<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Leave;
use App\Models\Attendance\Record;
use App\Models\Attendance\Session;
use App\Models\AuditLog;
use App\Models\RoutinePeriod;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teaching\TeachingPlan;
use App\Models\User;
use App\Services\AcademicCalendar;
use App\Services\RoutineService;
use App\Services\Teaching\Insights;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Taking attendance: what a class has on a date, the sheet to mark, and saving it (all or nothing). */
class Register
{
    public function __construct(private Modes $modes, private Roster $roster, private AcademicCalendar $calendar, private RoutineService $routines)
    {
    }

    // ------------------------------------------------------------------
    // the day
    // ------------------------------------------------------------------

    /**
     * The periods of a class on a date: from the routine (published copy, else draft), plus any already recorded that
     * the timetable no longer has.
     *
     * @return array<int,array<string,mixed>>
     */
    public function periods(SchoolClass $class, string $date, ?array $day = null): array
    {
        $day ??= $this->calendar->day($date, $class->id);
        $weekday = $day['routine_day'] ?? Carbon::parse($date)->dayOfWeek;
        $routine = $this->routines->routineFor($class);
        $slots = collect($routine->published_slots ?? $routine->slots()->get()->toArray())->where('day', $weekday);
        $periods = RoutinePeriod::whereIn('id', $slots->pluck('period_id'))->get()->keyBy('id');
        $subjects = DB::table('subjects')->whereIn('id', $slots->pluck('subject_id')->filter())->pluck('name', 'id');
        $teachers = User::whereIn('id', $slots->pluck('teacher_id')->filter())->pluck('name', 'id');
        $sessions = Session::where(['class_id' => $class->id, 'mode' => 'period'])->whereDate('date', $date)->with('takenBy:id,name')->get()->keyBy('slot_key');
        $today = AcademicCalendar::today();
        $out = [];

        foreach ($slots as $s) {
            $p = $periods[$s['period_id']] ?? null;

            if (! $p || $p->is_break || (empty($s['subject_id']) && empty($s['label']))) {
                continue;
            }

            $out[(int) $p->id] = [
                'period_id' => (int) $p->id, 'period_name' => $p->name, 'starts_at' => substr((string) $p->starts_at, 0, 5), 'ends_at' => substr((string) $p->ends_at, 0, 5),
                'subject_id' => ! empty($s['subject_id']) ? (int) $s['subject_id'] : null, 'subject_name' => ! empty($s['subject_id']) ? ($subjects[$s['subject_id']] ?? null) : $s['label'],
                'teacher_id' => ! empty($s['teacher_id']) ? (int) $s['teacher_id'] : null, 'teacher_name' => ! empty($s['teacher_id']) ? ($teachers[$s['teacher_id']] ?? null) : null,
            ];
        }

        // what was recorded stays as it was recorded
        foreach ($sessions as $key => $x) {
            $out[(int) $key] = $x->only(['period_id', 'period_name', 'starts_at', 'ends_at', 'subject_id', 'subject_name', 'teacher_id', 'teacher_name']);
        }

        foreach ($out as $key => &$row) {
            $x = $sessions[$key] ?? null;
            $row['session_id'] = $x?->id;
            $row['state'] = $x ? 'submitted' : ($date < $today ? 'not_taken' : 'pending');
            $row['taken_by'] = $x?->takenBy?->name;
        }

        unset($row);
        usort($out, fn ($a, $b) => [(string) $a['starts_at'], $a['period_id']] <=> [(string) $b['starts_at'], $b['period_id']]);

        return $out;
    }

    // ------------------------------------------------------------------
    // the sheet
    // ------------------------------------------------------------------

    /** everything the marking screen shows for a class, date and (period-wise) period */
    public function sheet(User $user, SchoolClass $class, string $date, ?int $periodId = null): array
    {
        $day = $this->calendar->day($date, $class->id);
        $recorded = Session::where('class_id', $class->id)->whereDate('date', $date)->orderBy('id')->get();
        // what is already recorded for the date decides; otherwise the mode in force that day
        $mode = $recorded->first()?->mode ?? $this->modes->for($class->id, $date);
        $periods = $mode === 'period' ? array_map(fn ($p) => $p + ['mine' => $p['teacher_id'] === $user->id], $this->periods($class, $date, $day)) : [];
        $slot = $mode === 'period' ? collect($periods)->firstWhere('period_id', $periodId) : null;
        $session = $mode === 'daily' ? $recorded->firstWhere('slot_key', 0) : ($slot ? $recorded->firstWhere('slot_key', $slot['period_id']) : null);

        $out = [
            'mode' => $mode, 'day' => $day, 'periods' => $periods, 'period' => $slot, 'session' => null, 'students' => [],
            'can' => ['save' => false, 'reason' => null], 'statuses' => Settings::allowed(),
        ];

        if ($mode === 'period' && ! $slot) {
            return $out;
        }

        [$ok, $reason] = $this->permitted($user, $class, $date, $mode, $slot, $session, $day);
        $out['can'] = ['save' => $ok, 'reason' => $reason];

        $records = $session ? $session->records()->get()->keyBy('student_id') : collect();
        $students = $this->roster->students($class, $date, $slot['subject_id'] ?? null);
        $extra = $records->keys()->diff($students->pluck('id'));

        if ($extra->isNotEmpty()) {
            $students = $this->roster->sort($students->concat(Student::withTrashed()->whereIn('id', $extra)->get()));
        }

        $leaves = $this->approvedLeaves($students->pluck('id')->all(), $date);
        $default = Settings::get('default_status') === 'present' ? 'present' : null;

        $out['students'] = $students->map(function ($s) use ($records, $leaves, $default) {
            $r = $records->get($s->id);
            $leave = $leaves->get($s->id);

            return [
                'id' => $s->id, 'code' => $s->student_id, 'name' => $s->student_name, 'roll' => $s->roll_number, 'photo' => $s->student_photo,
                'status' => $r ? $r->status : ($leave ? 'leave' : $default), 'remark' => $r?->remark, 'leave' => $leave?->type,
            ];
        })->values()->all();

        if ($session) {
            $session->load('takenBy:id,name', 'updatedBy:id,name');
            $out['session'] = [
                'id' => $session->id, 'revision' => $session->revision, 'taken_by' => $session->takenBy?->name, 'submitted_at' => $session->submitted_at?->toDateTimeString(),
                'updated_by' => $session->updatedBy?->name, 'updated_at' => $session->updated_at?->toDateTimeString(), 'late' => $session->late_submission,
                'substitute' => $session->substitute, 'source' => $session->source,
                'history' => AuditLog::forRecord($session)->with('user:id,name')->latest('id')->limit(20)->get()
                    ->map(fn ($a) => ['at' => $a->created_at ? (string) $a->created_at : null, 'by' => $a->user?->name, 'event' => $a->event, 'changes' => $a->changes])->all(),
            ];
        }

        return $out;
    }

    private function approvedLeaves(array $studentIds, string $date)
    {
        return Leave::where('status', 'approved')->whereIn('student_id', $studentIds)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->get()->keyBy('student_id');
    }

    /** @return array{0:bool,1:?string} whether the user may save this sheet, and if not, why */
    public function permitted(User $user, SchoolClass $class, string $date, string $mode, ?array $slot, ?Session $session, array $day): array
    {
        $today = AcademicCalendar::today();
        $ability = $session ? 'attendance.edit' : 'attendance.take';
        $allowed = fn (string $a) => $mode === 'daily' ? Access::takeDaily($user, $class->id, $a) : Access::takePeriod($user, $class->id, $slot, $date, $a);
        $no = fn (string $key, array $p = []) => [false, __("attendance.err.{$key}", $p)];

        if ($date > $today) {
            return $no('future');
        }

        if (substr($date, 0, 4) !== substr((string) $class->academic_year, 0, 4)) {
            return $no('wrong_year', ['year' => $class->academic_year]);
        }

        $old = $session
            ? Carbon::parse($session->submitted_at ?? $session->date)->toDateString() < Carbon::parse($today)->subDays((int) Settings::get('edit_days'))->toDateString()
            : $date < Carbon::parse($today)->subDays((int) Settings::get('entry_days'))->toDateString();

        // the permission for older days covers both taking and correcting them
        if (! ($allowed($ability) || ($old && $allowed('attendance.edit_old')))) {
            return $no($session ? 'no_edit' : 'no_take');
        }

        if ($old && ! $allowed('attendance.edit_old')) {
            return $no('too_old');
        }

        if (! $session && $mode === 'period' && $date < $today && Settings::get('period_same_day') && ! $allowed('attendance.edit_old')) {
            return $no('same_day');
        }

        if (! $day['working'] && ! $user->canDo('attendance.override_holiday')) {
            return $no('closed', ['reason' => $day['reason']]);
        }

        return [true, null];
    }

    // ------------------------------------------------------------------
    // saving
    // ------------------------------------------------------------------

    /**
     * @param  array<int,array{student_id:int,status:?string,remark?:?string}>  $entries  every student of the sheet
     * @param  ?int  $revision  the revision the screen was showing; null for a first submission
     */
    public function save(User $user, SchoolClass $class, string $date, ?int $periodId, array $entries, ?int $revision = null): Session
    {
        $sheet = $this->sheet($user, $class, $date, $periodId);

        if ($sheet['mode'] === 'period' && ! $sheet['period']) {
            $this->fail('no_period');
        }

        if (! $sheet['can']['save']) {
            throw ValidationException::withMessages(['attendance' => $sheet['can']['reason']]);
        }

        $current = $sheet['session']['revision'] ?? null;

        if ($current !== $revision) {
            $this->fail($current !== null && $revision === null ? 'already' : 'changed', ['name' => $sheet['session']['updated_by'] ?? $sheet['session']['taken_by'] ?? '—']);
        }

        $expected = collect($sheet['students'])->keyBy('id');
        $given = collect($entries)->keyBy(fn ($e) => (int) $e['student_id']);

        if ($expected->isEmpty()) {
            $this->fail('no_students');
        }

        if ($given->keys()->diff($expected->keys())->isNotEmpty()) {
            $this->fail('not_in_class');
        }

        if ($expected->keys()->diff($given->keys())->isNotEmpty() || $given->contains(fn ($e) => empty($e['status']))) {
            $this->fail('unmarked');
        }

        $existing = $sheet['session'] ? Record::where('session_id', $sheet['session']['id'])->get()->keyBy('student_id') : collect();
        $leaves = $this->approvedLeaves($given->keys()->all(), $date);

        foreach ($given as $id => $e) {
            // a status switched off in the settings can stay where it already is, and where approved leave puts it
            if (! in_array($e['status'], Settings::allowed(), true) && ($existing[$id]->status ?? null) !== $e['status'] && ! ($e['status'] === 'leave' && isset($leaves[$id]))) {
                $this->fail('bad_status');
            }
        }

        $slot = $sheet['period'];

        try {
            return DB::transaction(function () use ($user, $class, $date, $slot, $given, $existing, $sheet, $leaves, $revision) {
                $now = now();

                // one mode per class and date: the lock on the class row puts submissions of a class in a line
                SchoolClass::whereKey($class->id)->lockForUpdate()->first();

                if ($sheet['session']) {
                    $session = Session::whereKey($sheet['session']['id'])->lockForUpdate()->firstOrFail();
                    $session->revision === $revision || $this->fail('changed', ['name' => $session->updatedBy?->name ?? '—']);
                } else {
                    if (Session::where('class_id', $class->id)->whereDate('date', $date)->where('mode', '!=', $sheet['mode'])->exists()) {
                        $this->fail('other_mode');
                    }

                    $local = now('Asia/Dhaka');
                    $deadline = Settings::get('deadline');
                    $session = Session::create([
                        'class_id' => $class->id, 'academic_year' => $class->academic_year, 'date' => $date, 'mode' => $sheet['mode'], 'slot_key' => $slot['period_id'] ?? 0,
                        'day_type' => $sheet['day']['working'] ? ($sheet['day']['type'] === 'makeup' ? 'makeup' : 'working') : $sheet['day']['type'],
                        'substitute' => $slot && $slot['teacher_id'] && $slot['teacher_id'] !== $user->id,
                        'late_submission' => $sheet['mode'] === 'daily' && $deadline && ($date < $local->toDateString() || $local->format('H:i') > $deadline),
                        'taken_by' => $user->id, 'submitted_at' => $now,
                    ] + ($slot ? array_intersect_key($slot, array_flip(['period_id', 'period_name', 'starts_at', 'ends_at', 'subject_id', 'subject_name', 'teacher_id', 'teacher_name'])) : []));
                }

                $rows = [];
                $changes = [];

                foreach ($given as $id => $e) {
                    $before = $existing[$id] ?? null;
                    $remark = isset($e['remark']) && trim((string) $e['remark']) !== '' ? mb_substr(trim($e['remark']), 0, 255) : null;

                    if ($before && $before->status === $e['status'] && $before->remark === $remark) {
                        continue;
                    }

                    if ($before && $before->status !== $e['status']) {
                        $changes[] = ['student' => $id, 'from' => $before->status, 'to' => $e['status']];
                    }

                    $rows[] = [
                        'session_id' => $session->id, 'student_id' => $id, 'class_id' => $class->id, 'date' => $date, 'slot_key' => $session->slot_key, 'status' => $e['status'],
                        'remark' => $remark, 'leave_id' => $e['status'] === 'leave' ? $leaves->get($id)?->id : null, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }

                if ($sheet['session']) {
                    if ($rows) {
                        // a student recorded the same day in another class stops the change instead of overwriting it
                        $new = array_values(array_filter($rows, fn ($r) => ! isset($existing[$r['student_id']])));
                        $old = array_values(array_filter($rows, fn ($r) => isset($existing[$r['student_id']])));
                        $new && Record::insert($new);
                        $old && Record::upsert($old, ['student_id', 'date', 'slot_key'], ['status', 'remark', 'leave_id', 'updated_at']);
                        $session->fill(['updated_by' => $user->id, 'revision' => $session->revision + 1])->save();
                        AuditLog::record($session, 'attendance.edited', ['students' => count($rows), 'changes' => array_slice($changes, 0, 200)], $user->id);
                    }
                } else {
                    foreach (array_chunk($rows, 500) as $chunk) {
                        Record::insert($chunk);
                    }

                    AuditLog::record($session, 'attendance.submitted', ['students' => count($rows), 'closed_day' => $sheet['day']['working'] ? null : $sheet['day']['reason']], $user->id);

                    if ($slot) {
                        $this->markClassHeld($user, $session);
                    }
                }

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('duplicate');
        }
    }

    /** a submitted period counts as "class held" in the Daily Teaching Log, so it is not entered twice */
    private function markClassHeld(User $user, Session $s): void
    {
        if (! $s->subject_id || ! $s->period_id || ! ($s->teacher_id || $user->isTeacher())) {
            return;
        }

        $teacher = $s->teacher_id ?? $user->id;

        $added = DB::table('teaching_logs')->insertOrIgnore([
            'log_date' => $s->date->toDateString(), 'class_id' => $s->class_id, 'period_id' => $s->period_id,
            'subject_id' => $s->subject_id, 'teacher_id' => $teacher, 'substitute_id' => $s->substitute && $user->isTeacher() ? $user->id : null, 'kind' => 'regular',
            'plan_id' => TeachingPlan::where(['teacher_id' => $teacher, 'class_id' => $s->class_id, 'subject_id' => $s->subject_id])->value('id'),
            'held' => true, 'coverage' => 0, 'note' => __('attendance.class_held_note'), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($added) {
            Insights::touch();
        }
    }

    // ------------------------------------------------------------------
    // overview
    // ------------------------------------------------------------------

    /** how far a date's attendance is, per class */
    public function status(iterable $classes, string $date): array
    {
        $ids = collect($classes)->pluck('id');
        $sessions = DB::table('attendance_sessions')->whereIn('class_id', $ids)->whereDate('date', $date)->get(['class_id', 'mode', 'slot_key'])->groupBy('class_id');
        $out = [];

        foreach ($classes as $c) {
            $day = $this->calendar->day($date, $c->id);
            $done = $sessions[$c->id] ?? collect();
            $mode = $done->isNotEmpty() ? $done->first()->mode : $this->modes->for($c->id, $date);
            $due = $mode === 'daily' ? 1 : ($day['working'] ? count($this->periods($c, $date, $day)) : 0);
            $out[$c->id] = [
                'mode' => $mode, 'working' => $day['working'], 'reason' => $day['reason'], 'done' => $done->count(), 'due' => max($due, $done->count()),
                'state' => ! $day['working'] && $done->isEmpty() ? 'closed' : ($due && $done->count() >= $due ? 'taken' : ($done->count() ? 'partial' : ($due ? 'pending' : 'none'))),
            ];
        }

        return $out;
    }

    /** sessions on days the calendar now calls closed (declared after attendance was taken): kept, and flagged */
    public function conflicts(?array $classIds, string $from, string $to, int $limit = 50): array
    {
        $rows = DB::table('attendance_sessions')->whereBetween('date', [$from, $to])->whereIn('day_type', ['working', 'makeup'])
            ->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))->get(['id', 'class_id', 'date', 'period_name']);
        $out = [];

        foreach ($rows->groupBy('class_id') as $classId => $mine) {
            $dates = $mine->map(fn ($r) => substr($r->date, 0, 10));
            $closed = array_flip($this->calendar->holidayDays($dates->min(), $dates->max(), (int) $classId));

            foreach ($mine as $r) {
                $d = substr($r->date, 0, 10);

                if (isset($closed[$d])) {
                    $out[] = ['session_id' => $r->id, 'class_id' => (int) $classId, 'date' => $d, 'period' => $r->period_name, 'reason' => $this->calendar->day($d, (int) $classId)['reason']];
                }
            }
        }

        usort($out, fn ($a, $b) => $b['date'] <=> $a['date']);

        return array_slice($out, 0, $limit);
    }

    private function fail(string $key, array $p = []): never
    {
        throw ValidationException::withMessages(['attendance' => __("attendance.err.{$key}", $p)]);
    }
}
