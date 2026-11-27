<?php

namespace App\Services;

use App\Jobs\ScheduleTeachingPlans;
use App\Models\AuditLog;
use App\Models\BackgroundTask;
use App\Models\CalendarEvent;
use App\Models\Exam;
use App\Models\User;
use App\Support\BanglaDate;
use App\Support\Normalize;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Every change to the Academic Calendar: one event, many at once, a copied year, a confirmed date, a closure. */
class CalendarEditor
{
    public const MAX_BULK = 500;

    public function __construct(private AcademicCalendar $calendar)
    {
    }

    private function fail(string $message, string $field = 'calendar'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** check the rules that need more than one field, and fill what follows from the type */
    private function prepare(array $d): array
    {
        $type = $d['type'];
        $d['ends_on'] = $d['ends_on'] ?? null ?: $d['starts_on'];
        $d['audience'] = $d['audience'] ?? 'all';
        $d['repeat'] = $d['repeat'] ?? 'none';

        if ($d['ends_on'] < $d['starts_on']) {
            $this->fail('The end date is before the start date.', 'ends_on');
        }

        if (Carbon::parse($d['starts_on'])->diffInDays($d['ends_on']) > 366) {
            $this->fail('An event can be at most a year long.', 'ends_on');
        }

        if ($d['audience'] === 'classes' && empty($d['class_ids'])) {
            $this->fail('Choose at least one class.', 'class_ids');
        }

        if ($d['repeat'] !== 'none') {
            if ($d['audience'] === 'classes') {
                $this->fail('A yearly event cannot be for chosen classes, because classes change every year. Copy the year instead.', 'repeat');
            }

            if (in_array($type, ['closure', 'makeup', 'exam'], true)) {
                $this->fail('This kind of event does not repeat every year.', 'repeat');
            }
        }

        $d['off'] = in_array($type, CalendarEvent::OPTIONAL_OFF, true) ? (bool) ($d['off'] ?? false) : CalendarEvent::TYPES[$type][1];
        // teachers-only events never close classes
        $d['audience'] === 'teachers' && $d['off'] = false;
        $d['follows_day'] = $type === 'makeup' ? ($d['follows_day'] ?? null) : null;
        $d['exam_id'] = $type === 'exam' ? ($d['exam_id'] ?? null) : null;

        if ($type === 'makeup' && $d['follows_day'] === null && ! in_array(Carbon::parse($d['starts_on'])->dayOfWeek, $this->calendar->weekdays(), true)) {
            $this->fail('Say which weekday’s routine this make-up day follows.', 'follows_day');
        }

        return $d;
    }

    public function save(array $d, User $by, ?CalendarEvent $event = null): CalendarEvent
    {
        $d = $this->prepare($d);

        if ($d['exam_id'] && CalendarEvent::where('exam_id', $d['exam_id'])->when($event, fn ($q) => $q->where('id', '!=', $event->id))->exists()) {
            // the exam already has its own entry: that entry is what changes
            $event ??= CalendarEvent::where('exam_id', $d['exam_id'])->first();
            $event->exam_id === (int) $d['exam_id'] || $this->fail('That exam already has dates in the calendar.', 'exam_id');
        }

        return DB::transaction(function () use ($d, $by, $event) {
            $classes = $d['audience'] === 'classes' ? array_map('intval', $d['class_ids']) : [];
            $data = collect($d)->only((new CalendarEvent())->getFillable())->all();
            $before = $event?->only(['title', 'starts_on', 'ends_on', 'type', 'audience', 'tentative']);

            if ($event) {
                $event->update($data);
            } else {
                $event = CalendarEvent::create($data + ['created_by' => $by->id]);
            }

            $event->setClasses($classes);
            $this->syncExam($event);
            AuditLog::record($event, $before ? 'cal.updated' : 'cal.created', array_filter(['before' => $before, 'after' => $event->only(['title', 'starts_on', 'ends_on', 'type', 'audience', 'tentative'])]));

            return $event;
        });
    }

    /** an exam entry linked to an exam writes its dates back to the exam */
    private function syncExam(CalendarEvent $event): void
    {
        if ($event->exam_id) {
            Exam::withoutEvents(fn () => Exam::whereKey($event->exam_id)->update(['starts_on' => $event->starts_on->toDateString(), 'ends_on' => $event->ends_on->toDateString()]));
        }
    }

    /**
     * Change only one year of a repeating event: that year is skipped and a single event takes its place.
     */
    public function saveOccurrence(CalendarEvent $series, int $year, array $d, User $by): CalendarEvent
    {
        return DB::transaction(function () use ($series, $year, $d, $by) {
            $this->skipYear($series, $year, $by);

            return $this->save(['repeat' => 'none'] + $d, $by);
        });
    }

    private function skipYear(CalendarEvent $series, int $year, User $by): void
    {
        if ($series->repeat === 'none') {
            $this->fail('This event does not repeat.');
        }

        $series->update(['skip_years' => array_values(array_unique([...($series->skip_years ?? []), $year]))]);
        AuditLog::record($series, 'cal.skip_year', ['year' => $year]);
    }

    /** delete the whole event, or only one year of a repeating one */
    public function delete(CalendarEvent $event, User $by, ?int $year = null): void
    {
        DB::transaction(function () use ($event, $by, $year) {
            if ($year !== null && $event->repeat !== 'none') {
                $this->skipYear($event, $year, $by);

                return;
            }

            // removing an exam's entry removes the exam's dates, not the exam
            $event->exam_id && Exam::withoutEvents(fn () => Exam::whereKey($event->exam_id)->update(['starts_on' => null, 'ends_on' => null]));
            AuditLog::record($event, 'cal.deleted', $event->only(['title', 'starts_on', 'ends_on', 'type']));
            $event->delete();
        });
    }

    /** drag and drop: the event (or one year of it) starts on another day and keeps its length */
    public function move(CalendarEvent $event, string $newStart, User $by, ?string $occurrence = null): CalendarEvent
    {
        $from = $occurrence ?: $event->starts_on->toDateString();
        $length = (int) $event->starts_on->diffInDays($event->ends_on);
        $data = ['type' => $event->type, 'title' => $event->title, 'title_bn' => $event->title_bn, 'starts_on' => $newStart, 'ends_on' => Carbon::parse($newStart)->addDays($length)->toDateString(),
            'audience' => $event->audience, 'class_ids' => $event->classIds(), 'note' => $event->note, 'off' => $event->off, 'tentative' => $event->tentative, 'repeat' => $event->repeat,
            'follows_day' => $event->follows_day, 'exam_id' => $event->exam_id];

        return $event->repeat !== 'none'
            ? $this->saveOccurrence($event, (int) substr($from, 0, 4), $data, $by)
            : $this->save($data, $by, $event);
    }

    /** a moon-dependent date is settled: confirmed as it is, or moved */
    public function confirm(CalendarEvent $event, User $by, ?string $newStart = null, ?string $newEnd = null): CalendarEvent
    {
        return DB::transaction(function () use ($event, $by, $newStart, $newEnd) {
            $length = (int) $event->starts_on->diffInDays($event->ends_on);
            $start = $newStart ?: $event->starts_on->toDateString();
            $end = $newEnd ?: Carbon::parse($start)->addDays($length)->toDateString();

            if ($end < $start) {
                $this->fail('The end date is before the start date.', 'ends_on');
            }

            $before = [$event->starts_on->toDateString(), $event->ends_on->toDateString()];
            $event->update(['starts_on' => $start, 'ends_on' => $end, 'tentative' => false]);
            $this->syncExam($event);
            AuditLog::record($event, 'cal.confirmed', ['before' => $before, 'after' => [$start, $end]]);

            return $event;
        });
    }

    // ------------------------------------------------------------------
    // many at once
    // ------------------------------------------------------------------

    /** a date as people type it: 2026-03-26, 26/03/2026, 12 চৈত্র 1432, 1432-12-12 BS */
    public function readDate(string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return BanglaDate::parse($text) ?? Normalize::parseDate($text) ?? $this->excelDate($text);
    }

    private function excelDate(string $text): ?string
    {
        // a date cell of a spreadsheet arrives as a day number
        return preg_match('/^[3-7]\d{4}$/', $text) ? Carbon::create(1899, 12, 30)->addDays((int) $text)->toDateString() : null;
    }

    private function readType(string $text): ?string
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return 'holiday';
        }

        foreach (CalendarEvent::TYPES as $key => [$label]) {
            if ($text === $key || $text === mb_strtolower($label)) {
                return $key;
            }
        }

        return ['parent' => 'meeting', 'result' => 'result', 'fee' => 'fee_due', 'fee due' => 'fee_due', 'school event' => 'event', 'semester' => 'term'][$text] ?? null;
    }

    /**
     * Pasted lines "date | title | type | bangla title" ("date to date" for a range).
     *
     * @return array<int,array<int,string>> rows as [date, end, title, type, title_bn]
     */
    public function linesToRows(string $text): array
    {
        $rows = [];

        foreach (preg_split('/\R/u', $text) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }

            $parts = array_map('trim', preg_split('/\s*[|\t]\s*/u', trim($line)));
            [$date, $end] = array_pad(preg_split('/\s+(?:to|থেকে|–|—)\s+/u', $parts[0], 2), 2, '');
            $rows[$n + 1] = [$date, $end, $parts[1] ?? '', $parts[2] ?? '', $parts[3] ?? ''];
        }

        return $rows;
    }

    /**
     * Check every row; nothing is saved.
     *
     * @param  array<int,array<int,string>>  $rows  row number => [date, end, title, type, title_bn]
     * @return array<int,array{row:int,error:?string,duplicate:bool,data:?array,text:string}>
     */
    public function check(array $rows, bool $tentative = false): array
    {
        if (count($rows) > self::MAX_BULK) {
            $this->fail('Add at most ' . self::MAX_BULK . ' dates at a time.', 'text');
        }

        $out = [];
        $seen = [];

        foreach ($rows as $n => $r) {
            [$date, $end, $title, $type, $bn] = array_pad(array_map(fn ($v) => trim((string) $v), array_values($r)), 5, '');
            $start = $this->readDate($date);
            $stop = $end === '' ? $start : $this->readDate($end);
            $kind = $this->readType($type);

            $error = match (true) {
                $start === null => "The date \"{$date}\" is not understood",
                $stop === null => "The end date \"{$end}\" is not understood",
                $stop < $start => 'The end date is before the start date',
                Carbon::parse($start)->diffInDays($stop) > 366 => 'Longer than a year',
                $title === '' => 'The title is empty',
                mb_strlen($title) > 150 || mb_strlen($bn) > 150 => 'The title is longer than 150 letters',
                $kind === null => "Unknown type \"{$type}\"",
                in_array($kind, ['closure', 'makeup'], true) => 'Use Emergency closure for closures and make-up days',
                Normalize::looksLikeFormula($title) || Normalize::looksLikeFormula($bn) => 'A title starts like a formula (=, +, @)',
                default => null,
            };

            $data = $error ? null : ['type' => $kind, 'title' => $title, 'title_bn' => $bn ?: null, 'starts_on' => $start, 'ends_on' => $stop, 'tentative' => $tentative, 'audience' => 'all'];
            $key = $data ? "{$kind}|{$start}|" . mb_strtolower($title) : null;
            // the same entry with another end date is not "already there": adding it again corrects the calendar
            $old = $data && ! isset($seen[$key]) ? $this->differing($data)->first()?->ends_on->toDateString() : null;
            $duplicate = $data && ! $old && (isset($seen[$key]) || $this->exists($data));
            $key && $seen[$key] = true;

            $out[] = ['row' => (int) $n, 'error' => $error, 'duplicate' => $duplicate, 'old_end' => $old, 'data' => $data, 'text' => trim("{$date} {$title}")];
        }

        return $out;
    }

    private function exists(array $d): bool
    {
        return CalendarEvent::where('type', $d['type'])->where('starts_on', $d['starts_on'])->where('title', $d['title'])->exists();
    }

    /** school-wide single entries with this type, start date and title, but another end date */
    private function differing(array $d): \Illuminate\Support\Collection
    {
        return CalendarEvent::where('type', $d['type'])->where('starts_on', $d['starts_on'])->where('title', $d['title'])
            ->where('audience', 'all')->where('repeat', 'none')->where('ends_on', '!=', $d['ends_on'])->get();
    }

    /**
     * Save the rows that are not already there; returns how many were added. With $updated given, an entry that is there
     * with another end date takes the new one, and the number of entries corrected is put into it.
     */
    public function addMany(array $events, User $by, ?int &$updated = null): int
    {
        $correct = func_num_args() > 2;
        $updated = 0;

        return DB::transaction(function () use ($events, $by, $correct, &$updated) {
            $n = 0;

            foreach ($events as $e) {
                $wrong = $correct ? $this->differing($e) : collect();

                foreach ($wrong as $event) {
                    $before = $event->ends_on->toDateString();
                    $event->update(['ends_on' => $e['ends_on']]);
                    $this->syncExam($event);
                    AuditLog::record($event, 'cal.corrected', ['ends_on' => ['from' => $before, 'to' => $e['ends_on']]], $by->id);
                    $updated++;
                }

                if ($wrong->isEmpty() && ! $this->exists($e)) {
                    $this->save($e, $by);
                    $n++;
                }
            }

            return $n;
        });
    }

    /**
     * Last year's single events, a year later. Yearly repeating events need no copy. Closures and make-up days belong
     * to their own year; exam entries come with the exams.
     *
     * @return array<int,array>
     */
    public function copyCandidates(int $fromYear, array $types = []): array
    {
        $shift = fn (string $d) => Carbon::parse($d)->addYearNoOverflow()->toDateString();

        return CalendarEvent::where('repeat', 'none')->whereNull('exam_id')->whereNotIn('type', ['closure', 'makeup'])
            ->whereBetween('starts_on', ["{$fromYear}-01-01", "{$fromYear}-12-31"])->when($types, fn ($q) => $q->whereIn('type', $types))
            ->orderBy('starts_on')->limit(self::MAX_BULK)->get()
            ->map(function (CalendarEvent $e) use ($shift) {
                $start = $shift($e->starts_on->toDateString());

                // class-only events are not copied to classes of another year
                $data = ['type' => $e->type, 'title' => $e->title, 'title_bn' => $e->title_bn, 'starts_on' => $start, 'ends_on' => $shift($e->ends_on->toDateString()),
                    'audience' => $e->audience === 'classes' ? null : $e->audience, 'off' => $e->off, 'note' => $e->note];

                return ['id' => $e->id, 'from' => $e->starts_on->toDateString(), 'data' => $data,
                    'skip' => $data['audience'] === null ? 'For chosen classes: add it again for the new classes' : ($this->exists($data) ? 'Already in the new year' : null)];
            })->all();
    }

    public function copyYear(int $fromYear, array $ids, bool $tentative, User $by): int
    {
        $chosen = array_flip(array_map('intval', $ids));
        $events = collect($this->copyCandidates($fromYear))->filter(fn ($c) => ! $c['skip'] && isset($chosen[$c['id']]))
            ->map(fn ($c) => ['tentative' => $tentative] + $c['data'])->all();

        return $this->addMany($events, $by);
    }

    // ------------------------------------------------------------------
    // emergency closure
    // ------------------------------------------------------------------

    /**
     * Close the school or some classes, optionally with a make-up day. Routine, Attendance and Teaching Plan read the
     * calendar, so they follow at once; when asked, unfinished chapters of the affected plans are spread again.
     *
     * @return array{closure:CalendarEvent, makeup:?CalendarEvent, plans:int, task:?int}
     */
    public function close(array $d, User $by): array
    {
        $classes = $d['audience'] === 'classes' ? array_map('intval', $d['class_ids'] ?? []) : [];

        $result = DB::transaction(function () use ($d, $by, $classes) {
            $closure = $this->save(['type' => 'closure', 'title' => $d['reason'], 'title_bn' => $d['reason_bn'] ?? null, 'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'] ?? null,
                'audience' => $d['audience'], 'class_ids' => $classes, 'note' => $d['note'] ?? null], $by);
            $makeup = null;

            if (! empty($d['makeup_on'])) {
                if ($d['makeup_on'] >= $closure->starts_on->toDateString() && $d['makeup_on'] <= $closure->ends_on->toDateString()) {
                    $this->fail('The make-up day falls inside the closure.', 'makeup_on');
                }

                // unless told otherwise it follows the routine of the first day that was lost
                $follows = $d['follows_day'] ?? $closure->starts_on->dayOfWeek;
                $makeup = $this->save(['type' => 'makeup', 'title' => 'Make-up day: ' . mb_substr($d['reason'], 0, 120), 'starts_on' => $d['makeup_on'], 'audience' => $d['audience'],
                    'class_ids' => $classes, 'follows_day' => (int) $follows], $by);
            }

            AuditLog::record($closure, 'cal.closure', ['reason' => $d['reason'], 'classes' => $classes, 'makeup' => $d['makeup_on'] ?? null]);

            return compact('closure', 'makeup');
        });

        $plans = DB::table('teaching_plans')->where('academic_year', substr($result['closure']->starts_on->toDateString(), 0, 4))
            ->when($classes, fn ($q) => $q->whereIn('class_id', $classes))->pluck('id')->all();
        $task = null;

        if (! empty($d['reschedule']) && $plans) {
            $task = BackgroundTask::queue('teaching_schedule', $by, ['plans' => $plans, 'from' => max(AcademicCalendar::today(), $result['closure']->starts_on->toDateString())], count($plans));
            ScheduleTeachingPlans::dispatch($task->id);
        }

        return $result + ['plans' => count($plans), 'task' => $task?->id];
    }
}
