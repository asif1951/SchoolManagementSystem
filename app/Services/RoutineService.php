<?php

namespace App\Services;

use App\Models\ClassRoutine;
use App\Models\ClassRoutineSlot;
use App\Models\RoutinePeriod;
use App\Models\RoutinePlan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The class routine. Everything about who teaches what comes from the class module (a class's subjects, its subject
 * teachers, its class teacher); this adds only WHEN: the bell schedule, the working days, and which subject sits in
 * which period of which day, per class row (so section A and section B can differ).
 *
 * Rules kept here, not in the screens:
 *   - a cell is a working day × a real (non-break) period of the class's own shift;
 *   - a subject must be one of the class's subjects; a teacher must be an active teacher;
 *   - one teacher cannot be in two places at overlapping times (compared by clock time, so it holds across shifts);
 *   - what teachers see is the PUBLISHED copy: editing never leaks half-made changes.
 */
class RoutineService
{
    /** 0 = Sunday … 6 = Saturday (PHP's date('w')) */
    public const DAYS = [
        0 => ['en' => 'Sunday', 'short' => 'Sun', 'bn' => 'রবিবার'],
        1 => ['en' => 'Monday', 'short' => 'Mon', 'bn' => 'সোমবার'],
        2 => ['en' => 'Tuesday', 'short' => 'Tue', 'bn' => 'মঙ্গলবার'],
        3 => ['en' => 'Wednesday', 'short' => 'Wed', 'bn' => 'বুধবার'],
        4 => ['en' => 'Thursday', 'short' => 'Thu', 'bn' => 'বৃহস্পতিবার'],
        5 => ['en' => 'Friday', 'short' => 'Fri', 'bn' => 'শুক্রবার'],
        6 => ['en' => 'Saturday', 'short' => 'Sat', 'bn' => 'শনিবার'],
    ];

    /** the week as Bangladeshi schools write it: Saturday first */
    public const WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5];

    // ------------------------------------------------------------------
    // days and periods
    // ------------------------------------------------------------------

    /** the school's working days, in week order */
    public function workingDays(): array
    {
        $days = json_decode((string) DB::table('routine_settings')->value('working_days'), true) ?: [0, 1, 2, 3, 4];

        return array_values(array_filter(self::WEEK_ORDER, fn ($d) => in_array($d, $days, true)));
    }

    public function setWorkingDays(array $days): void
    {
        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn ($d) => isset(self::DAYS[$d]))));

        if (! $days) {
            throw ValidationException::withMessages(['days' => 'Choose at least one working day.']);
        }

        sort($days);

        if (DB::table('routine_settings')->exists()) {
            DB::table('routine_settings')->update(['working_days' => json_encode($days), 'updated_at' => now()]);
        } else {
            DB::table('routine_settings')->insert(['working_days' => json_encode($days), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** the periods a shift uses: its own set if it has one, else the common set */
    public function periodsFor(?int $shiftId): Collection
    {
        $own = $shiftId ? RoutinePeriod::whereNull('class_id')->where('shift_id', $shiftId)->orderBy('sort_order')->orderBy('starts_at')->get() : collect();

        return $own->isNotEmpty() ? $own : RoutinePeriod::whereNull('class_id')->whereNull('shift_id')->orderBy('sort_order')->orderBy('starts_at')->get();
    }

    /** a sensible first set, so the admin starts from something to adjust rather than from nothing */
    public function ensureDefaultPeriods(): void
    {
        if (RoutinePeriod::whereNull('class_id')->exists()) {
            return;
        }

        $set = [['1st', '10:00', '10:40', false], ['2nd', '10:40', '11:20', false], ['3rd', '11:20', '12:00', false], ['4th', '12:00', '12:40', false],
                ['Break', '12:40', '13:10', true], ['5th', '13:10', '13:50', false], ['6th', '13:50', '14:30', false], ['7th', '14:30', '15:10', false]];

        foreach ($set as $i => [$name, $from, $to, $break]) {
            RoutinePeriod::create(['shift_id' => null, 'name' => $name, 'starts_at' => $from, 'ends_at' => $to, 'is_break' => $break, 'sort_order' => $i + 1]);
        }
    }

    /**
     * Replace a shift's whole set of periods in one go (the Periods screen saves a table at once).
     *
     * @param  array<int,array{name:string,starts_at:string,ends_at:string,is_break?:bool}>  $rows  in order
     */
    public function savePeriods(?int $shiftId, array $rows): void
    {
        $clean = [];

        foreach (array_values($rows) as $i => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $from = substr((string) ($r['starts_at'] ?? ''), 0, 5);
            $to = substr((string) ($r['ends_at'] ?? ''), 0, 5);

            if ($name === '' || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to)) {
                throw ValidationException::withMessages(['periods' => 'Row ' . ($i + 1) . ': give a name and a start and end time.']);
            }

            if ($to <= $from) {
                throw ValidationException::withMessages(['periods' => "Row " . ($i + 1) . " ({$name}): it must end after it starts."]);
            }

            if ($clean && $from < end($clean)['ends_at']) {
                throw ValidationException::withMessages(['periods' => "Row " . ($i + 1) . " ({$name}) starts before the one above it ends."]);
            }

            $clean[] = ['shift_id' => $shiftId, 'name' => mb_substr($name, 0, 40), 'starts_at' => $from, 'ends_at' => $to, 'is_break' => (bool) ($r['is_break'] ?? false), 'sort_order' => $i + 1];
        }

        if (! $clean) {
            throw ValidationException::withMessages(['periods' => 'Add at least one period.']);
        }

        DB::transaction(function () use ($shiftId, $clean) {
            // keep the rows (and so the cells that use them) whose position still exists; add or drop the rest
            $existing = RoutinePeriod::whereNull('class_id')->where('shift_id', $shiftId)->orderBy('sort_order')->orderBy('starts_at')->get()->values();

            foreach ($clean as $i => $row) {
                $existing->has($i) ? $existing[$i]->update($row) : RoutinePeriod::create($row);
            }

            $existing->slice(count($clean))->each->delete();       // cells in a removed period go with it
            $this->dropCellsInBreaks();
            $this->pruneSnapshots();
        });
    }



    // ------------------------------------------------------------------
    // keeping to the weekly plan
    // ------------------------------------------------------------------

    /** the cells of a routine that hold this subject */
    private function cellsOf(ClassRoutine $routine, string $subject, ?int $subjectId)
    {
        return ClassRoutineSlot::where('class_routine_id', $routine->id)->where(fn ($w) => $subjectId ? $w->where('subject_id', $subjectId) : $w->where('label', $subject));
    }

    /**
     * The class's weekly plan is a rule, not only a hint for the automatic builder: a subject cannot be placed more times in
     * the week than the plan says, nor more times in one day than "most in a day". Subjects the plan does not mention (or
     * gives 0) are free. Change the plan to allow more.
     *
     * @throws ValidationException
     */
    private function assertWithinPlan(SchoolClass $class, ClassRoutine $routine, string $subject, ?int $subjectId, int $day, int $periodId): void
    {
        $plan = RoutinePlan::where('class_id', $class->id)->where('subject', $subject)->first();

        if (! $plan || $plan->periods_per_week < 1) {
            return;
        }

        // everything of this subject except the cell being filled (replacing a cell with the same subject adds nothing)
        $others = fn () => $this->cellsOf($routine, $subject, $subjectId)->where(fn ($w) => $w->where('day', '!=', $day)->orWhere('period_id', '!=', $periodId));

        $onDay = $others()->where('day', $day)->count();

        if ($onDay >= $plan->max_per_day) {
            throw ValidationException::withMessages(['subject' => "{$subject} is limited to {$plan->max_per_day} a day in this class's plan, and " . self::DAYS[$day]['en'] . ' already has ' . $onDay . '. Change the weekly plan (Make it automatically) to allow more.']);
        }

        $week = $others()->count();

        if ($week >= $plan->periods_per_week) {
            throw ValidationException::withMessages(['subject' => "{$subject} already has all {$plan->periods_per_week} periods of the week in this class's plan. Change the weekly plan (Make it automatically) to allow more."]);
        }
    }

    /**
     * Where a routine disagrees with its weekly plan: too many periods of a subject, a subject twice on a day (more than the plan
     * allows), and how many still have to be placed.
     *
     * @param  array<string,array>  $cells  the grid's cells
     * @return array{over:array<int,string>, under:array<int,string>}
     */
    private function planCheck(SchoolClass $class, array $cells): array
    {
        $plan = RoutinePlan::where('class_id', $class->id)->get()->filter(fn ($p) => $p->periods_per_week > 0);
        $over = [];
        $under = [];

        if ($plan->isEmpty()) {
            return compact('over', 'under');
        }

        $placed = [];

        foreach ($cells as $key => $cell) {
            [$day] = explode('-', $key);
            $placed[$cell['subject']][(int) $day] = ($placed[$cell['subject']][(int) $day] ?? 0) + 1;
        }

        foreach ($plan as $p) {
            $byDay = $placed[$p->subject] ?? [];
            $n = array_sum($byDay);

            if ($n > $p->periods_per_week) {
                $over[] = "{$p->subject}: {$n} placed, but the plan says {$p->periods_per_week} a week";
            } elseif ($n < $p->periods_per_week) {
                $under[] = ($p->periods_per_week - $n) . " more {$p->subject}";
            }

            foreach ($byDay as $d => $count) {
                if ($count > $p->max_per_day) {
                    $over[] = "{$p->subject} is on " . self::DAYS[$d]['en'] . " {$count} times, but the plan allows {$p->max_per_day} a day";
                }
            }
        }

        return compact('over', 'under');
    }

    /** subjects removed from a class: their periods leave its routine (draft and published copy) and their plan rows go */
    public function subjectsRemoved(SchoolClass $class, array $removed): int
    {
        RoutinePlan::where('class_id', $class->id)->whereIn('subject', $removed)->delete();

        $routine = ClassRoutine::where('class_id', $class->id)->first();

        if (! $routine) {
            return 0;
        }

        $ids = Subject::whereIn('name', $removed)->pluck('id')->all();
        $n = $routine->slots()->where(fn ($w) => $w->whereIn('label', $removed)->orWhereIn('subject_id', $ids ?: [0]))->delete();

        if ($routine->published_slots) {
            $kept = array_values(array_filter($routine->published_slots, fn ($s) => ! in_array($s['subject_id'] ?? 0, $ids, true) && ! in_array($s['label'] ?? '', $removed, true)));

            if (count($kept) !== count($routine->published_slots)) {
                $routine->timestamps = false;
                $routine->update(['published_slots' => $kept]);
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------
    // teachers in two places
    // ------------------------------------------------------------------

    /**
     * Every teacher who is in two classes at overlapping times right now. Placing a period cannot create one, but changing the
     * periods or breaks of a class can, so this is checked after such a change and shown on the routines page.
     *
     * @return array<int,array{teacher:string, day:string, time:string, classes:string}>
     */
    public function teacherClashes(int $limit = 50): array
    {
        $rows = DB::table('class_routine_slots as a')
            ->join('class_routine_slots as b', fn ($j) => $j->on('b.teacher_id', '=', 'a.teacher_id')->on('b.day', '=', 'a.day')->on('b.id', '>', 'a.id'))
            ->join('routine_periods as pa', 'pa.id', '=', 'a.period_id')
            ->join('routine_periods as pb', 'pb.id', '=', 'b.period_id')
            ->join('class_routines as ra', 'ra.id', '=', 'a.class_routine_id')
            ->join('class_routines as rb', 'rb.id', '=', 'b.class_routine_id')
            ->join('users as u', 'u.id', '=', 'a.teacher_id')
            ->whereColumn('a.class_routine_id', '!=', 'b.class_routine_id')
            ->where('pa.is_break', false)->where('pb.is_break', false)
            ->whereColumn('pa.starts_at', '<', 'pb.ends_at')->whereColumn('pb.starts_at', '<', 'pa.ends_at')
            ->orderBy('u.name')->orderBy('a.day')->limit($limit)
            ->get(['u.name as teacher', 'a.day', 'pa.starts_at as a_from', 'pa.ends_at as a_to', 'ra.class_id as a_class', 'rb.class_id as b_class']);

        $classes = SchoolClass::whereIn('id', $rows->pluck('a_class')->merge($rows->pluck('b_class'))->unique())->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'teacher' => $r->teacher, 'day' => self::DAYS[(int) $r->day]['en'],
            'time' => RoutinePeriod::clock(substr($r->a_from, 0, 5)) . ' – ' . RoutinePeriod::clock(substr($r->a_to, 0, 5)),
            'classes' => ($classes[$r->a_class]->short_label ?? '?') . ' and ' . ($classes[$r->b_class]->short_label ?? '?'),
        ])->all();
    }

    // ------------------------------------------------------------------
    // a class's own periods and breaks
    // ------------------------------------------------------------------

    /** the periods of a class: its own if it has them, else its shift's, else the common set */
    public function periodsForClass(SchoolClass $class): Collection
    {
        $own = RoutinePeriod::where('class_id', $class->id)->orderBy('sort_order')->orderBy('starts_at')->get();

        return $own->isNotEmpty() ? $own : $this->periodsFor($class->shift_id);
    }

    /** where a class's periods come from: 'class' (its own), 'shift' or 'common' */
    public function periodSource(SchoolClass $class): string
    {
        if (RoutinePeriod::where('class_id', $class->id)->exists()) {
            return 'class';
        }

        return $class->shift_id && RoutinePeriod::whereNull('class_id')->where('shift_id', $class->shift_id)->exists() ? 'shift' : 'common';
    }

    /** @return array<int,array{name:string,starts_at:string,ends_at:string,is_break:bool}>  validated, in order */
    private function cleanPeriodRows(array $rows): array
    {
        $clean = [];

        foreach (array_values($rows) as $i => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $from = substr((string) ($r['starts_at'] ?? ''), 0, 5);
            $to = substr((string) ($r['ends_at'] ?? ''), 0, 5);

            if ($name === '' || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to)) {
                throw ValidationException::withMessages(['periods' => 'Row ' . ($i + 1) . ': give a name and a start and end time.']);
            }

            if ($to <= $from) {
                throw ValidationException::withMessages(['periods' => 'Row ' . ($i + 1) . " ({$name}): it must end after it starts."]);
            }

            if ($clean && $from < end($clean)['ends_at']) {
                throw ValidationException::withMessages(['periods' => 'Row ' . ($i + 1) . " ({$name}) starts before the one above it ends."]);
            }

            $clean[] = ['name' => mb_substr($name, 0, 40), 'starts_at' => $from, 'ends_at' => $to, 'is_break' => (bool) ($r['is_break'] ?? false)];
        }

        if (! $clean) {
            throw ValidationException::withMessages(['periods' => 'Add at least one period.']);
        }

        if (! collect($clean)->contains(fn ($r) => ! $r['is_break'])) {
            throw ValidationException::withMessages(['periods' => 'Add at least one teaching period, not only breaks.']);
        }

        return $clean;
    }

    /**
     * Give a class periods of its own: its own length of period, its own breaks. What is already placed in the routine
     * moves with its position (the 3rd teaching period stays the 3rd), in the draft and in the published copy.
     *
     * @param  array<int,array{name:string,starts_at:string,ends_at:string,is_break?:bool}>  $rows
     */
    public function saveClassPeriods(SchoolClass $class, array $rows): void
    {
        $clean = $this->cleanPeriodRows($rows);

        DB::transaction(function () use ($class, $clean) {
            $before = $this->periodsForClass($class)->values();
            $own = RoutinePeriod::where('class_id', $class->id)->orderBy('sort_order')->orderBy('starts_at')->get()->values();

            foreach ($clean as $i => $row) {
                $data = $row + ['class_id' => $class->id, 'shift_id' => null, 'sort_order' => $i + 1];
                $own->has($i) ? $own[$i]->update($data) : $own->put($i, RoutinePeriod::create($data));
            }

            $own->slice(count($clean))->each->delete();

            $after = RoutinePeriod::where('class_id', $class->id)->orderBy('sort_order')->get()->values();

            // the class had no periods of its own until now: its cells sit on the shift's periods, so move them across
            if ($before->first()?->class_id === null) {
                $this->remap($class, $before, $after);
            }

            $this->dropCellsInBreaks();
            $this->pruneSnapshots();
        });
    }

    /** the class goes back to the periods of its shift (or the common set), its cells moving with their position */
    public function resetClassPeriods(SchoolClass $class): void
    {
        DB::transaction(function () use ($class) {
            $own = RoutinePeriod::where('class_id', $class->id)->orderBy('sort_order')->get()->values();

            if ($own->isEmpty()) {
                return;
            }

            $inherited = $this->periodsFor($class->shift_id)->values();
            $this->remap($class, $own, $inherited);
            RoutinePeriod::where('class_id', $class->id)->delete();
            $this->pruneSnapshots();
        });
    }

    /**
     * Use this class's periods for other classes too.
     *
     * @param  array<int,int>  $classIds
     */
    public function copyClassPeriods(SchoolClass $from, array $classIds): int
    {
        $rows = $this->periodsForClass($from)->map(fn ($p) => ['name' => $p->name, 'starts_at' => $p->start, 'ends_at' => $p->end, 'is_break' => $p->is_break])->values()->all();
        $n = 0;

        foreach (SchoolClass::whereIn('id', $classIds)->where('id', '!=', $from->id)->get() as $class) {
            $this->saveClassPeriods($class, $rows);
            $n++;
        }

        return $n;
    }

    /** move the cells of a class from one set of periods to another, teaching period by teaching period; cells with nowhere to go are dropped */
    private function remap(SchoolClass $class, Collection $from, Collection $to): void
    {
        $a = $from->where('is_break', false)->values();
        $b = $to->where('is_break', false)->values();
        $map = [];

        foreach ($a as $i => $p) {
            if ($b->has($i)) {
                $map[$p->id] = $b[$i]->id;
            }
        }

        $routine = $this->routineFor($class);

        foreach ($routine->slots()->get() as $slot) {
            isset($map[$slot->period_id]) ? $slot->update(['period_id' => $map[$slot->period_id]]) : $slot->delete();
        }

        if ($routine->published_slots) {
            $kept = [];

            foreach ($routine->published_slots as $s) {
                if (isset($map[$s['period_id']])) {
                    $kept[] = ['period_id' => $map[$s['period_id']]] + $s;
                }
            }

            $routine->update(['published_slots' => $kept]);
        }
    }

    /** published copies must not point at a period that was removed or turned into a break */
    private function pruneSnapshots(): void
    {
        $teaching = RoutinePeriod::where('is_break', false)->pluck('id')->flip();

        foreach (ClassRoutine::whereNotNull('published_slots')->get() as $r) {
            $kept = array_values(array_filter($r->published_slots, fn ($s) => isset($teaching[$s['period_id']])));

            if (count($kept) !== count($r->published_slots)) {
                $r->update(['published_slots' => $kept]);
            }
        }
    }

    /** a period turned into a break cannot hold a subject */
    private function dropCellsInBreaks(): void
    {
        ClassRoutineSlot::whereIn('period_id', RoutinePeriod::where('is_break', true)->select('id'))->delete();
    }

    // ------------------------------------------------------------------
    // the weekly plan (input of the automatic routine)
    // ------------------------------------------------------------------

    /**
     * The plan of a class: every subject with how many periods a week and the most in one day. A subject with no saved
     * plan gets a suggestion (the week shared out evenly), marked saved = false.
     *
     * @return array{rows:array<int,array>, slots:int, saved:bool}
     */
    public function planFor(SchoolClass $class): array
    {
        $subjects = $this->subjectsOf($class);
        $saved = RoutinePlan::where('class_id', $class->id)->get()->keyBy('subject');
        $days = count($this->workingDays());
        $slots = $this->periodsForClass($class)->where('is_break', false)->count() * $days;
        $n = max(1, count($subjects));
        $base = intdiv($slots, $n);
        $extra = $slots - $base * $n;

        $rows = [];

        foreach ($subjects as $i => $s) {
            $p = $saved[$s['name']] ?? null;
            $count = $p ? (int) $p->periods_per_week : $base + ($i < $extra ? 1 : 0);

            $rows[] = ['subject' => $s['name'], 'teacher' => $s['teacher'], 'teacher_id' => $s['teacher_id'], 'teachers' => $s['teachers'], 'periods_per_week' => $count,
                       'max_per_day' => $p ? (int) $p->max_per_day : ($days && $count > $days ? 2 : 1)];
        }

        return ['rows' => $rows, 'slots' => $slots, 'saved' => $saved->isNotEmpty()];
    }

    /** @param  array<int,array{subject:string,periods_per_week:int,max_per_day?:int}>  $rows */
    public function savePlan(SchoolClass $class, array $rows): void
    {
        $names = collect($this->subjectsOf($class))->pluck('name')->all();

        DB::transaction(function () use ($class, $rows, $names) {
            foreach ($rows as $r) {
                if (! in_array($r['subject'], $names, true)) {
                    continue;
                }

                RoutinePlan::updateOrCreate(
                    ['class_id' => $class->id, 'subject' => $r['subject']],
                    ['periods_per_week' => max(0, min(40, (int) $r['periods_per_week'])), 'max_per_day' => max(1, min(8, (int) ($r['max_per_day'] ?? 1)))],
                );
            }
        });
    }

    /** the same plan for the other sections of this class (same name, same year); subjects they do not have are left out */
    public function copyPlanToSections(SchoolClass $class): int
    {
        $rows = RoutinePlan::where('class_id', $class->id)->get(['subject', 'periods_per_week', 'max_per_day'])->map->only(['subject', 'periods_per_week', 'max_per_day'])->all();
        $n = 0;

        foreach (SchoolClass::where('class_name', $class->class_name)->where('academic_year', $class->academic_year)->where('id', '!=', $class->id)->get() as $other) {
            $this->savePlan($other, $rows);
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------
    // the routine of one class
    // ------------------------------------------------------------------

    public function routineFor(SchoolClass $class): ClassRoutine
    {
        return ClassRoutine::firstOrCreate(['class_id' => $class->id]);
    }

    /** the class's subjects with the teacher the class module assigned to each */
    public function subjectsOf(SchoolClass $class): array
    {
        $names = array_values(array_filter($class->subjects ?? [], fn ($n) => trim((string) $n) !== ''));
        $rows = Subject::whereIn('name', $names)->get()->keyBy('name');
        $lists = $class->currentSubjectTeacherLists();

        return array_map(function ($n) use ($rows, $lists) {
            $t = ($lists->get($n) ?? collect())->filter(fn ($a) => $a->user_id)->unique('user_id')->values();

            return [
                'name' => $n, 'subject_id' => $rows[$n]->id ?? null, 'short' => $rows[$n]->short_name ?? null,
                // the main teacher (the first assigned), all the teachers, and their names for display
                'teacher_id' => $t->first()?->user_id, 'teacher' => $t->pluck('teacher_name')->implode(', ') ?: null,
                'teachers' => $t->map(fn ($a) => ['id' => (int) $a->user_id, 'name' => $a->teacher_name])->all(),
            ];
        }, $names);
    }

    /**
     * Everything the editor and the print need for one class.
     *
     * @param  bool  $published  true = the frozen published copy, false = the draft being edited
     */
    public function grid(SchoolClass $class, bool $published = false): array
    {
        $routine = $this->routineFor($class);
        $periods = $this->periodsForClass($class);
        $days = $this->workingDays();
        $slots = $published ? collect($routine->published_slots ?? []) : $this->draftSlots($routine);

        $subjectNames = Subject::whereIn('id', $slots->pluck('subject_id')->filter()->unique())->get(['id', 'name', 'short_name'])->keyBy('id');
        $teacherNames = User::whereIn('id', $slots->pluck('teacher_id')->filter()->unique())->pluck('name', 'id');

        // every teacher of each subject, so a cell can name them all (the one taking the period is listed first)
        $subjectList = $this->subjectsOf($class);
        $namesOf = collect($subjectList)->keyBy('name')->map(fn ($x) => array_column($x['teachers'], 'name'));

        $cells = [];

        foreach ($slots as $s) {
            $subject = $s['subject_id'] ? ($subjectNames[$s['subject_id']] ?? null) : null;
            $mine = ($s['teacher_id'] ?? null) ? ($teacherNames[$s['teacher_id']] ?? null) : null;
            $all = $namesOf[$subject?->name ?? ($s['label'] ?? '')] ?? [];
            $all = array_values(array_unique(array_filter([$mine, ...$all])));
            $cells[$s['day'] . '-' . $s['period_id']] = [
                'subject' => $subject?->name ?? ($s['label'] ?: null), 'short' => $subject?->short_name, 'is_subject' => $subject !== null,
                'teacher_id' => $s['teacher_id'] ?? null, 'teacher' => $mine, 'all_teachers' => $all,
            ];
        }

        $teachable = $periods->where('is_break', false)->count() * count($days);
        $draft = $this->draftSlots($routine);

        return [
            'class' => ['id' => $class->id, 'label' => $class->class_label, 'section' => $class->section_label, 'full_label' => $class->full_label, 'year' => $class->academic_year,
                        'shift' => $class->shift_name, 'medium' => $class->medium_name, 'group' => $class->group_name, 'class_teacher' => $class->class_teacher ?: null],
            'days' => array_map(fn ($d) => ['id' => $d] + self::DAYS[$d], $days),
            'periods' => $periods->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'start' => $p->start, 'end' => $p->end, 'time' => RoutinePeriod::clock($p->start) . ' – ' . RoutinePeriod::clock($p->end), 'is_break' => $p->is_break])->values()->all(),
            'cells' => $cells,
            'subjects' => $subjectList,
            'plan_check' => $published ? ['over' => [], 'under' => []] : $this->planCheck($class, $cells),
            'period_source' => $this->periodSource($class),
            'published' => $routine->is_published,
            'published_at' => $routine->published_at?->format('d M Y, h:i A'),
            'changed' => $routine->is_published && $this->normalize($draft) !== $this->normalize(collect($routine->published_slots ?? [])),
            'filled' => count(array_filter(array_keys($cells), fn ($k) => $this->cellIsTeachable($k, $periods, $days))),
            'total' => $teachable,
            'notes' => $routine->notes,
        ];
    }

    private function cellIsTeachable(string $key, Collection $periods, array $days): bool
    {
        [$day, $pid] = array_map('intval', explode('-', $key));

        return in_array($day, $days, true) && $periods->contains(fn ($p) => $p->id === $pid && ! $p->is_break);
    }

    /** the draft as plain rows */
    private function draftSlots(ClassRoutine $routine): Collection
    {
        return $routine->slots()->get(['day', 'period_id', 'subject_id', 'label', 'teacher_id'])->map(fn ($s) => [
            'day' => (int) $s->day, 'period_id' => (int) $s->period_id, 'subject_id' => $s->subject_id ? (int) $s->subject_id : null,
            'label' => $s->label, 'teacher_id' => $s->teacher_id ? (int) $s->teacher_id : null,
        ]);
    }

    private function normalize(Collection $slots): array
    {
        return $slots->map(fn ($s) => [(int) $s['day'], (int) $s['period_id'], $s['subject_id'] ? (int) $s['subject_id'] : null, $s['label'] ?: null, ($s['teacher_id'] ?? null) ? (int) $s['teacher_id'] : null])
            ->sortBy(fn ($r) => sprintf('%d-%08d', $r[0], $r[1]))->values()->all();
    }

    // ------------------------------------------------------------------
    // editing a cell
    // ------------------------------------------------------------------

    /**
     * Put a subject (or a label like "Games") and a teacher into a cell; with neither, the cell is emptied.
     *
     * @throws ValidationException  with a message fit to show: not a working day, not a period, teacher already busy …
     */
    public function setCell(SchoolClass $class, int $day, int $periodId, ?string $subject, ?string $label, ?int $teacherId): void
    {
        $routine = $this->routineFor($class);
        $period = $this->periodsForClass($class)->firstWhere('id', $periodId);

        if (! $period || $period->is_break) {
            throw ValidationException::withMessages(['cell' => 'That is not a teaching period of this class.']);
        }

        if (! in_array($day, $this->workingDays(), true)) {
            throw ValidationException::withMessages(['cell' => 'That is not a working day.']);
        }

        $subject = $subject !== null && trim($subject) !== '' ? trim($subject) : null;
        $label = $label !== null && trim($label) !== '' ? mb_substr(trim($label), 0, 60) : null;

        if (! $subject && ! $label) {
            ClassRoutineSlot::where('class_routine_id', $routine->id)->where('day', $day)->where('period_id', $periodId)->delete();

            return;
        }

        $subjectId = null;

        if ($subject) {
            $known = collect($this->subjectsOf($class))->firstWhere('name', $subject);

            if (! $known) {
                throw ValidationException::withMessages(['subject' => "\"{$subject}\" is not a subject of this class."]);
            }

            $subjectId = $known['subject_id'];
            $label = $subjectId ? null : $subject;           // a subject with no record of its own is kept by name
        }

        if ($subject) {
            $this->assertWithinPlan($class, $routine, $subject, $subjectId, $day, $periodId);
        }

        if ($teacherId !== null && ! User::where('id', $teacherId)->where('role', User::ROLE_TEACHER)->where('status', User::STATUS_ACTIVE)->exists()) {
            throw ValidationException::withMessages(['teacher_id' => 'Choose an active teacher.']);
        }

        if ($teacherId !== null && ($clash = $this->clash($teacherId, $day, $period, $routine->id))) {
            throw ValidationException::withMessages(['teacher_id' => "{$clash['teacher']} already teaches {$clash['class']} on " . self::DAYS[$day]['en'] . " at {$clash['time']}."]);
        }

        ClassRoutineSlot::updateOrCreate(
            ['class_routine_id' => $routine->id, 'day' => $day, 'period_id' => $periodId],
            ['subject_id' => $subjectId, 'label' => $label, 'teacher_id' => $teacherId],
        );
    }

    /**
     * Is this teacher already teaching at a time that overlaps this period on this day (in any other class, any shift)?
     *
     * @return array{teacher:string, class:string, time:string}|null
     */
    public function clash(int $teacherId, int $day, RoutinePeriod $period, ?int $exceptRoutineId = null): ?array
    {
        $row = ClassRoutineSlot::query()
            ->join('routine_periods as p', 'p.id', '=', 'class_routine_slots.period_id')
            ->join('class_routines as r', 'r.id', '=', 'class_routine_slots.class_routine_id')
            ->join('classes as c', 'c.id', '=', 'r.class_id')
            ->join('users as u', 'u.id', '=', 'class_routine_slots.teacher_id')
            ->where('class_routine_slots.teacher_id', $teacherId)
            ->where('class_routine_slots.day', $day)
            ->where('p.is_break', false)
            ->where('p.starts_at', '<', $period->ends_at)
            ->where('p.ends_at', '>', $period->starts_at)
            ->when($exceptRoutineId, fn ($q) => $q->where('class_routine_slots.class_routine_id', '!=', $exceptRoutineId))
            ->select('c.id as class_id', 'u.name as teacher', 'p.starts_at', 'p.ends_at')
            ->first();

        if (! $row) {
            return null;
        }

        $class = SchoolClass::find($row->class_id);

        return ['teacher' => $row->teacher, 'class' => $class?->short_label ?? 'another class',
                'time' => RoutinePeriod::clock(substr($row->starts_at, 0, 5)) . ' – ' . RoutinePeriod::clock(substr($row->ends_at, 0, 5))];
    }

    /**
     * Copy one day's cells onto other days of the same class.
     *
     * @param  array<int,int>  $toDays
     * @return array{copied:int, skipped:array<int,string>}
     */
    public function copyDay(SchoolClass $class, int $fromDay, array $toDays): array
    {
        $routine = $this->routineFor($class);
        $source = $routine->slots()->where('day', $fromDay)->get();
        $copied = 0;
        $skipped = [];

        foreach (array_unique(array_map('intval', $toDays)) as $to) {
            if ($to === $fromDay || ! in_array($to, $this->workingDays(), true)) {
                continue;
            }

            // the day is being replaced: clear it first, so the periods it had do not count against the plan
            ClassRoutineSlot::where('class_routine_id', $routine->id)->where('day', $to)->delete();

            foreach ($this->periodsForClass($class)->where('is_break', false) as $p) {
                $s = $source->firstWhere('period_id', $p->id);

                try {
                    $this->setCell($class, $to, $p->id, $s?->subject_id ? Subject::find($s->subject_id)?->name : ($s?->label), $s?->subject_id ? null : $s?->label, $s?->teacher_id);
                    $s && $copied++;
                } catch (ValidationException $e) {
                    $skipped[] = self::DAYS[$to]['en'] . ' ' . $p->name . ': ' . collect($e->errors())->flatten()->first();
                }
            }
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Start this class from another class's routine (typically a sister section). Cells are matched by position (the Nth
     * teaching period of the day), subjects this class does not have are left out, and each subject's teacher is THIS
     * class's own subject teacher (sections often have different ones).
     *
     * @return array{copied:int, skipped:array<int,string>}
     */
    public function copyFromClass(SchoolClass $class, SchoolClass $from, bool $published = false): array
    {
        if ($class->id === $from->id) {
            throw ValidationException::withMessages(['from' => 'Choose a different class to copy from.']);
        }

        $mine = $this->periodsForClass($class)->where('is_break', false)->values();
        $theirs = $this->periodsForClass($from)->where('is_break', false)->values();
        $source = $published ? collect($this->routineFor($from)->published_slots ?? []) : $this->draftSlots($this->routineFor($from));
        $subjectNames = Subject::whereIn('id', $source->pluck('subject_id')->filter()->unique())->pluck('name', 'id');
        $mySubjects = collect($this->subjectsOf($class))->keyBy('name');

        $routine = $this->routineFor($class);
        $copied = 0;
        $skipped = [];

        DB::transaction(function () use ($class, $routine, $mine, $theirs, $source, $subjectNames, $mySubjects, &$copied, &$skipped) {
            ClassRoutineSlot::where('class_routine_id', $routine->id)->delete();

            foreach ($source as $s) {
                $i = $theirs->search(fn ($p) => $p->id === $s['period_id']);
                $target = $i === false ? null : $mine->get($i);

                if (! $target || ! in_array($s['day'], $this->workingDays(), true)) {
                    continue;
                }

                $name = $s['subject_id'] ? ($subjectNames[$s['subject_id']] ?? null) : null;

                if ($name && ! $mySubjects->has($name)) {
                    $skipped[] = self::DAYS[$s['day']]['en'] . ' ' . $target->name . ": {$name} is not a subject of this class";
                    continue;
                }

                try {
                    $this->setCell($class, $s['day'], $target->id, $name, $name ? null : $s['label'], $name ? ($mySubjects[$name]['teacher_id'] ?? null) : null);
                    $copied++;
                } catch (ValidationException $e) {
                    $skipped[] = self::DAYS[$s['day']]['en'] . ' ' . $target->name . ': ' . collect($e->errors())->flatten()->first();
                }
            }
        });

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /** empty the whole draft */
    public function clearAll(SchoolClass $class): void
    {
        ClassRoutineSlot::where('class_routine_id', $this->routineFor($class)->id)->delete();
    }


    /**
     * A teacher stopped teaching $subject in this class: take them off its periods in the routine. Each such period goes to
     * another teacher of the subject who is free at that time, or to nobody when there is none (the editor then shows
     * "no teacher"). Done in the draft and in the published copy, so the routine never names someone who no longer teaches it.
     *
     * @param  array<int,int>  $removed  user ids
     * @return array{changed:int, moved:array<string,int>, without:array<int,string>}  periods changed (draft and published), who took the draft's periods, and the draft's periods left without a teacher
     */
    public function teacherLeftSubject(SchoolClass $class, string $subject, array $removed): array
    {
        $routine = ClassRoutine::where('class_id', $class->id)->first();
        $result = ['changed' => 0, 'moved' => [], 'without' => []];

        if (! $routine) {
            return $result;
        }

        $subjectId = Subject::where('name', $subject)->value('id');
        $remaining = $class->currentSubjectTeacherLists()->get($subject, collect())->pluck('user_id')->filter()->map(fn ($i) => (int) $i)->values()->all();
        $periods = $this->periodsForClass($class)->keyBy('id');
        $changed = 0;
        $names = User::whereIn('id', $remaining)->pluck('name', 'id');

        $replacement = function (int $day, int $periodId) use ($remaining, $periods, $routine): ?int {
            $period = $periods[$periodId] ?? null;

            foreach ($remaining as $t) {
                if ($period && ! $this->clash($t, $day, $period, $routine->id)) {
                    return $t;
                }
            }

            return null;
        };

        $isThisSubject = fn ($s) => ($subjectId && (int) ($s['subject_id'] ?? 0) === (int) $subjectId) || (($s['label'] ?? null) === $subject);

        DB::transaction(function () use ($routine, $removed, $subjectId, $subject, $replacement, &$changed, $isThisSubject, &$result, $names, $periods) {
            $slots = $routine->slots()->whereIn('teacher_id', $removed)->where(function ($q) use ($subjectId, $subject) {
                $q->where('label', $subject);
                $subjectId && $q->orWhere('subject_id', $subjectId);
            })->get();

            foreach ($slots as $slot) {
                $to = $replacement((int) $slot->day, (int) $slot->period_id);
                $slot->update(['teacher_id' => $to]);
                $changed++;

                if ($to) {
                    $result['moved'][$names[$to] ?? 'another teacher'] = ($result['moved'][$names[$to] ?? 'another teacher'] ?? 0) + 1;
                } else {
                    $result['without'][] = self::DAYS[(int) $slot->day]['en'] . ' ' . ($periods[$slot->period_id]->name ?? '');
                }
            }

            if ($routine->published_slots) {
                $snapshot = $routine->published_slots;
                $touched = false;

                foreach ($snapshot as $i => $s) {
                    if (in_array((int) ($s['teacher_id'] ?? 0), $removed, true) && $isThisSubject($s)) {
                        $snapshot[$i]['teacher_id'] = $replacement((int) $s['day'], (int) $s['period_id']);
                        $touched = true;
                        $changed++;
                    }
                }

                if ($touched) {
                    $routine->timestamps = false;              // a correction, not an edit waiting to be published
                    $routine->update(['published_slots' => array_values($snapshot)]);
                }
            }
        });

        $result['changed'] = $changed;

        return $result;
    }

    // ------------------------------------------------------------------
    // publishing
    // ------------------------------------------------------------------

    public function publish(SchoolClass $class, User $by): ClassRoutine
    {
        $routine = $this->routineFor($class);
        $slots = $this->draftSlots($routine);

        if ($slots->isEmpty()) {
            throw ValidationException::withMessages(['publish' => 'The routine is empty: fill in some periods before publishing it.']);
        }

        $routine->update(['published_slots' => $slots->values()->all(), 'published_at' => now(), 'published_by' => $by->id]);

        return $routine;
    }

    public function unpublish(SchoolClass $class): void
    {
        $this->routineFor($class)->update(['published_slots' => null, 'published_at' => null, 'published_by' => null]);
    }

    // ------------------------------------------------------------------
    // teachers
    // ------------------------------------------------------------------

    /**
     * One teacher's week across every class they teach: days down, distinct clock times across.
     *
     * @param  bool  $published  true = only what has been published, false = the drafts as they stand
     */
    public function teacherGrid(int $teacherId, bool $published): array
    {
        $teacher = User::find($teacherId);
        $days = $this->workingDays();
        $rows = [];                     // [day, period_id, class_id, subject_id, label]

        if ($published) {
            foreach (ClassRoutine::whereNotNull('published_at')->get(['class_id', 'published_slots']) as $r) {
                foreach ($r->published_slots ?? [] as $s) {
                    if (($s['teacher_id'] ?? null) === $teacherId) {
                        $rows[] = [$s['day'], $s['period_id'], $r->class_id, $s['subject_id'], $s['label'] ?? null];
                    }
                }
            }
        } else {
            foreach (ClassRoutineSlot::join('class_routines as r', 'r.id', '=', 'class_routine_slots.class_routine_id')->where('class_routine_slots.teacher_id', $teacherId)
                ->get(['class_routine_slots.day', 'class_routine_slots.period_id', 'r.class_id', 'class_routine_slots.subject_id', 'class_routine_slots.label']) as $s) {
                $rows[] = [(int) $s->day, (int) $s->period_id, (int) $s->class_id, $s->subject_id, $s->label];
            }
        }

        $periods = RoutinePeriod::whereIn('id', array_unique(array_column($rows, 1)))->get()->keyBy('id');
        $classes = SchoolClass::whereIn('id', array_unique(array_column($rows, 2)))->get()->keyBy('id');
        $subjects = Subject::whereIn('id', array_filter(array_unique(array_column($rows, 3))))->pluck('name', 'id');

        // the columns are the distinct clock times the teacher has a class at
        $columns = $periods->map(fn ($p) => $p->start . '-' . $p->end)->unique()->sort()->values()->map(function ($k) {
            [$a, $b] = explode('-', $k);

            return ['key' => $k, 'start' => $a, 'end' => $b, 'time' => RoutinePeriod::clock($a) . ' – ' . RoutinePeriod::clock($b)];
        })->all();

        $cells = [];

        foreach ($rows as [$day, $pid, $cid, $sid, $label]) {
            $p = $periods[$pid] ?? null;
            $c = $classes[$cid] ?? null;

            if (! $p || ! $c) {
                continue;
            }

            $cells[$day . '|' . $p->start . '-' . $p->end][] = ['class' => $c->short_label, 'subject' => ($sid ? ($subjects[$sid] ?? null) : null) ?? $label];
        }

        return [
            'teacher' => $teacher ? ['id' => $teacher->id, 'name' => $teacher->name] : null,
            'days' => array_map(fn ($d) => ['id' => $d] + self::DAYS[$d], $days),
            'columns' => $columns,
            'cells' => $cells,
            'periods_per_week' => count($rows),
        ];
    }
}
