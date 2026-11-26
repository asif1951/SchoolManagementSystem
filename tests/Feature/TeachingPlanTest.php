<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\Teaching\Holiday;
use App\Models\Teaching\TeachingPlan;
use App\Services\Teaching\Calendar;
use App\Services\Teaching\PlanService;
use App\Services\Teaching\Progress;
use App\Services\Teaching\Scheduler;
use App\Services\Teaching\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesTeachingData;
use Tests\TestCase;

class TeachingPlanTest extends TestCase
{
    use RefreshDatabase, MakesTeachingData;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-15 08:00:00');
        $this->setUpTeaching();
        $this->routineOn($this->class, 'Mathematics', $this->teacher);       // 5 periods a week
        $this->syllabus();
    }

    private function plan(): TeachingPlan
    {
        return app(PlanService::class)->create($this->class, $this->math, $this->teacher);
    }

    public function test_a_plan_is_made_from_the_syllabus_in_one_step(): void
    {
        $this->actingAs($this->teacher)->post(route('teaching.plans.store'), ['class_id' => $this->class->id, 'subject_id' => $this->math->id])->assertSessionHasNoErrors();

        $plan = TeachingPlan::firstOrFail();
        $this->assertSame(['Numbers', 'Algebra', 'Geometry'], $plan->items()->with('chapter')->get()->pluck('chapter.name')->all());
        $this->assertSame(40, $plan->planned_periods);
        $this->assertSame('none', $plan->health);

        $this->actingAs($this->teacher)->post(route('teaching.plans.store'), ['class_id' => $this->class->id, 'subject_id' => $this->math->id])->assertSessionHasErrors('plan');
    }

    public function test_a_teacher_cannot_plan_a_subject_that_is_not_theirs(): void
    {
        $this->actingAs($this->other)->post(route('teaching.plans.store'), ['class_id' => $this->class->id, 'subject_id' => $this->math->id])->assertForbidden();
        $this->actingAs($this->other)->post(route('teaching.plans.store'), ['class_id' => $this->class->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id])->assertForbidden();
    }

    public function test_auto_schedule_uses_only_teaching_days_in_order_and_by_periods(): void
    {
        Holiday::create(['starts_on' => '2026-01-04', 'ends_on' => '2026-01-08', 'title' => 'Winter']);          // a whole week
        Exam::create(['academic_year' => '2026', 'name' => 'Half yearly', 'weight' => 100, 'starts_on' => '2026-06-07', 'ends_on' => '2026-06-18', 'status' => 'draft', 'sort_order' => 1]);
        $plan = $this->plan();

        $r = app(Scheduler::class)->auto($plan);

        $this->assertSame(5, $r['per_week']);
        $this->assertTrue($r['enough']);
        $days = app(Calendar::class)->teachingDays('2026-01-01', '2026-12-31');
        $this->assertNotContains('2026-01-05', $days);                         // holiday
        $this->assertNotContains('2026-06-08', $days);                         // exam
        $this->assertNotContains('2026-01-02', $days);                         // Friday

        $items = $plan->items()->get();
        $this->assertSame($days[0], $items[0]->starts_on->toDateString());
        $this->assertSame(end($days), $items[2]->ends_on->toDateString());

        foreach ($items as $k => $i) {
            $this->assertContains($i->starts_on->toDateString(), $days);
            $this->assertContains($i->ends_on->toDateString(), $days);
            $k && $this->assertGreaterThan($items[$k - 1]->ends_on->toDateString(), $i->starts_on->toDateString());
        }

        // Geometry has twice the periods of Numbers, so about twice the days
        $count = fn ($i) => count(array_filter($days, fn ($d) => $d >= $i->starts_on->toDateString() && $d <= $i->ends_on->toDateString()));
        $this->assertEqualsWithDelta(2 * $count($items[0]), $count($items[2]), 2);
    }

    public function test_it_warns_when_there_are_not_enough_periods_or_chapters_overlap(): void
    {
        $plan = $this->plan();
        $svc = app(PlanService::class);
        [$a, $b] = $plan->items()->get()->all();

        $r = app(Scheduler::class)->auto($plan, '2026-01-01', '2026-01-15');           // 40 periods in two weeks
        $this->assertFalse($r['enough']);

        $svc->updateItem($a, ['starts_on' => '2026-02-01', 'ends_on' => '2026-02-12']);
        $svc->updateItem($b, ['starts_on' => '2026-02-08', 'ends_on' => '2026-02-26']);
        $types = collect($svc->warnings($plan, $plan->items()->with('chapter')->get()))->pluck('type');
        $this->assertContains('overlap', $types);
        $this->assertContains('periods', $types);
    }

    public function test_dates_are_checked_and_changes_are_audited(): void
    {
        $item = $this->plan()->items()->first();

        $this->actingAs($this->teacher)->put(route('teaching.items.update', $item), ['starts_on' => '2026-03-10', 'ends_on' => '2026-03-01'])->assertSessionHasErrors('plan');
        $this->actingAs($this->teacher)->put(route('teaching.items.update', $item), ['starts_on' => '2025-12-01', 'ends_on' => '2026-03-01'])->assertSessionHasErrors('plan');
        $this->actingAs($this->teacher)->put(route('teaching.items.update', $item), ['starts_on' => '2026-03-01', 'ends_on' => '2026-03-10'])->assertSessionHasNoErrors();

        $this->assertSame('2026-03-10', $item->fresh()->ends_on->toDateString());
        $this->assertSame('2026-03-10', AuditLog::where('event', 'plan.item')->latest('id')->first()->changes['ends_on']['to']);
        $this->assertSame('delayed', $item->fresh()->statusOn('2026-03-15'));
        $this->assertSame(1, $item->plan->fresh()->delayed);
    }

    public function test_expected_progress_health_and_projection(): void
    {
        $plan = $this->plan();
        [$a, $b, $c] = $plan->items()->get()->all();
        $svc = app(PlanService::class);
        $svc->updateItem($a, ['starts_on' => '2026-01-04', 'ends_on' => '2026-02-26']);       // over
        $svc->updateItem($b, ['starts_on' => '2026-04-01', 'ends_on' => '2026-05-28']);       // not started
        $svc->updateItem($c, ['starts_on' => '2026-06-01', 'ends_on' => '2026-08-31']);

        $plan->refresh();
        $this->assertSame(25.0, $plan->expected_pct);                  // chapter 1 = 10 of 40 periods, all expected
        $this->assertSame(0.0, $plan->done_pct);
        $this->assertSame('red', $plan->health);
        $this->assertNull($plan->projected_finish);

        $svc->updateItem($a, ['completed' => true]);
        $plan->refresh();
        $this->assertSame(25.0, $plan->done_pct);
        $this->assertSame('green', $plan->health);
        $this->assertGreaterThan('2026-03-15', $plan->projected_finish->toDateString());

        Settings::save(['amber_gap' => 1, 'red_gap' => 50]);
        $svc->updateItem($a, ['completed' => false]);
        $this->assertSame('amber', $plan->fresh()->health);
    }

    public function test_expected_moves_with_the_date_without_any_log(): void
    {
        $plan = $this->plan();
        app(Scheduler::class)->auto($plan);
        $before = $plan->fresh()->expected_pct;

        Carbon::setTestNow('2026-07-15 08:00:00');
        $this->assertSame(1, app(Progress::class)->refreshStale('2026'));
        $this->assertGreaterThan($before, $plan->fresh()->expected_pct);
    }

    public function test_a_plan_is_copied_to_another_section_and_the_next_year(): void
    {
        $plan = $this->plan();
        app(Scheduler::class)->auto($plan);
        $b = $this->makeClass('Class 6', 'B', 2026, ['subjects' => ['Mathematics']]);
        $b->assignSubjectTeacher('Mathematics', $this->teacher);

        $this->actingAs($this->teacher)->post(route('teaching.plans.copy', $plan), ['direction' => 'to', 'other' => $b->id])->assertSessionHasNoErrors();
        $copy = TeachingPlan::where('class_id', $b->id)->firstOrFail();
        $this->assertEquals($plan->items()->pluck('starts_on'), $copy->items()->pluck('starts_on'));

        $next = $this->makeClass('Class 6', 'A', 2027, ['subjects' => ['Mathematics']]);
        $later = app(PlanService::class)->copy($plan, $next, $this->teacher);                // 2027 uses the 2026 syllabus
        $this->assertSame('2027', substr($later->items()->first()->starts_on->toDateString(), 0, 4));
    }

    public function test_approval_off_by_default_and_the_full_flow_when_on(): void
    {
        $plan = $this->plan();
        app(Scheduler::class)->auto($plan);

        $this->actingAs($this->teacher)->post(route('teaching.plans.submit', $plan))->assertSessionHasErrors('plan');

        Settings::save(['approval_required' => true]);
        $this->actingAs($this->teacher)->post(route('teaching.plans.submit', $plan))->assertSessionHasNoErrors();
        $this->assertSame('submitted', $plan->fresh()->status);
        $this->assertSame(1, $this->head->notifications()->count());

        $this->actingAs($this->teacher)->post(route('teaching.plans.decide', $plan), ['approve' => true])->assertForbidden();
        $this->actingAs($this->head)->post(route('teaching.plans.decide', $plan), ['approve' => false])->assertSessionHasErrors('plan');          // say why
        $this->actingAs($this->head)->post(route('teaching.plans.decide', $plan), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertSame('approved', $plan->fresh()->status);
        $this->assertSame(1, $this->teacher->notifications()->count());
        $this->assertTrue(AuditLog::where('event', 'plan.approved')->exists());

        // changing an approved plan sends it back for approval
        app(PlanService::class)->updateItem($plan->items()->first(), ['planned_periods' => 12]);
        $this->assertSame('draft', $plan->fresh()->status);
    }

    public function test_reviews_notify_the_teacher(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->teacher)->post(route('teaching.plans.review', $plan), ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->head)->post(route('teaching.plans.review', $plan), ['body' => 'Please speed up.'])->assertSessionHasNoErrors();

        $this->assertSame('notify.review', $this->teacher->notifications()->first()->data['key']);
    }

    public function test_no_one_reaches_another_teachers_plan(): void
    {
        $plan = $this->plan();
        $item = $plan->items()->first();

        $this->actingAs($this->other)->get(route('teaching.plans.show', $plan))->assertForbidden();
        $this->actingAs($this->other)->put(route('teaching.items.update', $item), ['planned_periods' => 2])->assertForbidden();
        $this->actingAs($this->other)->delete(route('teaching.items.destroy', $item))->assertForbidden();
        $this->actingAs($this->other)->post(route('teaching.plans.schedule', $plan))->assertForbidden();
        $this->actingAs($this->other)->post(route('teaching.plans.copy', $plan), ['direction' => 'from', 'other' => $plan->id])->assertForbidden();
        $this->actingAs($this->other)->delete(route('teaching.plans.destroy', $plan))->assertForbidden();
        $this->actingAs($this->other)->get(route('teaching.plans', ['session' => '2026', 'teacher' => $this->teacher->id]))
            ->assertInertia(fn (Assert $p) => $p->has('plans.data', 0));

        // the head sees but does not edit; the admin does both
        $this->actingAs($this->head)->get(route('teaching.plans.show', $plan))->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.edit', false)->where('can.review', true));
        $this->actingAs($this->head)->put(route('teaching.items.update', $item), ['planned_periods' => 2])->assertForbidden();
        $this->actingAs($this->admin)->put(route('teaching.items.update', $item), ['planned_periods' => 2])->assertSessionHasNoErrors();
    }

    public function test_undated_plans_are_scheduled_together_with_progress(): void
    {
        $plan = $this->plan();

        $task = $this->actingAs($this->teacher)->postJson(route('teaching.plans.schedule-all'), ['session' => '2026'])->assertOk()->json('task');

        $this->actingAs($this->teacher)->getJson(route('teaching.tasks.show', $task))->assertJsonPath('status', 'done');
        $this->assertNotNull($plan->items()->first()->starts_on);
    }
}
