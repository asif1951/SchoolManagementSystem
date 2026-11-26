<?php

namespace Tests\Feature;

use App\Models\ClassRoutineSlot;
use App\Models\RoutinePeriod;
use App\Models\RoutinePlan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\RoutineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

/** The rules of the routine that must hold on EVERY way of filling it, not just the automatic one. */
class RoutineLogicTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private User $sir;
    private User $madam;
    private SchoolClass $class;
    private RoutineService $svc;
    private $p;      // the teaching periods

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(RoutineService::class);
        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->sir = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Religion Sir']);
        $this->madam = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Math Madam']);

        foreach ([['1st', '10:00', '10:40'], ['2nd', '10:40', '11:20'], ['3rd', '11:20', '12:00'], ['4th', '12:00', '12:40']] as $i => $x) {
            RoutinePeriod::create(['name' => $x[0], 'starts_at' => $x[1], 'ends_at' => $x[2], 'sort_order' => $i + 1]);
        }

        $this->class = $this->makeClass('Class 9', 'B', null, ['subjects' => ['Religion', 'Math', 'Art']]);
        $this->class->assignSubjectTeacher('Religion', $this->sir);
        $this->class->assignSubjectTeacher('Math', $this->madam);
        $this->p = $this->svc->periodsForClass($this->class)->where('is_break', false)->values();

        // Religion: 4 a week, at most 1 a day. Math: 5 a week, up to 2 a day. Art is not in the plan.
        $this->svc->savePlan($this->class, [['subject' => 'Religion', 'periods_per_week' => 4, 'max_per_day' => 1], ['subject' => 'Math', 'periods_per_week' => 5, 'max_per_day' => 2]]);
    }

    private function place(string $subject, int $day, int $period, ?User $t = null): void
    {
        $this->svc->setCell($this->class, $day, $this->p[$period]->id, $subject, null, $t?->id);
    }

    private function refused(callable $fn, string $contains): void
    {
        try {
            $fn();
            $this->fail('this should have been refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($contains, collect($e->errors())->flatten()->first());
        }
    }

    // ---- the plan is a rule for manual placing too -----------------------------------------------------------------

    public function test_a_second_period_of_a_subject_on_one_day_is_refused_when_the_plan_says_one_a_day(): void
    {
        $this->place('Religion', 0, 0, $this->sir);                                   // Sunday, 1st

        $this->refused(fn () => $this->place('Religion', 0, 1, $this->sir), 'limited to 1 a day');     // Sunday, 2nd: the case you reported

        $this->place('Religion', 1, 1, $this->sir);                                   // another day is fine
        $this->assertSame(2, ClassRoutineSlot::count());
    }

    public function test_a_subject_cannot_pass_its_weekly_number(): void
    {
        foreach ([0, 1, 2, 3] as $day) {
            $this->place('Religion', $day, 0, $this->sir);
        }

        $this->refused(fn () => $this->place('Religion', 4, 0, $this->sir), 'all 4 periods of the week');
        $this->assertSame(4, ClassRoutineSlot::count());
    }

    public function test_the_day_limit_can_be_more_than_one_when_the_plan_says_so(): void
    {
        $this->place('Math', 0, 0, $this->madam);
        $this->place('Math', 0, 1, $this->madam);                                     // 2 a day is allowed for Math

        $this->refused(fn () => $this->place('Math', 0, 2, $this->madam), 'limited to 2 a day');
    }

    public function test_replacing_a_cell_with_the_same_subject_is_not_counted_twice(): void
    {
        $this->place('Religion', 0, 0, $this->sir);
        $this->place('Religion', 0, 0, $this->sir);                                   // the same cell again: no error

        $this->assertSame(1, ClassRoutineSlot::count());

        // moving a subject into the cell that held something else is also fine
        $this->place('Math', 1, 0, $this->madam);
        $this->place('Religion', 1, 0, $this->sir);
        $this->assertSame('Religion', $this->svc->grid($this->class)['cells']["1-{$this->p[0]->id}"]['subject']);
    }

    public function test_a_subject_the_plan_does_not_mention_is_free(): void
    {
        foreach ([0, 0, 1, 1, 2] as $i => $day) {
            $this->place('Art', $day, $i % 2);                                        // Art is in no plan: placed as often as asked
        }

        $this->assertSame(5, ClassRoutineSlot::count());
    }

    public function test_a_plan_of_zero_for_a_subject_does_not_restrict_it(): void
    {
        RoutinePlan::where('subject', 'Religion')->update(['periods_per_week' => 0]);

        $this->place('Religion', 0, 0, $this->sir);
        $this->place('Religion', 0, 1, $this->sir);
        $this->assertSame(2, ClassRoutineSlot::count());
    }

    public function test_copying_a_day_obeys_the_plan_and_replaces_the_target_day(): void
    {
        $this->place('Religion', 0, 0, $this->sir);
        $this->place('Math', 0, 1, $this->madam);
        $this->place('Math', 1, 2, $this->madam);                                     // Monday has an old Math period

        $r = $this->svc->copyDay($this->class, 0, [1]);                               // Sunday over Monday

        $monday = collect($this->svc->grid($this->class)['cells'])->filter(fn ($c, $k) => str_starts_with($k, '1-'));
        $this->assertSame(['Religion', 'Math'], $monday->pluck('subject')->values()->all());      // Monday now matches Sunday; the old period is gone
        $this->assertSame(0, count($r['skipped']));
    }

    public function test_copying_a_day_that_would_break_the_weekly_plan_skips_and_says_so(): void
    {
        foreach ([0, 1, 2] as $day) {
            $this->place('Religion', $day, 0, $this->sir);                            // 3 of the 4 Religion periods
        }

        $r = $this->svc->copyDay($this->class, 0, [3, 4]);                            // copying Sunday would make 5

        $this->assertCount(1, $r['skipped']);
        $this->assertStringContainsString('all 4 periods', $r['skipped'][0]);
        $this->assertSame(4, ClassRoutineSlot::where('subject_id', Subject::where('name', 'Religion')->value('id'))->count());
    }

    public function test_the_editor_says_where_the_routine_disagrees_with_its_plan(): void
    {
        $this->place('Religion', 0, 0, $this->sir);
        $this->place('Math', 0, 1, $this->madam);

        // a routine made before the rule existed: two Religion periods on one day, written straight into the table
        $routine = $this->svc->routineFor($this->class);
        ClassRoutineSlot::create(['class_routine_id' => $routine->id, 'day' => 0, 'period_id' => $this->p[1]->id + 100000 > 0 ? $this->p[2]->id : 0, 'subject_id' => Subject::where('name', 'Religion')->value('id'), 'teacher_id' => $this->sir->id]);

        $check = $this->svc->grid($this->class)['plan_check'];

        $this->assertTrue(collect($check['over'])->contains(fn ($x) => str_contains($x, 'Religion is on Sunday 2 times')));
        $this->assertTrue(collect($check['under'])->contains(fn ($x) => str_contains($x, 'more Religion')));
        $this->assertTrue(collect($check['under'])->contains(fn ($x) => str_contains($x, 'more Math')));

        $this->actingAs($this->admin)->get(route('routine.editor', $this->class))->assertInertia(fn (Assert $p) => $p->has('grid.plan_check.over', 1));
    }

    // ---- the other flows ------------------------------------------------------------------------------------------

    public function test_removing_a_subject_from_a_class_removes_its_periods_and_plan_from_the_routine(): void
    {
        $this->place('Religion', 0, 0, $this->sir);
        $this->place('Math', 0, 1, $this->madam);
        $this->svc->publish($this->class, $this->admin);

        $this->class->syncSubjects(['Math', 'Art']);                                   // Religion leaves the class

        $cells = $this->svc->grid($this->class)['cells'];
        $this->assertSame(['Math'], collect($cells)->pluck('subject')->unique()->values()->all());
        $this->assertSame(['Math'], collect($this->svc->grid($this->class, true)['cells'])->pluck('subject')->unique()->values()->all());     // and from the published copy
        $this->assertSame(0, RoutinePlan::where('class_id', $this->class->id)->where('subject', 'Religion')->count());
    }

    public function test_changing_the_bells_so_a_teacher_is_in_two_places_is_found(): void
    {
        $b = $this->makeClass('Class 8', 'A', null, ['subjects' => ['Math']]);
        $b->assignSubjectTeacher('Math', $this->madam);
        $this->svc->savePlan($b, [['subject' => 'Math', 'periods_per_week' => 3, 'max_per_day' => 1]]);

        $this->place('Math', 0, 1, $this->madam);                                     // 9B, Sunday, 10:40–11:20
        $this->assertSame([], $this->svc->teacherClashes());

        // Class 8 gets its own bell whose 1st period overlaps 9B's 2nd
        $this->svc->saveClassPeriods($b, [['name' => '1st', 'starts_at' => '10:50', 'ends_at' => '11:30'], ['name' => '2nd', 'starts_at' => '11:30', 'ends_at' => '12:10']]);
        $firstOfB = $this->svc->periodsForClass($b)->first();
        // (placing it there is refused: the clash is caught at once)
        $this->refused(fn () => $this->svc->setCell($b, 0, $firstOfB->id, 'Math', null, $this->madam->id), 'already teaches');
        $this->assertSame([], $this->svc->teacherClashes());

        // but if the periods are changed AFTER both are placed, the double booking appears and is reported
        $this->svc->saveClassPeriods($b, [['name' => '1st', 'starts_at' => '12:30', 'ends_at' => '13:10'], ['name' => '2nd', 'starts_at' => '13:10', 'ends_at' => '13:50']]);
        $this->svc->setCell($b, 0, $this->svc->periodsForClass($b)->first()->id, 'Math', null, $this->madam->id);
        $this->assertSame([], $this->svc->teacherClashes());

        // changing the periods so that she would be in two places is refused, and nothing is changed
        $this->actingAs($this->admin)->put(route('routine.class-periods.save', $b), ['periods' => [['name' => '1st', 'starts_at' => '10:50', 'ends_at' => '11:30']]])
            ->assertSessionHasErrors('periods');

        $this->assertStringContainsString('cannot be in two classes', session('errors')->first('periods'));
        $this->assertSame('12:30', $this->svc->periodsForClass($b)->first()->start);
        $this->assertSame([], $this->svc->teacherClashes());
    }

    public function test_a_routine_that_would_double_book_the_published_timetable_cannot_be_published(): void
    {
        $b = $this->makeClass('Class 8', 'A', null, ['subjects' => ['Math']]);
        $this->place('Math', 0, 0, $this->madam);
        $this->svc->publish($this->class, $this->admin);

        // 9B's draft moves her away, but its published routine still has her on Sunday 1st
        $this->svc->setCell($this->class, 0, $this->p[0]->id, null, null, null);
        $this->svc->setCell($b, 0, $this->p[0]->id, 'Math', null, $this->madam->id);

        $this->refused(fn () => $this->svc->publish($b, $this->admin), 'cannot be in two classes');

        $this->svc->unpublish($this->class);
        $this->svc->publish($b, $this->admin);                  // free now
        $this->assertSame([], $this->svc->teacherClashes(50, true));
    }

    public function test_another_academic_year_does_not_make_a_teacher_busy(): void
    {
        $next = $this->makeClass('Class 9', 'B', null, ['subjects' => ['Math'], 'academic_year' => '2031']);
        $this->place('Math', 0, 0, $this->madam);

        $this->svc->setCell($next, 0, $this->p[0]->id, 'Math', null, $this->madam->id);

        $this->assertSame([], $this->svc->teacherClashes());
        $this->assertSame(1, $this->svc->teacherGrid($this->madam->id, false, '2031')['periods_per_week']);
    }

    public function test_the_editor_is_told_who_is_busy_in_each_period(): void
    {
        $b = $this->makeClass('Class 8', 'A', null, ['subjects' => ['Math']]);
        $this->svc->setCell($b, 0, $this->p[0]->id, 'Math', null, $this->madam->id);

        $busy = $this->svc->grid($this->class)['busy'];

        $this->assertStringContainsString('8', $busy["0-{$this->p[0]->id}"][$this->madam->id]);
        $this->assertArrayNotHasKey("0-{$this->p[1]->id}", $busy);
    }

    // ---- saving periods and breaks --------------------------------------------------------------------------------

    private function row(string $name, string $from, string $to, bool $break = false): array
    {
        return ['name' => $name, 'starts_at' => $from, 'ends_at' => $to, 'is_break' => $break];
    }

    /** subject by teaching position on Sunday, e.g. [1 => 'Math', 3 => 'Art'] */
    private function sunday(SchoolClass $class): array
    {
        $g = $this->svc->grid($class);
        $out = [];

        foreach (collect($g['periods'])->where('is_break', false)->values() as $i => $p) {
            isset($g['cells']["0-{$p['id']}"]) && ($out[$i + 1] = $g['cells']["0-{$p['id']}"]['subject']);
        }

        return $out;
    }

    public function test_removing_and_adding_a_break_saves_and_keeps_every_subject_in_its_period(): void
    {
        $with = [$this->row('1st', '10:00', '10:40'), $this->row('2nd', '10:40', '11:20'), $this->row('Break', '11:20', '11:50', true), $this->row('3rd', '11:50', '12:30')];
        $without = [$this->row('1st', '10:00', '10:40'), $this->row('2nd', '10:40', '11:20'), $this->row('3rd', '11:20', '12:00')];

        $this->actingAs($this->admin)->put(route('routine.class-periods.save', $this->class), ['periods' => $with])->assertSessionHasNoErrors();
        $p = $this->svc->periodsForClass($this->class)->where('is_break', false)->values();
        foreach (['Religion', 'Math', 'Art'] as $i => $subject) {
            $this->svc->setCell($this->class, 0, $p[$i]->id, $subject, null, null);
        }
        $this->svc->publish($this->class, $this->admin);

        // the break is removed
        $this->actingAs($this->admin)->put(route('routine.class-periods.save', $this->class), ['periods' => $without])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->svc->periodsForClass($this->class)->where('is_break', true)->count());
        $this->assertSame([1 => 'Religion', 2 => 'Math', 3 => 'Art'], $this->sunday($this->class));
        $this->assertCount(3, $this->svc->grid($this->class, true)['cells']);
        $this->actingAs($this->admin)->get(route('routine.editor', $this->class))->assertInertia(fn (Assert $page) => $page->has('grid.periods', 3));

        // and put back somewhere else
        $this->svc->saveClassPeriods($this->class, [$this->row('1st', '10:00', '10:40'), $this->row('Break', '10:40', '11:00', true), $this->row('2nd', '11:00', '11:40'), $this->row('3rd', '11:40', '12:20')]);
        $this->assertSame([1 => 'Religion', 2 => 'Math', 3 => 'Art'], $this->sunday($this->class));
    }

    public function test_the_common_periods_page_saves_a_removed_break_without_moving_subjects(): void
    {
        $this->svc->savePeriods(null, [$this->row('1st', '10:00', '10:40'), $this->row('Break', '10:40', '11:10', true), $this->row('2nd', '11:10', '11:50')]);
        $p = $this->svc->periodsForClass($this->class)->where('is_break', false)->values();
        $this->svc->setCell($this->class, 0, $p[1]->id, 'Math', null, null);

        $this->actingAs($this->admin)->put(route('routine.periods.save'), ['shift_id' => null, 'periods' => [$this->row('1st', '10:00', '10:40'), $this->row('2nd', '10:40', '11:20')]])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->svc->periodsForClass($this->class)->count());
        $this->assertSame([2 => 'Math'], $this->sunday($this->class));
    }

    public function test_giving_a_shift_its_own_periods_and_taking_them_back_keeps_the_subjects(): void
    {
        $shift = \App\Models\Shift::firstOrCreate(['name' => 'Morning']);
        $this->class->update(['shift_id' => $shift->id]);
        $this->place('Math', 0, 1);

        $this->svc->savePeriods($shift->id, [$this->row('1st', '07:00', '07:40'), $this->row('2nd', '07:40', '08:20')]);
        $this->assertSame([2 => 'Math'], $this->sunday($this->class));

        $this->actingAs($this->admin)->delete(route('routine.periods.reset', $shift))->assertSessionHasNoErrors();
        $this->assertSame([2 => 'Math'], $this->sunday($this->class));
    }

    public function test_the_periods_page_lists_the_classes_it_does_not_change(): void
    {
        $this->actingAs($this->admin)->get(route('routine.periods'))->assertInertia(fn (Assert $page) => $page->has('ownClasses', 0));

        $this->svc->saveClassPeriods($this->class, [$this->row('1st', '10:00', '10:40')]);
        $this->actingAs($this->admin)->get(route('routine.periods'))->assertInertia(fn (Assert $page) => $page->has('ownClasses', 1)->where('ownClasses.0.id', $this->class->id));
    }

    // ---- drag and drop --------------------------------------------------------------------------------------------

    /** what the editor sends after dragging one period onto another and pressing Save */
    private function drag(SchoolClass $class, int $d1, int $p1, int $d2, int $p2)
    {
        $cells = $this->svc->grid($class)['cells'];
        $change = function (int $day, int $period, ?array $c) {
            return ['day' => $day, 'period_id' => $this->p[$period]->id, 'subject' => $c['subject'] ?? null, 'label' => null, 'teacher_id' => $c['teacher_id'] ?? null];
        };

        return $this->actingAs($this->admin)->put(route('routine.cells', $class), ['changes' => [
            $change($d2, $p2, $cells["{$d1}-{$this->p[$p1]->id}"] ?? null),
            $change($d1, $p1, $cells["{$d2}-{$this->p[$p2]->id}"] ?? null),
        ]]);
    }

    private function at(int $day, int $period): ?string
    {
        return $this->svc->grid($this->class)['cells']["{$day}-{$this->p[$period]->id}"]['subject'] ?? null;
    }

    public function test_dragging_one_period_onto_another_swaps_them_with_their_teachers(): void
    {
        $this->place('Religion', 0, 0, $this->sir);
        $this->place('Math', 1, 1, $this->madam);

        $this->drag($this->class, 0, 0, 1, 1)->assertSessionHasNoErrors();

        $this->assertSame('Math', $this->at(0, 0));
        $this->assertSame('Religion', $this->at(1, 1));
        $this->assertSame($this->sir->id, $this->svc->grid($this->class)['cells']["1-{$this->p[1]->id}"]['teacher_id']);
        $this->assertSame(2, ClassRoutineSlot::count());
    }

    public function test_dragging_onto_an_empty_period_moves_it(): void
    {
        $this->place('Math', 0, 0, $this->madam);

        $this->drag($this->class, 0, 0, 2, 3)->assertSessionHasNoErrors();

        $this->assertNull($this->at(0, 0));
        $this->assertSame('Math', $this->at(2, 3));
    }

    public function test_a_drag_that_would_double_book_a_teacher_changes_nothing(): void
    {
        $b = $this->makeClass('Class 8', 'A', null, ['subjects' => ['Math']]);
        $this->svc->setCell($b, 1, $this->p[1]->id, 'Math', null, $this->sir->id);
        $this->place('Religion', 0, 0, $this->sir);
        $this->place('Math', 1, 1, $this->madam);

        $this->drag($this->class, 0, 0, 1, 1)->assertSessionHasErrors('save');
        $this->assertStringContainsString('cannot be in two classes', session('errors')->first('save'));

        // and the other way round: dragging the other period would send him there too
        $this->drag($this->class, 1, 1, 0, 0)->assertSessionHasErrors('save');
        $this->assertSame('Religion', $this->at(0, 0));
        $this->assertSame('Math', $this->at(1, 1));
    }

    public function test_a_drag_cannot_put_a_subject_twice_on_a_one_a_day_day(): void
    {
        $this->place('Religion', 0, 0, $this->sir);
        $this->place('Religion', 1, 0, $this->sir);

        $this->drag($this->class, 1, 0, 0, 2)->assertSessionHasErrors('save');
        $this->assertSame('Religion', $this->at(1, 0));
        $this->assertNull($this->at(0, 2));

        $this->drag($this->class, 0, 0, 0, 2)->assertSessionHasNoErrors();       // within its own day is fine
    }

    public function test_an_old_routine_that_already_breaks_the_plan_can_still_be_rearranged(): void
    {
        foreach ([0, 1, 2, 3] as $d) {
            $this->place('Religion', $d, 0, $this->sir);
        }
        RoutinePlan::where('subject', 'Religion')->update(['periods_per_week' => 2]);

        $this->drag($this->class, 0, 0, 0, 3)->assertSessionHasNoErrors();
        $this->assertSame('Religion', $this->at(0, 3));
    }

    public function test_many_changes_are_saved_together_or_not_at_all(): void
    {
        $this->place('Math', 0, 0, $this->madam);
        $b = $this->makeClass('Class 8', 'A', null, ['subjects' => ['Math']]);
        $this->svc->setCell($b, 2, $this->p[0]->id, 'Math', null, $this->sir->id);
        $row = fn (int $d, int $p, ?string $s, ?User $t = null) => ['day' => $d, 'period_id' => $this->p[$p]->id, 'subject' => $s, 'label' => null, 'teacher_id' => $t?->id];

        // the second change double-books Religion Sir: the first must not be saved either
        $this->actingAs($this->admin)->put(route('routine.cells', $this->class), ['changes' => [$row(1, 0, 'Math', $this->madam), $row(2, 0, 'Religion', $this->sir)]])
            ->assertSessionHasErrors('save');
        $this->assertNull($this->at(1, 0));

        $this->actingAs($this->admin)->put(route('routine.cells', $this->class), ['changes' => [$row(0, 0, null), $row(1, 0, 'Math', $this->madam), $row(3, 0, 'Religion', $this->sir)]])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->at(0, 0));
        $this->assertSame('Math', $this->at(1, 0));
        $this->assertSame('Religion', $this->at(3, 0));
    }

    public function test_only_those_who_manage_can_save_the_table(): void
    {
        $this->actingAs($this->madam)->put(route('routine.cells', $this->class), ['changes' => [['day' => 0, 'period_id' => $this->p[0]->id, 'subject' => 'Math']]])->assertForbidden();
    }
}
