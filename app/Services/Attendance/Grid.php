<?php

namespace App\Services\Attendance;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\AcademicCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The attendance register of a class for a month: students down, days across. */
class Grid
{
    public function __construct(private Calculator $calc, private AcademicCalendar $calendar, private Roster $roster)
    {
    }

    /**
     * @param  string  $month  YYYY-MM
     * @return array{days:array, students:array, subjects:array, modes:array, working_days:int}
     */
    public function month(User $user, SchoolClass $class, string $month, ?string $from = null, ?string $to = null): array
    {
        $first = Carbon::parse("{$month}-01");
        $a = max($from ?: $first->toDateString(), $first->toDateString());
        $b = min($to ?: $first->copy()->endOfMonth()->toDateString(), $first->copy()->endOfMonth()->toDateString());
        $limit = Access::subjectLimit($user, 'attendance.view', $class->id);
        $q = ['from' => $a, 'to' => $b, 'class_ids' => [$class->id], 'subject_limits' => $limit === null ? [] : [$class->id => $limit]];

        $days = [];

        for ($d = Carbon::parse($a); $d->toDateString() <= $b; $d->addDay()) {
            $s = $d->toDateString();
            $state = $this->calendar->day($s, $class->id);
            $days[] = ['date' => $s, 'day' => (int) $d->format('j'), 'weekday' => $d->dayOfWeek, 'working' => $state['working'], 'type' => $state['type'], 'reason' => $state['reason']];
        }

        $cells = [];
        $modes = [];
        $rows = $this->calc->query($q)->groupBy('r.student_id', 'r.date', 's.mode')
            ->selectRaw("r.student_id, r.date, s.mode, MAX(r.status) status, SUM(r.status IN ('present','late')) attended, COUNT(*) total, SUM(r.status = 'absent') absent, SUM(r.status = 'leave') on_leave")->get();

        foreach ($rows as $r) {
            $date = substr($r->date, 0, 10);
            $modes[$r->mode] = true;
            $cells[$r->student_id][$date] = $r->mode === 'daily' ? ['s' => $r->status]
                : ['s' => $r->attended == $r->total ? 'present' : ($r->absent == $r->total ? 'absent' : ($r->on_leave == $r->total ? 'leave' : 'partial')), 't' => "{$r->attended}/{$r->total}"];
        }

        $today = AcademicCalendar::today();
        $current = $a <= $today ? $this->roster->students($class, min($b, $today)) : collect();
        $extra = collect(array_keys($cells))->diff($current->pluck('id'));
        $students = $extra->isEmpty() ? $current : $this->roster->sort($current->concat(Student::withTrashed()->whereIn('id', $extra)->get()));
        $stats = $this->calc->students($q);

        $subjects = [];
        $bySubject = [];

        if (isset($modes['period'])) {
            $found = $this->calc->grouped(['mode' => 'period'] + $q, ['r.student_id', 's.subject_id'])->whereNotNull('subject_id');
            $names = DB::table('subjects')->whereIn('id', $found->pluck('subject_id')->unique())->orderBy('name')->pluck('name', 'id');
            $subjects = $names->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n])->values()->all();

            foreach ($found as $r) {
                $bySubject[$r->student_id][$r->subject_id] = Calculator::figures($r);
            }
        }

        $empty = Calculator::figures(null);

        return [
            'from' => $a, 'to' => $b, 'days' => $days, 'modes' => array_keys($modes), 'subjects' => $subjects,
            'working_days' => count(array_filter($days, fn ($d) => $d['working'] && $d['date'] <= $today)),
            'students' => $students->map(fn ($s) => [
                'id' => $s->id, 'roll' => $s->roll_number, 'name' => $s->student_name, 'cells' => (object) ($cells[$s->id] ?? []),
                'sum' => array_intersect_key($stats[$s->id] ?? $empty, array_flip(['present', 'absent', 'late', 'leave', 'total', 'percent'])),
                'subjects' => (object) ($bySubject[$s->id] ?? []),
            ])->values()->all(),
        ];
    }
}
