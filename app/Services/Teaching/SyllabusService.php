<?php

namespace App\Services\Teaching;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teaching\SyllabusChapter;
use App\Models\Teaching\SyllabusTopic;
use App\Support\Normalize;
use App\Support\SheetStream;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The syllabus: class level → subject → chapters → topics, kept per session and reused by every teacher.
 * A session with no syllabus of its own uses the latest earlier one.
 */
class SyllabusService
{
    public const IMPORT_COLUMNS = ['Class', 'Subject', 'Chapter', 'Topic', 'Periods'];

    public const MAX_IMPORT_ROWS = 20000;

    /** the session whose syllabus applies: this one if it has chapters, else the latest earlier one, else null */
    public function effectiveYear(string $year, string $className, int $subjectId): ?string
    {
        return SyllabusChapter::where('class_name', $className)->where('subject_id', $subjectId)->where('academic_year', '<=', $year)
            ->max('academic_year');
    }

    /** @return Collection<int,SyllabusChapter> with topics, in order */
    public function chapters(string $year, string $className, int $subjectId): Collection
    {
        return SyllabusChapter::with('topics')->where('academic_year', $year)->where('class_name', $className)->where('subject_id', $subjectId)
            ->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Add chapters from typed or pasted lines. A line starting with "-" is a topic of the chapter above it;
     * "Name | 8" gives the chapter 8 suggested periods.
     *
     * @return int chapters and topics added
     */
    public function addLines(string $year, string $className, int $subjectId, string $text): int
    {
        $lines = array_values(array_filter(array_map('rtrim', preg_split('/\R/u', $text)), fn ($l) => trim($l) !== ''));

        if (count($lines) > 500) {
            throw ValidationException::withMessages(['text' => 'Add at most 500 lines at a time.']);
        }

        return DB::transaction(function () use ($year, $className, $subjectId, $lines) {
            $position = (int) SyllabusChapter::where(['academic_year' => $year, 'class_name' => $className, 'subject_id' => $subjectId])->max('position');
            $chapter = null;
            $added = 0;

            foreach ($lines as $line) {
                $line = trim($line);

                if (preg_match('/^[-•*]\s*(.+)$/u', $line, $m)) {
                    $chapter ??= SyllabusChapter::where(['academic_year' => $year, 'class_name' => $className, 'subject_id' => $subjectId])->orderByDesc('position')->orderByDesc('id')->first();

                    if (! $chapter) {
                        throw ValidationException::withMessages(['text' => 'The first line must be a chapter, not a topic.']);
                    }

                    SyllabusTopic::create(['chapter_id' => $chapter->id, 'name' => mb_substr(trim($m[1]), 0, 200), 'position' => (int) $chapter->topics()->max('position') + 1]);
                    $added++;
                    continue;
                }

                $periods = 0;

                if (preg_match('/^(.*?)\s*\|\s*(\d{1,3})$/u', $line, $m)) {
                    [$line, $periods] = [$m[1], (int) $m[2]];
                }

                $chapter = SyllabusChapter::create(['academic_year' => $year, 'class_name' => $className, 'subject_id' => $subjectId,
                    'name' => mb_substr($line, 0, 200), 'periods' => $periods, 'position' => ++$position]);
                AuditLog::record($chapter, 'syllabus.created', ['name' => $chapter->name]);
                $added++;
            }

            return $added;
        });
    }

    public function updateChapter(SyllabusChapter $chapter, array $data): void
    {
        $chapter->fill($data);

        if ($chapter->isDirty()) {
            $changes = [];

            foreach ($chapter->getDirty() as $k => $v) {
                $changes[$k] = ['from' => $chapter->getOriginal($k), 'to' => $v];
            }

            DB::transaction(function () use ($chapter, $changes) {
                $chapter->save();
                AuditLog::record($chapter, 'syllabus.updated', $changes);
            });
        }
    }

    /** soft delete: plans and logs that used it keep their history, and it can be brought back */
    public function deleteChapter(SyllabusChapter $chapter): void
    {
        DB::transaction(function () use ($chapter) {
            $chapter->delete();
            AuditLog::record($chapter, 'syllabus.deleted', ['name' => $chapter->name]);
        });
    }

    public function restoreChapter(SyllabusChapter $chapter): void
    {
        DB::transaction(function () use ($chapter) {
            $chapter->restore();
            AuditLog::record($chapter, 'syllabus.restored', ['name' => $chapter->name]);
        });
    }

    /** put the chapters of one set in the given order */
    public function reorderChapters(string $year, string $className, int $subjectId, array $ids): void
    {
        DB::transaction(function () use ($year, $className, $subjectId, $ids) {
            $mine = SyllabusChapter::where(['academic_year' => $year, 'class_name' => $className, 'subject_id' => $subjectId])->pluck('position', 'id');
            $n = 0;
            $first = null;

            foreach ($ids as $id) {
                if (isset($mine[$id])) {
                    SyllabusChapter::whereKey($id)->update(['position' => ++$n]);
                    $first ??= $id;
                }
            }

            $first && AuditLog::record(SyllabusChapter::find($first), 'syllabus.order', ['order' => array_values($ids)]);
        });
    }

    public function reorderTopics(SyllabusChapter $chapter, array $ids): void
    {
        DB::transaction(function () use ($chapter, $ids) {
            $mine = SyllabusTopic::where('chapter_id', $chapter->id)->pluck('position', 'id');
            $n = 0;

            foreach ($ids as $id) {
                isset($mine[$id]) && SyllabusTopic::whereKey($id)->update(['position' => ++$n]);
            }

            AuditLog::record($chapter, 'syllabus.topic_order', ['order' => array_values($ids)]);
        });
    }

    /**
     * Copy a syllabus to other classes and/or another session. Chapters already there (same name) are left alone.
     *
     * @param  array<int,string>  $toClasses
     * @return int chapters copied
     */
    public function copy(string $fromYear, string $fromClass, int $subjectId, string $toYear, array $toClasses): int
    {
        $source = $this->chapters($fromYear, $fromClass, $subjectId);

        if ($source->isEmpty()) {
            throw ValidationException::withMessages(['copy' => 'There is nothing to copy.']);
        }

        return DB::transaction(function () use ($source, $fromYear, $fromClass, $subjectId, $toYear, $toClasses) {
            $copied = 0;

            foreach (array_unique($toClasses) as $to) {
                if ($to === $fromClass && $toYear === $fromYear) {
                    continue;
                }

                $existing = $this->chapters($toYear, $to, $subjectId)->mapWithKeys(fn ($c) => [mb_strtolower($c->name) => true]);
                $position = (int) SyllabusChapter::where(['academic_year' => $toYear, 'class_name' => $to, 'subject_id' => $subjectId])->max('position');

                foreach ($source as $c) {
                    if (isset($existing[mb_strtolower($c->name)])) {
                        continue;
                    }

                    $new = SyllabusChapter::create(['academic_year' => $toYear, 'class_name' => $to, 'subject_id' => $subjectId, 'name' => $c->name, 'periods' => $c->periods, 'position' => ++$position]);
                    $now = now();
                    SyllabusTopic::insert($c->topics->values()->map(fn ($t, $i) => ['chapter_id' => $new->id, 'name' => $t->name, 'position' => $i + 1, 'created_at' => $now, 'updated_at' => $now])->all());
                    AuditLog::record($new, 'syllabus.copied', ['from' => "{$fromClass} {$fromYear}"]);
                    $copied++;
                }
            }

            return $copied;
        });
    }

    public function template(): array
    {
        $class = SchoolClass::orderByDesc('academic_year')->value('class_name') ?: 'Class 6';
        $subject = Subject::active()->ordered()->value('name') ?: 'Mathematics';

        return [self::IMPORT_COLUMNS,
            [$class, $subject, 'Chapter 1: Numbers', 'Natural numbers', '8'],
            [$class, $subject, 'Chapter 1: Numbers', 'Fractions', ''],
            [$class, $subject, 'Chapter 2: Algebra', '', '10']];
    }

    /**
     * Read an import file and check every row; nothing is saved.
     *
     * @return array{rows:array, errors:array<int,array{row:int,reason:string}>, total:int}
     */
    public function parse(string $path): array
    {
        $subjects = Subject::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $classes = SchoolClass::query()->distinct()->pluck('class_name')->mapWithKeys(fn ($n) => [mb_strtolower($n) => $n]);
        $map = null;
        $rows = [];
        $errors = [];
        $total = 0;

        foreach (SheetStream::rows($path) as $n => $cells) {
            $cells = array_values($cells);

            if ($map === null) {
                $head = array_map(fn ($h) => mb_strtolower(trim($h)), $cells);
                $map = [];

                foreach (self::IMPORT_COLUMNS as $col) {
                    $i = array_search(mb_strtolower($col), $head, true);
                    $i !== false && $map[$col] = $i;
                }

                if (! isset($map['Class'], $map['Subject'], $map['Chapter'])) {
                    throw ValidationException::withMessages(['file' => 'The first row must have the headings Class, Subject and Chapter (Topic and Periods are optional).']);
                }

                continue;
            }

            if (++$total > self::MAX_IMPORT_ROWS) {
                throw ValidationException::withMessages(['file' => 'Import at most ' . number_format(self::MAX_IMPORT_ROWS) . ' rows at a time.']);
            }

            $get = fn (string $c) => isset($map[$c]) ? trim((string) preg_replace('/\s+/u', ' ', (string) ($cells[$map[$c]] ?? ''))) : '';
            [$class, $subject, $chapter, $topic, $periods] = [$get('Class'), $get('Subject'), $get('Chapter'), $get('Topic'), $get('Periods')];

            $reason = match (true) {
                $class === '' || ! isset($classes[mb_strtolower($class)]) => "Unknown class \"{$class}\"",
                $subject === '' || ! isset($subjects[mb_strtolower($subject)]) => "Unknown subject \"{$subject}\"",
                $chapter === '' => 'The chapter is empty',
                mb_strlen($chapter) > 200 || mb_strlen($topic) > 200 => 'A name is longer than 200 letters',
                $periods !== '' && (! ctype_digit($periods) || (int) $periods > 500) => 'Periods must be a number up to 500',
                // a cell that a spreadsheet would run as a formula is never stored
                Normalize::looksLikeFormula($chapter) || Normalize::looksLikeFormula($topic) => 'A name starts like a formula (=, +, @)',
                default => null,
            };

            if ($reason) {
                count($errors) < 200 && $errors[] = ['row' => $n, 'reason' => $reason];
                continue;
            }

            $rows[] = ['class' => $classes[mb_strtolower($class)], 'subject_id' => $subjects[mb_strtolower($subject)], 'subject' => $subject, 'chapter' => $chapter, 'topic' => $topic, 'periods' => (int) $periods];
        }

        return ['rows' => $rows, 'errors' => $errors, 'total' => $total];
    }

    /** @return array{chapters:int,topics:int} what was new; existing names are reused, never duplicated */
    public function import(string $year, array $rows, ?callable $tick = null): array
    {
        return DB::transaction(function () use ($year, $rows, $tick) {
            $new = ['chapters' => 0, 'topics' => 0];
            $sets = [];

            foreach ($rows as $r) {
                $key = $r['class'] . '|' . $r['subject_id'];
                $sets[$key] ??= $this->chapters($year, $r['class'], $r['subject_id'])->mapWithKeys(fn ($c) => [mb_strtolower($c->name) => ['model' => $c, 'topics' => $c->topics->mapWithKeys(fn ($t) => [mb_strtolower($t->name) => true])->all()]])->all();
                $ck = mb_strtolower($r['chapter']);

                if (! isset($sets[$key][$ck])) {
                    $c = SyllabusChapter::create(['academic_year' => $year, 'class_name' => $r['class'], 'subject_id' => $r['subject_id'], 'name' => $r['chapter'], 'periods' => $r['periods'], 'position' => count($sets[$key]) + 1]);
                    AuditLog::record($c, 'syllabus.imported', ['name' => $c->name]);
                    $sets[$key][$ck] = ['model' => $c, 'topics' => []];
                    $new['chapters']++;
                }

                $tk = mb_strtolower($r['topic']);

                if ($tk !== '' && ! isset($sets[$key][$ck]['topics'][$tk])) {
                    SyllabusTopic::create(['chapter_id' => $sets[$key][$ck]['model']->id, 'name' => $r['topic'], 'position' => count($sets[$key][$ck]['topics']) + 1]);
                    $sets[$key][$ck]['topics'][$tk] = true;
                    $new['topics']++;
                }

                $tick && $tick();
            }

            return $new;
        });
    }
}
