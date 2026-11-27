<?php

namespace App\Services\Attendance;

use App\Services\AcademicCalendar;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place attendance is counted (screens, reports, register, marksheets).
 *
 *   daily        % = (present days + late days) / the student's recorded working days
 *   period-wise  % = (present + late periods) / the student's conducted periods
 *   both in one range (the class changed mode): each date counts once, a period date as its attended share
 *
 * Only submitted attendance exists, so a day or period not taken is never counted. Sessions on holidays and closures
 * (declared before or after) and sessions recorded on a day that was closed then, are left out. Leave is never present.
 *
 * Filters: from, to, class_ids (null = all), student_ids, subject_id, subject_limits [class => subject ids],
 * period_id, mode.
 */
class Calculator
{
    public function __construct(private AcademicCalendar $calendar)
    {
    }

    /** @return array<int,int> sessions that must not be counted */
    public function excluded(array $f): array
    {
        $rows = DB::table('attendance_sessions')->whereBetween('date', [$f['from'], $f['to']])
            ->when(isset($f['class_ids']), fn ($q) => $q->whereIn('class_id', $f['class_ids']))->get(['id', 'class_id', 'date', 'day_type']);
        $out = [];

        foreach ($rows->groupBy('class_id') as $classId => $mine) {
            $dates = $mine->map(fn ($r) => substr($r->date, 0, 10));
            $closed = array_flip($this->calendar->holidayDays($dates->min(), $dates->max(), (int) $classId));

            foreach ($mine as $r) {
                if (! in_array($r->day_type, ['working', 'makeup'], true) || isset($closed[substr($r->date, 0, 10)])) {
                    $out[] = $r->id;
                }
            }
        }

        return $out;
    }

    /** the countable records of a range, joined with their sessions (r, s) */
    public function query(array $f): Builder
    {
        $excluded = $this->excluded($f);

        return DB::table('attendance_records as r')->join('attendance_sessions as s', 's.id', '=', 'r.session_id')
            ->whereBetween('r.date', [$f['from'], $f['to']])
            ->when(isset($f['class_ids']), fn ($q) => $q->whereIn('r.class_id', $f['class_ids']))
            ->when(isset($f['student_ids']), fn ($q) => $q->whereIn('r.student_id', $f['student_ids']))
            ->when(! empty($f['subject_id']), fn ($q) => $q->where('s.subject_id', $f['subject_id']))
            ->when(! empty($f['period_id']), fn ($q) => $q->where('s.period_id', $f['period_id']))
            ->when(! empty($f['mode']), fn ($q) => $q->where('s.mode', $f['mode']))
            ->when($excluded, fn ($q) => $q->whereNotIn('r.session_id', $excluded))
            // a subject teacher sees only their subjects in those classes
            ->when(! empty($f['subject_limits']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->whereNotIn('r.class_id', array_keys($f['subject_limits']));

                foreach ($f['subject_limits'] as $classId => $ids) {
                    $w->orWhere(fn ($x) => $x->where('r.class_id', $classId)->whereIn('s.subject_id', $ids ?: [0]));
                }
            }));
    }

    public static function percent(float $attended, float $total): ?float
    {
        return $total > 0 ? round($attended / $total * 100, 2) : null;
    }

    private const SUMS = "SUM(r.status = 'present') present, SUM(r.status = 'absent') absent, SUM(r.status = 'late') late, SUM(r.status = 'leave') on_leave, COUNT(*) total";

    public static function figures(?object $r): array
    {
        $out = ['present' => (int) ($r->present ?? 0), 'absent' => (int) ($r->absent ?? 0), 'late' => (int) ($r->late ?? 0), 'leave' => (int) ($r->on_leave ?? 0), 'total' => (int) ($r->total ?? 0)];
        $out['percent'] = self::percent($out['present'] + $out['late'], $out['total']);

        return $out;
    }

    /** the five sums grouped by any columns (e.g. 'r.date', 's.subject_id') */
    public function grouped(array $f, array $by): Collection
    {
        return $this->query($f)->groupBy(...$by)->selectRaw(implode(', ', $by) . ', ' . self::SUMS)->get();
    }

    public function totals(array $f): array
    {
        return self::figures($this->query($f)->selectRaw(self::SUMS)->first());
    }

    /**
     * Per student: daily days, conducted periods, and the one percentage.
     *
     * @return array<int,array<string,mixed>>
     */
    public function students(array $f): array
    {
        $by = [];

        foreach ($this->grouped($f, ['r.student_id', 's.mode']) as $row) {
            $by[$row->student_id][$row->mode] = self::figures($row);
        }

        // each period date as a share of a day: only needed to count days
        $withPeriods = array_keys(array_filter($by, fn ($m) => isset($m['period'])));
        $shares = [];

        if ($withPeriods) {
            $rows = $this->query(['student_ids' => $withPeriods, 'mode' => 'period'] + $f)->groupBy('r.student_id', 'r.date')
                ->selectRaw("r.student_id, SUM(r.status IN ('present','late')) / COUNT(*) share")->get();

            foreach ($rows as $r) {
                $shares[$r->student_id][] = (float) $r->share;
            }
        }

        $empty = self::figures(null);
        $out = [];

        foreach ($by as $id => $m) {
            $daily = $m['daily'] ?? $empty;
            $period = $m['period'] ?? $empty;
            $attendedDays = $daily['present'] + $daily['late'] + array_sum($shares[$id] ?? []);
            $days = $daily['total'] + count($shares[$id] ?? []);
            $sum = fn (string $k) => $daily[$k] + $period[$k];
            $mode = count($m) > 1 ? 'mixed' : (isset($m['period']) ? 'period' : 'daily');

            $out[$id] = [
                'daily' => $daily, 'period' => $period, 'mode' => $mode,
                'present' => $sum('present'), 'absent' => $sum('absent'), 'late' => $sum('late'), 'leave' => $sum('leave'), 'total' => $sum('total'),
                'percent' => match ($mode) {
                    'mixed' => self::percent($attendedDays, $days),
                    'period' => $period['percent'],
                    default => $daily['percent'],
                },
                'attended_days' => round($attendedDays, 2), 'days' => $days,
            ];
        }

        return $out;
    }

    /** date by date for the filtered student: a daily status, or the day's periods with "partial" for a mixed day */
    public function days(array $f): array
    {
        $rows = $this->query($f)->orderBy('r.date')->orderBy('s.starts_at')
            ->get(['r.date', 'r.status', 's.mode', 's.period_name', 's.subject_name', 'r.remark']);
        $out = [];

        foreach ($rows->groupBy(fn ($r) => substr($r->date, 0, 10)) as $date => $mine) {
            if ($mine->first()->mode === 'daily') {
                $out[] = ['date' => $date, 'mode' => 'daily', 'status' => $mine->first()->status, 'remark' => $mine->first()->remark, 'periods' => []];

                continue;
            }

            $n = fn (string $s) => $mine->where('status', $s)->count();
            $statuses = $mine->pluck('status')->unique();
            $out[] = [
                'date' => $date, 'mode' => 'period', 'status' => $statuses->count() === 1 ? $statuses->first() : 'partial',
                'total' => $mine->count(), 'present' => $n('present'), 'absent' => $n('absent'), 'late' => $n('late'), 'leave' => $n('leave'),
                'percent' => self::percent($n('present') + $n('late'), $mine->count()),
                'periods' => $mine->map(fn ($r) => ['name' => $r->period_name, 'subject' => $r->subject_name, 'status' => $r->status])->values()->all(),
            ];
        }

        return $out;
    }

    /** the longest run of days absent (every record of the day) for each student, and the run still open */
    public function absenceRuns(array $f): array
    {
        $rows = $this->query($f)->groupBy('r.student_id', 'r.date')->orderBy('r.student_id')->orderBy('r.date')
            ->selectRaw("r.student_id, r.date, SUM(r.status = 'absent') = COUNT(*) away")->cursor();
        $out = [];
        $id = null;
        $run = 0;
        $start = null;

        foreach ($rows as $r) {
            if ($r->student_id !== $id) {
                [$id, $run, $start] = [$r->student_id, 0, null];
                $out[$id] = ['longest' => 0, 'from' => null, 'to' => null, 'current' => 0];
            }

            if ($r->away) {
                $start ??= substr($r->date, 0, 10);
                $run++;

                if ($run > $out[$id]['longest']) {
                    $out[$id] = ['longest' => $run, 'from' => $start, 'to' => substr($r->date, 0, 10), 'current' => $run];
                }
            } else {
                [$run, $start] = [0, null];
            }

            $out[$id]['current'] = $run;
        }

        return $out;
    }
}
