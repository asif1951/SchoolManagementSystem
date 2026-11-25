<?php

namespace Tests\Feature;

use App\Models\ClassRoutine;
use App\Models\ClassRoutineSlot;
use App\Models\RoutinePeriod;
use App\Models\RoutinePlan;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\RoutineGenerator;
use App\Services\RoutineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

/** The automatic routine, class-specific periods and breaks, and the weekly plan. */
class RoutineAutoTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private RoutineService $svc;
    private RoutineGenerator $gen;
    private array $t = [];       // teachers by name

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(RoutineService::class);
        $this->gen = app(RoutineGenerator::class);
        $this->admin = $this->makeUser(User::ROLE_ADMIN);

        foreach (['math', 'eng', 'ban', 'sci', 'ict'] as $n) {
            $this->t[$n] = $this->makeUser(User::ROLE_TEACHER);
        }

        // six 40-minute periods, 10:00–14:00, with a break in the middle
        foreach ([['1st', '10:00', '10:40'], ['2nd', '10:40', '11:20'], ['3rd', '11:20', '12:00'], ['Break', '12:00', '12:30', true], ['4th', '12:30', '13:10'], ['5th', '13:10', '13:50'], ['6th', '13:50', '14:30']] as $i => $p) {
            RoutinePeriod::create(['name' => $p[0], 'starts_at' => $p[1], 'ends_at' => $p[2], 'is_break' => $p[3] ?? false, 'sort_order' => $i + 1]);
        }
    }

    /** a section whose five subjects each have a teacher; the same teachers serve every section (the hard case) */
    private function section(string $name, string $sec, array $plan = ['Math' => [5, 2], 'English' => [5, 2], 'Bangla' => [5, 2], 'Science' => [5, 2], 'ICT' => [5, 2]]): SchoolClass
    {
        $c = $this->makeClass($name, $sec, null, ['subjects' => ['Math', 'English', 'Bangla', 'Science', 'ICT']], $this->t['math']);

        foreach (['Math' => 'math', 'English' => 'eng', 'Bangla' => 'ban', 'Science' => 'sci', 'ICT' => 'ict'] as $subject => $key) {
            $c->assignSubjectTeacher($subject, $this->t[$key]);
        }

        $this->svc->savePlan($c, collect($plan)->map(fn ($v, $k) => ['subject' => $k, 'periods_per_week' => $v[0], 'max_per_day' => $v[1]])->values()->all());

        return $c->fresh();
    }

    /** every teacher's periods, from the database, as [teacher => [[day, from, to, class]]] */
    private function load(): array
    {
        $out = [];

        foreach (ClassRoutineSlot::query()->join('routine_periods as p', 'p.id', '=', 'class_routine_slots.period_id')->join('class_routines as r', 'r.id', '=', 'class_routine_slots.class_routine_id')
            ->whereNotNull('class_routine_slots.teacher_id')->get(['class_routine_slots.teacher_id as t', 'class_routine_slots.day as d', 'p.starts_at as a', 'p.ends_at as b', 'r.class_id as c']) as $s) {
            $out[$s->t][] = [(int) $s->d, substr($s->a, 0, 5), substr($s->b, 0, 5), $s->c];
        }

        return $out;
    }

    private function assertNoTeacherIsInTwoPlaces(): void
    {
        foreach ($this->load() as $teacher => $slots) {
            foreach ($slots as $i => $x) {
                foreach ($slots as $j => $y) {
                    if ($i < $j && $x[0] === $y[0] && $x[1] < $y[2] && $y[1] < $x[2]) {
                        $this->fail("teacher {$teacher} is in two classes on day {$x[0]} at {$x[1]}–{$x[2]} and {$y[1]}–{$y[2]}");
                    }
                }
            }
        }

        $this->assertTrue(true);
    }

    private function counts(SchoolClass $c): array
    {
        $g = $this->svc->grid($c);
        $by = [];

        foreach ($g['cells'] as $key => $cell) {
            [$day] = explode('-', $key);
            $by[$cell['subject']]['n'] = ($by[$cell['subject']]['n'] ?? 0) + 1;
            $by[$cell['subject']]['days'][$day] = ($by[$cell['subject']]['days'][$day] ?? 0) + 1;
        }

        return $by;
    }

    // ---- the plan ------------------------------------------------------------------------------------------------

    public function test_a_plan_is_suggested_then_saved_and_copied_to_the_sections(): void
    {
        $a = $this->makeClass('Class 7', 'A', null, ['subjects' => ['Math', 'English']]);
        $b = $this->makeClass('Class 7', 'B', null, ['subjects' => ['Math', 'English']]);

        $plan = $this->svc->planFor($a);
        $this->assertFalse($plan['saved']);
        $this->assertSame(30, $plan['slots']);                                         // 5 days × 6 teaching periods
        $this->assertSame(30, array_sum(array_column($plan['rows'], 'periods_per_week')));

        $this->svc->savePlan($a, [['subject' => 'Math', 'periods_per_week' => 7, 'max_per_day' => 2], ['subject' => 'Physics', 'periods_per_week' => 3, 'max_per_day' => 1]]);
        $this->assertSame(1, RoutinePlan::where('class_id', $a->id)->count());          // a subject the class does not have is ignored

        $this->assertSame(1, $this->svc->copyPlanToSections($a));
        $this->assertSame(7, RoutinePlan::where('class_id', $b->id)->where('subject', 'Math')->value('periods_per_week'));
    }

    // ---- the generator -------------------------------------------------------------------------------------------

    public function test_a_routine_is_made_that_meets_the_plan_and_the_day_limits(): void
    {
        $c = $this->section('Class 6', 'A');

        $r = $this->gen->run(collect([$c]), ['seed' => 11]);
        $this->assertTrue($r['ok']);

        $this->gen->apply($r, collect([$c]), false);

        foreach ($this->counts($c) as $subject => $x) {
            $this->assertSame(5, $x['n'], "{$subject} should have 5 periods");
            $this->assertLessThanOrEqual(2, max($x['days']), "{$subject} has more than 2 in a day");
        }

        $this->assertCount(25, $this->svc->grid($c)['cells']);                              // 5 subjects × 5 periods
    }

    public function test_no_teacher_is_ever_in_two_classes_at_once_even_when_sections_share_all_teachers(): void
    {
        // three sections, five teachers who teach all of them: 3 × 6 = 18 periods for each teacher in a 25-period week
        $classes = collect(['A', 'B', 'C'])->map(fn ($s) => $this->section('Class 6', $s, ['Math' => [5, 1], 'English' => [5, 1], 'Bangla' => [5, 1], 'Science' => [5, 1], 'ICT' => [5, 1]]));

        $r = $this->gen->run($classes, ['seed' => 5]);
        $this->assertTrue($r['ok'], json_encode(collect($r['classes'])->pluck('unplaced')));

        $this->gen->apply($r, $classes, false);
        $this->assertNoTeacherIsInTwoPlaces();

        foreach ($classes as $c) {
            foreach ($this->counts($c) as $x) {
                $this->assertSame(5, $x['n']);
                $this->assertSame(1, max($x['days']));                                   // at most one a day, as planned
            }
        }
    }

    public function test_classes_with_different_bells_still_never_overlap_for_a_teacher(): void
    {
        $a = $this->section('Class 6', 'A', ['Math' => [10, 2], 'English' => [10, 2], 'Bangla' => [5, 1]]);
        $b = $this->section('Class 7', 'A', ['Math' => [10, 2], 'English' => [10, 2], 'Bangla' => [5, 1]]);

        // class B's periods are 45 minutes and start 20 minutes later, so none of its periods lines up with class A's
        $this->svc->saveClassPeriods($b, [
            ['name' => '1st', 'starts_at' => '10:20', 'ends_at' => '11:05'], ['name' => '2nd', 'starts_at' => '11:05', 'ends_at' => '11:50'], ['name' => '3rd', 'starts_at' => '11:50', 'ends_at' => '12:35'],
            ['name' => 'Break', 'starts_at' => '12:35', 'ends_at' => '13:05', 'is_break' => true], ['name' => '4th', 'starts_at' => '13:05', 'ends_at' => '13:50'], ['name' => '5th', 'starts_at' => '13:50', 'ends_at' => '14:35'],
        ]);

        $r = $this->gen->run(collect([$a, $b]), ['seed' => 3]);
        $this->gen->apply($r, collect([$a, $b]), false);

        $this->assertNoTeacherIsInTwoPlaces();
        $this->assertSame(25, array_sum(array_column($this->counts($a), 'n')));
        $this->assertSame(25, array_sum(array_column($this->counts($b), 'n')));
    }

    public function test_a_plan_bigger_than_the_week_is_refused_with_the_numbers(): void
    {
        $c = $this->section('Class 6', 'A', ['Math' => [20, 5], 'English' => [20, 5]]);

        $r = $this->gen->run(collect([$c]), ['seed' => 1]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('40', $r['classes'][$c->id]['error']);
        $this->assertStringContainsString('30', $r['classes'][$c->id]['error']);
        $this->assertSame([], $r['classes'][$c->id]['cells']);
    }

    public function test_an_overloaded_teacher_is_reported_not_double_booked(): void
    {
        // two sections both need the same teacher for 20 periods a week: only 30 exist, so 10 cannot be placed
        $a = $this->section('Class 6', 'A', ['Math' => [20, 5], 'English' => [2, 1]]);
        $b = $this->section('Class 6', 'B', ['Math' => [20, 5], 'English' => [2, 1]]);

        $r = $this->gen->run(collect([$a, $b]), ['seed' => 9]);
        $this->assertFalse($r['ok']);

        $missing = collect($r['classes'])->flatMap(fn ($c) => $c['unplaced'])->where('subject', 'Math')->sum('missing');
        $this->assertSame(10, $missing);
        $this->assertStringContainsString('already teaching', collect($r['classes'])->flatMap(fn ($c) => $c['unplaced'])->first()['why']);

        $this->gen->apply($r, collect([$a, $b]), false);
        $this->assertNoTeacherIsInTwoPlaces();
    }

    public function test_periods_already_placed_can_be_kept(): void
    {
        $c = $this->section('Class 6', 'A', ['Math' => [3, 1], 'English' => [3, 1]]);
        $p = $this->svc->periodsForClass($c)->where('is_break', false)->values();

        $this->svc->setCell($c, 0, $p[0]->id, 'Math', null, $this->t['math']->id);                // placed by hand

        $r = $this->gen->run(collect([$c]), ['seed' => 2, 'keep_existing' => true]);
        $this->gen->apply($r, collect([$c]), true);

        $this->assertSame('Math', $this->svc->grid($c)['cells']["0-{$p[0]->id}"]['subject']);      // still there
        $this->assertSame(3, $this->counts($c)['Math']['n']);                                        // 1 kept + 2 new
        $this->assertSame(3, $this->counts($c)['English']['n']);
    }

    public function test_it_works_around_routines_outside_the_run(): void
    {
        $a = $this->section('Class 6', 'A', ['Math' => [5, 1], 'English' => [5, 1]]);
        $b = $this->section('Class 6', 'B', ['Math' => [5, 1], 'English' => [5, 1]]);

        $this->gen->apply($this->gen->run(collect([$a]), ['seed' => 4]), collect([$a]), false);      // A is made first
        $this->gen->apply($this->gen->run(collect([$b]), ['seed' => 4]), collect([$b]), false);      // B is made later, around A

        $this->assertNoTeacherIsInTwoPlaces();
        $this->assertSame(5, $this->counts($b)['Math']['n']);
    }

    public function test_the_same_seed_gives_the_same_routine_and_another_seed_a_different_one(): void
    {
        $c = $this->section('Class 6', 'A');

        $x = $this->gen->run(collect([$c]), ['seed' => 100])['classes'][$c->id]['cells'];
        $y = $this->gen->run(collect([$c]), ['seed' => 100])['classes'][$c->id]['cells'];
        $z = $this->gen->run(collect([$c]), ['seed' => 777])['classes'][$c->id]['cells'];

        $this->assertSame($x, $y);
        $this->assertNotSame($x, $z);
    }

    public function test_a_teachers_daily_limit_is_respected(): void
    {
        $a = $this->section('Class 6', 'A', ['Math' => [10, 2], 'English' => [5, 1]]);
        $r = $this->gen->run(collect([$a]), ['seed' => 6, 'teacher_max_per_day' => 2]);
        $this->gen->apply($r, collect([$a]), false);

        $perDay = [];
        foreach ($this->load()[$this->t['math']->id] ?? [] as $s) {
            $perDay[$s[0]] = ($perDay[$s[0]] ?? 0) + 1;
        }

        $this->assertLessThanOrEqual(2, max($perDay));
        $this->assertSame(10, array_sum($perDay));
    }

    // ---- class-specific periods ----------------------------------------------------------------------------------

    public function test_a_class_can_have_its_own_periods_and_the_cells_move_with_their_position(): void
    {
        $c = $this->section('Class 6', 'A', ['Math' => [2, 1]]);
        $common = $this->svc->periodsForClass($c)->where('is_break', false)->values();

        $this->svc->setCell($c, 0, $common[0]->id, 'Math', null, $this->t['math']->id);
        $this->svc->setCell($c, 1, $common[2]->id, 'Math', null, $this->t['math']->id);
        $this->svc->publish($c, $this->admin);
        $this->assertSame('common', $this->svc->periodSource($c));

        $this->svc->saveClassPeriods($c, [
            ['name' => '1st', 'starts_at' => '08:00', 'ends_at' => '08:50'], ['name' => '2nd', 'starts_at' => '08:50', 'ends_at' => '09:40'],
            ['name' => 'Break', 'starts_at' => '09:40', 'ends_at' => '10:20', 'is_break' => true], ['name' => '3rd', 'starts_at' => '10:20', 'ends_at' => '11:10'],
        ]);

        $this->assertSame('class', $this->svc->periodSource($c));
        $own = $this->svc->periodsForClass($c)->where('is_break', false)->values();
        $this->assertCount(3, $own);

        $draft = $this->svc->grid($c)['cells'];
        $this->assertSame('Math', $draft["0-{$own[0]->id}"]['subject']);          // the 1st teaching period is still the 1st
        $this->assertSame('Math', $draft["1-{$own[2]->id}"]['subject']);          // the 3rd is still the 3rd
        $this->assertCount(2, $this->svc->grid($c, true)['cells']);               // and so is the published copy

        $this->svc->resetClassPeriods($c);
        $this->assertSame('common', $this->svc->periodSource($c));
        $back = $this->svc->periodsForClass($c)->where('is_break', false)->values();
        $this->assertSame('Math', $this->svc->grid($c)['cells']["0-{$back[0]->id}"]['subject']);
        $this->assertSame('Math', $this->svc->grid($c)['cells']["1-{$back[2]->id}"]['subject']);
    }

    public function test_cells_in_a_period_that_no_longer_exists_are_dropped_everywhere(): void
    {
        $c = $this->section('Class 6', 'A', ['Math' => [2, 1]]);
        $p = $this->svc->periodsForClass($c)->where('is_break', false)->values();
        $this->svc->setCell($c, 0, $p[4]->id, 'Math', null, $this->t['math']->id);               // the 5th period
        $this->svc->publish($c, $this->admin);

        $this->svc->saveClassPeriods($c, [['name' => '1st', 'starts_at' => '09:00', 'ends_at' => '09:45'], ['name' => '2nd', 'starts_at' => '09:45', 'ends_at' => '10:30']]);   // only two now

        $this->assertSame(0, ClassRoutineSlot::count());
        $this->assertSame([], ClassRoutine::first()->published_slots);
    }

    public function test_a_period_set_must_have_a_teaching_period_and_sensible_times(): void
    {
        $c = $this->section('Class 6', 'A');

        foreach ([[['name' => 'Break', 'starts_at' => '10:00', 'ends_at' => '10:30', 'is_break' => true]], [['name' => 'A', 'starts_at' => '11:00', 'ends_at' => '10:00']]] as $bad) {
            try {
                $this->svc->saveClassPeriods($c, $bad);
                $this->fail('expected a refusal');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('common', $this->svc->periodSource($c));
    }

    public function test_a_classs_periods_can_be_used_for_other_classes(): void
    {
        $a = $this->section('Class 6', 'A');
        $b = $this->section('Class 6', 'B');
        $this->svc->saveClassPeriods($a, [['name' => '1st', 'starts_at' => '07:30', 'ends_at' => '08:15'], ['name' => '2nd', 'starts_at' => '08:15', 'ends_at' => '09:00']]);

        $this->assertSame(1, $this->svc->copyClassPeriods($a, [$a->id, $b->id]));
        $this->assertSame('07:30', $this->svc->periodsForClass($b)->first()->start);
        $this->assertSame('class', $this->svc->periodSource($b));
    }

    // ---- the screens ---------------------------------------------------------------------------------------------

    public function test_the_editor_preview_and_apply_endpoints(): void
    {
        $c = $this->section('Class 6', 'A', ['Math' => [1, 1]]);
        $plan = [['subject' => 'Math', 'periods_per_week' => 6, 'max_per_day' => 2], ['subject' => 'English', 'periods_per_week' => 6, 'max_per_day' => 2], ['subject' => 'Bangla', 'periods_per_week' => 6, 'max_per_day' => 2],
                 ['subject' => 'Science', 'periods_per_week' => 4, 'max_per_day' => 1], ['subject' => 'ICT', 'periods_per_week' => 3, 'max_per_day' => 1]];

        $res = $this->actingAs($this->admin)->postJson(route('routine.auto.preview', $c), ['plan' => $plan, 'seed' => 42])->assertOk();
        $res->assertJsonPath('ok', true)->assertJsonPath('seed', 42)->assertJsonPath('result.placed', 25);
        $this->assertSame(0, ClassRoutineSlot::count());                                           // a preview saves nothing

        $this->actingAs($this->admin)->post(route('routine.auto.apply', $c), ['seed' => 42])->assertSessionHasNoErrors();
        $this->assertSame(25, ClassRoutineSlot::count());
        $this->assertSame(6, RoutinePlan::where('class_id', $c->id)->where('subject', 'Math')->value('periods_per_week'));   // the plan was saved
    }

    public function test_many_classes_are_generated_together_from_the_generate_page(): void
    {
        $classes = collect(['A', 'B'])->map(fn ($s) => $this->section('Class 6', $s, ['Math' => [5, 1], 'English' => [5, 1], 'Bangla' => [5, 1], 'Science' => [5, 1], 'ICT' => [5, 1]]));

        $this->actingAs($this->admin)->get(route('routine.generate', ['session' => (string) $classes[0]->academic_year]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Routine/Generate')->has('classes', 2)->where('classes.0.planned', 25)->where('classes.0.capacity', 30));

        $ids = $classes->pluck('id')->all();
        $this->actingAs($this->admin)->postJson(route('routine.generate.preview'), ['class_ids' => $ids, 'seed' => 8])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(0, ClassRoutineSlot::count());

        $this->actingAs($this->admin)->post(route('routine.generate.apply'), ['class_ids' => $ids, 'seed' => 8])->assertSessionHasNoErrors();
        $this->assertSame(50, ClassRoutineSlot::count());
        $this->assertNoTeacherIsInTwoPlaces();
    }

    public function test_only_those_who_manage_can_generate_or_change_periods(): void
    {
        $c = $this->section('Class 6', 'A');

        $this->actingAs($this->t['math'])->get(route('routine.generate'))->assertForbidden();
        $this->actingAs($this->t['math'])->postJson(route('routine.auto.preview', $c), ['plan' => [['subject' => 'Math', 'periods_per_week' => 1]]])->assertForbidden();
        $this->actingAs($this->t['math'])->put(route('routine.class-periods.save', $c), ['periods' => [['name' => '1st', 'starts_at' => '09:00', 'ends_at' => '09:40']]])->assertForbidden();
        $this->actingAs($this->t['math'])->put(route('routine.plan.save', $c), ['plan' => [['subject' => 'Math', 'periods_per_week' => 1]]])->assertForbidden();
    }

    public function test_the_editor_receives_the_plan_and_where_its_periods_come_from(): void
    {
        $c = $this->section('Class 6', 'A');

        $this->actingAs($this->admin)->get(route('routine.editor', $c))
            ->assertInertia(fn ($p) => $p->has('plan.rows', 5)->where('plan.saved', true)->where('grid.period_source', 'common'));

        $this->actingAs($this->admin)->put(route('routine.class-periods.save', $c), ['periods' => [['name' => '1st', 'starts_at' => '09:00', 'ends_at' => '09:40'], ['name' => '2nd', 'starts_at' => '09:40', 'ends_at' => '10:20']]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('routine.editor', $c))->assertInertia(fn ($p) => $p->where('grid.period_source', 'class')->has('grid.periods', 2));

        $this->actingAs($this->admin)->delete(route('routine.class-periods.reset', $c))->assertSessionHasNoErrors();
        $this->assertSame('common', $this->svc->periodSource($c));
    }

    public function test_a_realistic_school_is_scheduled_completely_with_every_period_full(): void
    {
        $subjects = ['Bangla', 'English', 'Math', 'Science', 'Social Science', 'Religion', 'ICT', 'Art'];
        $counts = [5, 5, 4, 4, 4, 3, 3, 2];                                           // 30 = every period of the week
        $teachers = [];
        $classes = collect();

        foreach (['Class 6', 'Class 7', 'Class 8', 'Class 9'] as $level) {
            foreach ($subjects as $sub) {
                $teachers[$level][$sub] = $this->makeUser(User::ROLE_TEACHER);        // one teacher per subject per level, teaching all 3 sections
            }

            foreach (['A', 'B', 'C'] as $sec) {
                $c = $this->makeClass($level, $sec, null, ['subjects' => $subjects], $teachers[$level]['Math']);

                foreach ($subjects as $sub) {
                    $c->assignSubjectTeacher($sub, $teachers[$level][$sub]);
                }

                $this->svc->savePlan($c, collect($subjects)->map(fn ($n, $i) => ['subject' => $n, 'periods_per_week' => $counts[$i], 'max_per_day' => 2])->all());
                $classes->push($c->fresh());
            }
        }

        $t = microtime(true);
        $r = $this->gen->run($classes, ['seed' => 21, 'seconds' => 20]);
        $took = microtime(true) - $t;

        $missing = collect($r['classes'])->flatMap(fn ($c) => $c['unplaced'])->sum('missing');
        $this->assertSame(0, $missing, 'periods left out: ' . json_encode(collect($r['classes'])->pluck('unplaced')->filter()->all()));
        $this->assertLessThan(20, $took, "took {$took}s");

        $this->gen->apply($r, $classes, false);
        $this->assertNoTeacherIsInTwoPlaces();
        $this->assertSame(12 * 30, ClassRoutineSlot::count());

        foreach ($classes as $c) {
            foreach ($this->counts($c) as $subject => $x) {
                $this->assertLessThanOrEqual(2, max($x['days']), "{$subject} twice-plus a day in {$c->full_label}");
            }
        }
    }

    public function test_one_teacher_teaching_the_same_subject_in_two_classes_is_never_double_booked(): void
    {
        // Mr Shadhon teaches Bangla in Class 8 and in Class 9
        $shadhon = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Mr Shadhon']);
        $eight = $this->makeClass('Class 8', 'A', null, ['subjects' => ['Bangla', 'Math']]);
        $nine = $this->makeClass('Class 9', 'A', null, ['subjects' => ['Bangla', 'Math']]);
        $eight->assignSubjectTeacher('Bangla', $shadhon);
        $nine->assignSubjectTeacher('Bangla', $shadhon);
        $first = $this->svc->periodsForClass($eight)->where('is_break', false)->values();

        // by hand: Sunday's 1st period in Class 8, then the same time in Class 9 is refused, with the reason
        $this->svc->setCell($eight, 0, $first[0]->id, 'Bangla', null, $shadhon->id);

        try {
            $this->svc->setCell($nine, 0, $first[0]->id, 'Bangla', null, $shadhon->id);
            $this->fail('Mr Shadhon was put in two classes at once');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Mr Shadhon already teaches Class 8 A on Sunday', $e->errors()['teacher_id'][0]);
        }

        $this->svc->setCell($nine, 0, $first[1]->id, 'Bangla', null, $shadhon->id);          // the next period is free: fine
        $this->svc->clearAll($eight);
        $this->svc->clearAll($nine);

        // automatically: six Bangla periods in each class, all with Mr Shadhon
        foreach ([$eight, $nine] as $c) {
            $this->svc->savePlan($c, [['subject' => 'Bangla', 'periods_per_week' => 6, 'max_per_day' => 2], ['subject' => 'Math', 'periods_per_week' => 6, 'max_per_day' => 2]]);
        }

        $r = $this->gen->run(collect([$eight, $nine]), ['seed' => 14]);
        $this->gen->apply($r, collect([$eight, $nine]), false);

        $this->assertNoTeacherIsInTwoPlaces();
        $this->assertSame(12, count($this->load()[$shadhon->id]));                            // all 12 Bangla periods placed, none overlapping
    }
}
