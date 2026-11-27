<?php

namespace App\Services\Attendance;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicCalendar;
use App\Support\BanglaDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every report as the same shape: columns, rows, summary cards and the range. The screen, the print and the export all
 * read this, so they cannot disagree.
 */
class Reports
{
    /** type => group; the "period" group is offered only where period-wise attendance exists */
    public const TYPES = [
        'summary' => 'time',
        'students' => 'student', 'student' => 'student', 'low' => 'student', 'consecutive' => 'student', 'perfect' => 'student',
        'classes' => 'class', 'sections' => 'class',
        'status' => 'status',
        'subjects' => 'period', 'student_subjects' => 'period', 'class_subjects' => 'period', 'low_subjects' => 'period', 'periods' => 'period',
    ];

    public function __construct(private Calculator $calc, private Periods $periods, private AcademicCalendar $calendar)
    {
    }

    /** the classes of a year this person may report on, narrowed by the filters */
    public function classes(User $user, array $f, string $ability = 'attendance.reports'): Collection
    {
        $ids = Access::classIds($user, $ability);

        return SchoolClass::query()->where('academic_year', $f['year'])
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($f['class_name'] ?? null, fn ($q, $v) => $q->where('class_name', $v))
            ->when($f['class_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when($f['shift_id'] ?? null, fn ($q, $v) => $q->where('shift_id', $v))
            ->orderBy('class_name')->orderBy('section')->get();
    }

    public function filters(User $user, array $f, Collection $classes): array
    {
        $range = $this->periods->resolve($f['period'] ?? Settings::get('default_period'), $f['year'], $f['date'] ?? null, $f['from'] ?? null, $f['to'] ?? null);
        $limits = [];

        foreach ($classes as $c) {
            $limit = Access::subjectLimit($user, 'attendance.reports', $c->id);

            if ($limit !== null) {
                $limits[$c->id] = $limit;
            }
        }

        $query = $range + ['class_ids' => $classes->pluck('id')->all() ?: [0], 'subject_limits' => $limits];

        if (! empty($f['student_id'])) {
            $query['student_ids'] = [(int) $f['student_id']];
        } elseif (! empty($f['group_id'])) {
            $query['student_ids'] = Student::whereIn('class_id', $query['class_ids'])->where(fn ($q) => $q->where('study_group_id', $f['group_id'])
                ->orWhere(fn ($x) => $x->whereNull('study_group_id')->whereIn('class_id', $classes->where('study_group_id', (int) $f['group_id'])->pluck('id'))))->pluck('id')->all() ?: [0];
        }

        foreach (['subject_id', 'period_id'] as $k) {
            if (! empty($f[$k])) {
                $query[$k] = (int) $f[$k];
            }
        }

        return $query;
    }

    /**
     * @return array{type:string,title:string,columns:array,rows:array<int,array>,cards:array,range:array}
     */
    public function build(User $user, string $type, array $f): array
    {
        $type = isset(self::TYPES[$type]) ? $type : 'summary';
        $classes = $this->classes($user, $f);
        $q = $this->filters($user, $f, $classes);

        if (self::TYPES[$type] === 'period') {
            $q['mode'] = 'period';
        }

        [$columns, $rows] = $this->{'report' . str_replace('_', '', ucwords($type, '_'))}($q, $f, $classes);

        $totals = $this->calc->totals($q);
        $mixed = $this->calc->query($q)->distinct()->count('s.mode') > 1;
        $one = count($q['class_ids']) === 1 ? $q['class_ids'][0] : null;
        $upTo = min($q['to'], AcademicCalendar::today());
        $cards = [
            'working_days' => $q['from'] <= $upTo ? $this->calendar->countWorkingDays($q['from'], $upTo, $one) : 0,
            'sessions' => $this->calc->query($q)->distinct()->count('r.session_id'),
            'students' => $this->calc->query($q)->distinct()->count('r.student_id'),
        ] + $totals;

        return [
            'type' => $type, 'note' => $mixed ? __('attendance.mixed_note') : null, 'title' => __("attendance.report.{$type}"), 'columns' => $columns, 'rows' => array_values($rows), 'cards' => $cards,
            'range' => ['from' => $q['from'], 'to' => $q['to'], 'label' => $q['label'], 'text' => $this->date($q['from']) . ($q['to'] !== $q['from'] ? ' – ' . $this->date($q['to']) : '')],
        ];
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** a date as the settings (or the screen's choice) say: English, Bangla or both */
    public function date(string $date, ?string $mode = null): string
    {
        $mode ??= request()?->input('cal') ?: Settings::get('date_mode');
        $english = \Illuminate\Support\Carbon::parse($date)->format('j M Y');

        return match ($mode) {
            'bangla' => BanglaDate::format($date),
            'both' => "{$english} · " . BanglaDate::format($date),
            default => $english,
        };
    }

    private function cols(array $keys): array
    {
        $types = ['present' => 'num', 'absent' => 'num', 'late' => 'num', 'leave' => 'num', 'total' => 'num', 'sessions' => 'num', 'students' => 'num', 'longest' => 'num', 'current' => 'num', 'percent' => 'pct', 'status' => 'status', 'date' => 'date'];

        return array_map(fn ($k) => ['key' => $k, 'label' => __("attendance.col.{$k}"), 'type' => $types[$k] ?? 'text'], $keys);
    }

    private const COUNTS = ['present', 'absent', 'late', 'leave', 'total', 'percent'];

    /** @return array<int,array{roll:?string,name:string,code:?string,class:string,class_id:int}> */
    private function people(array $ids, Collection $classes): array
    {
        $labels = $classes->keyBy('id')->map(fn ($c) => $this->label($c));
        $out = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (Student::withTrashed()->whereIn('id', $chunk)->get() as $s) {
                $out[$s->id] = ['student_id' => $s->id, 'roll' => $s->roll_number, 'name' => $s->student_name, 'code' => $s->student_id,
                    'class' => $labels[$s->class_id] ?? '', 'class_id' => (int) $s->class_id];
            }
        }

        return $out;
    }

    public function label(object $c): string
    {
        $name = ($c->class_title ?? null) ?: $c->class_name;
        $section = ($c->section_title ?? null) ?: $c->section;

        $group = $c->group_name ?? null;

        return trim($name . ($section ? " ({$section})" : '') . ($group ? " · {$group}" : ''));
    }

    private function byRoll(array $rows): array
    {
        usort($rows, fn ($a, $b) => [$a['class'], (int) $a['roll'], $a['name']] <=> [$b['class'], (int) $b['roll'], $b['name']]);

        return $rows;
    }

    private function studentRows(array $q, Collection $classes, ?\Closure $keep = null): array
    {
        $stats = $this->calc->students($q);

        if ($keep) {
            $stats = array_filter($stats, $keep);
        }

        $people = $this->people(array_keys($stats), $classes);
        $rows = [];

        foreach ($stats as $id => $s) {
            if (isset($people[$id])) {
                $rows[] = $people[$id] + array_intersect_key($s, array_flip(self::COUNTS));
            }
        }

        return $this->byRoll($rows);
    }

    private function subjectNames(Collection $rows): array
    {
        return DB::table('subjects')->whereIn('id', $rows->pluck('subject_id')->filter()->unique())->pluck('name', 'id')->all();
    }

    // ------------------------------------------------------------------
    // the reports
    // ------------------------------------------------------------------

    private function reportSummary(array $q): array
    {
        $rows = $this->calc->grouped($q, ['r.date'])->sortBy('date')->map(function ($r) {
            $d = substr($r->date, 0, 10);

            return ['date' => $d, 'date_label' => $this->date($d)] + Calculator::figures($r);
        })->all();

        return [$this->cols(['date', ...self::COUNTS]), $rows];
    }

    private function reportStudents(array $q, array $f, Collection $classes): array
    {
        return [$this->cols(['roll', 'name', 'class', ...self::COUNTS]), $this->studentRows($q, $classes)];
    }

    private function reportStudent(array $q): array
    {
        if (empty($q['student_ids']) || count($q['student_ids']) !== 1) {
            return [$this->cols(['date', 'status', 'detail']), []];
        }

        $rows = array_map(fn ($d) => [
            'date' => $d['date'], 'date_label' => $this->date($d['date']), 'status' => $d['status'], 'percent' => $d['percent'] ?? null,
            'detail' => $d['mode'] === 'daily' ? (string) $d['remark'] : collect($d['periods'])->map(fn ($p) => "{$p['name']} {$p['subject']}: " . __("attendance.status.{$p['status']}"))->implode('; '),
        ], $this->calc->days($q));

        return [$this->cols(['date', 'status', 'detail']), $rows];
    }

    private function reportLow(array $q, array $f, Collection $classes): array
    {
        $limit = (float) Settings::get('low_threshold');
        $rows = $this->studentRows($q, $classes, fn ($s) => $s['percent'] !== null && $s['percent'] < $limit);
        usort($rows, fn ($a, $b) => $a['percent'] <=> $b['percent']);

        return [$this->cols(['roll', 'name', 'class', ...self::COUNTS]), $rows];
    }

    private function reportPerfect(array $q, array $f, Collection $classes): array
    {
        return [$this->cols(['roll', 'name', 'class', ...self::COUNTS]), $this->studentRows($q, $classes, fn ($s) => $s['total'] > 0 && $s['present'] + $s['late'] === $s['total'])];
    }

    private function reportConsecutive(array $q, array $f, Collection $classes): array
    {
        $min = (int) Settings::get('consecutive_days');
        $runs = array_filter($this->calc->absenceRuns($q), fn ($r) => $r['longest'] >= $min);
        $people = $this->people(array_keys($runs), $classes);
        $rows = [];

        foreach ($runs as $id => $r) {
            if (isset($people[$id])) {
                $rows[] = $people[$id] + ['longest' => $r['longest'], 'current' => $r['current'], 'span' => $this->date($r['from']) . ' – ' . $this->date($r['to'])];
            }
        }

        usort($rows, fn ($a, $b) => [$b['current'], $b['longest']] <=> [$a['current'], $a['longest']]);

        return [$this->cols(['roll', 'name', 'class', 'longest', 'span', 'current']), $rows];
    }

    private function classRows(array $q, Collection $classes, bool $byName): array
    {
        $stats = $this->calc->grouped($q, ['r.class_id'])->keyBy('class_id');
        $heads = $this->calc->query($q)->groupBy('r.class_id')->selectRaw('r.class_id, COUNT(DISTINCT r.student_id) n')->pluck('n', 'class_id');
        $rows = [];

        foreach ($classes->groupBy(fn ($c) => $byName ? (($c->class_title ?? null) ?: $c->class_name) : $this->label($c)) as $label => $group) {
            $sum = (object) ['present' => 0, 'absent' => 0, 'late' => 0, 'on_leave' => 0, 'total' => 0];
            $students = 0;

            foreach ($group as $c) {
                if ($r = $stats[$c->id] ?? null) {
                    foreach (array_keys((array) $sum) as $k) {
                        $sum->$k += $r->$k;
                    }

                    $students += $heads[$c->id] ?? 0;
                }
            }

            if ($sum->total) {
                $rows[] = ['class' => (string) $label, 'students' => $students] + Calculator::figures($sum);
            }
        }

        usort($rows, fn ($a, $b) => $b['percent'] <=> $a['percent']);

        return [$this->cols(['class', 'students', ...self::COUNTS]), $rows];
    }

    private function reportClasses(array $q, array $f, Collection $classes): array
    {
        return $this->classRows($q, $classes, true);
    }

    private function reportSections(array $q, array $f, Collection $classes): array
    {
        return $this->classRows($q, $classes, false);
    }

    private function reportStatus(array $q, array $f, Collection $classes): array
    {
        $status = in_array($f['status'] ?? null, Settings::STATUSES, true) ? $f['status'] : 'absent';
        $records = $this->calc->query($q)->where('r.status', $status)->orderByDesc('r.date')->orderBy('r.class_id')->limit(20000)
            ->get(['r.student_id', 'r.date', 'r.status', 'r.remark', 's.period_name', 's.subject_name']);
        $people = $this->people($records->pluck('student_id')->unique()->values()->all(), $classes);
        $rows = [];

        foreach ($records as $r) {
            if (isset($people[$r->student_id])) {
                $d = substr($r->date, 0, 10);
                $rows[] = ['date' => $d, 'date_label' => $this->date($d)] + $people[$r->student_id]
                    + ['status' => $r->status, 'period' => $r->period_name, 'subject' => $r->subject_name, 'remark' => $r->remark];
            }
        }

        return [$this->cols(['date', 'roll', 'name', 'class', 'status', 'period', 'subject', 'remark']), $rows];
    }

    private function reportSubjects(array $q): array
    {
        $found = $this->calc->query($q)->whereNotNull('s.subject_id')->groupBy('s.subject_id')
            ->selectRaw("s.subject_id, COUNT(DISTINCT s.id) sessions, SUM(r.status = 'present') present, SUM(r.status = 'absent') absent, SUM(r.status = 'late') late, SUM(r.status = 'leave') on_leave, COUNT(*) total")->get();
        $names = $this->subjectNames($found);
        $rows = $found->map(fn ($r) => ['subject' => $names[$r->subject_id] ?? '?', 'sessions' => (int) $r->sessions] + Calculator::figures($r))->sortByDesc('percent')->all();

        return [$this->cols(['subject', 'sessions', ...self::COUNTS]), $rows];
    }

    private function studentSubjectRows(array $q, Collection $classes, ?float $below = null): array
    {
        $found = $this->calc->grouped($q, ['r.student_id', 's.subject_id'])->whereNotNull('subject_id');
        $names = $this->subjectNames($found);
        $people = $this->people($found->pluck('student_id')->unique()->values()->all(), $classes);
        $rows = [];

        foreach ($found as $r) {
            $figures = Calculator::figures($r);

            if (isset($people[$r->student_id]) && ($below === null || $figures['percent'] < $below)) {
                $rows[] = $people[$r->student_id] + ['subject' => $names[$r->subject_id] ?? '?'] + $figures;
            }
        }

        return $this->byRoll($rows);
    }

    private function reportStudentSubjects(array $q, array $f, Collection $classes): array
    {
        return [$this->cols(['roll', 'name', 'class', 'subject', ...self::COUNTS]), $this->studentSubjectRows($q, $classes)];
    }

    private function reportLowSubjects(array $q, array $f, Collection $classes): array
    {
        $rows = $this->studentSubjectRows($q, $classes, (float) Settings::get('low_threshold'));
        usort($rows, fn ($a, $b) => $a['percent'] <=> $b['percent']);

        return [$this->cols(['roll', 'name', 'class', 'subject', ...self::COUNTS]), $rows];
    }

    private function reportClassSubjects(array $q, array $f, Collection $classes): array
    {
        $found = $this->calc->grouped($q, ['r.class_id', 's.subject_id'])->whereNotNull('subject_id');
        $names = $this->subjectNames($found);
        $labels = $classes->keyBy('id')->map(fn ($c) => $this->label($c));
        $rows = $found->map(fn ($r) => ['class' => $labels[$r->class_id] ?? '?', 'subject' => $names[$r->subject_id] ?? '?'] + Calculator::figures($r))
            ->sortBy([['class', 'asc'], ['subject', 'asc']])->all();

        return [$this->cols(['class', 'subject', ...self::COUNTS]), $rows];
    }

    private function reportPeriods(array $q): array
    {
        $rows = $this->calc->grouped($q, ['s.period_name', 's.starts_at'])->sortBy('starts_at')
            ->map(fn ($r) => ['period' => $r->period_name, 'time' => $r->starts_at] + Calculator::figures($r))->all();

        return [$this->cols(['period', 'time', ...self::COUNTS]), $rows];
    }
}
