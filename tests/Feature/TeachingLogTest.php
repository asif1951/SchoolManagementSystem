<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Teaching\TeachingLog;
use App\Models\Teaching\TeachingPlan;
use App\Services\RoutineService;
use App\Services\Teaching\PlanService;
use App\Services\Teaching\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesTeachingData;
use Tests\TestCase;

class TeachingLogTest extends TestCase
{
    use RefreshDatabase, MakesTeachingData;

    private TeachingPlan $plan;
    private $period;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-15 08:00:00');                 // a Sunday
        $this->setUpTeaching();
        $this->routineOn($this->class, 'Mathematics', $this->teacher);
        $this->syllabus();
        $this->plan = app(PlanService::class)->create($this->class, $this->math, $this->teacher);
        $this->period = app(RoutineService::class)->periodsForClass($this->class)->first();
    }

    private function row(array $more = []): array
    {
        return array_merge(['log_date' => '2026-03-15', 'class_id' => $this->class->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id,
            'period_id' => $this->period->id, 'held' => true, 'coverage' => 100, 'understanding' => 'good', 'homework' => false], $more);
    }

    public function test_today_opens_with_the_routine_classes_and_the_next_topic_chosen(): void
    {
        $item = $this->plan->items()->first();

        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Teaching/DailyLog')
            ->where('date', '2026-03-15')->where('canWrite', true)->has('cards', 1)
            ->where('cards.0.subject', 'Mathematics')->where('cards.0.period', '1st')->where('cards.0.log', null)
            ->where('cards.0.suggest.item_id', $item->id)->where('cards.0.suggest.topic_id', $item->chapter->topics[0]->id));

        // a Friday has no classes; another teacher sees only their own
        $this->actingAs($this->teacher)->get(route('teaching.log', ['date' => '2026-03-13']))->assertInertia(fn (Assert $p) => $p->has('cards', 0));
        $this->actingAs($this->other)->get(route('teaching.log', ['teacher' => $this->teacher->id]))->assertInertia(fn (Assert $p) => $p->has('cards', 0)->where('teacherId', $this->other->id));
    }

    public function test_the_published_routine_wins_over_the_draft(): void
    {
        $svc = app(RoutineService::class);
        $svc->publish($this->class, $this->admin);
        $svc->clearAll($this->class);                             // the draft is being reworked

        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->has('cards', 1));
    }

    public function test_saving_a_class_updates_progress_and_moves_to_the_next_topic(): void
    {
        $item = $this->plan->items()->first();
        [$first, $second] = $item->chapter->topics->all();

        $res = $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['item_id' => $item->id, 'topic_id' => $first->id]))->assertOk();

        $res->assertJsonPath('log.coverage', 100);
        $this->assertSame(50.0, $item->fresh()->progress);                       // one of two topics
        $this->assertSame(12.5, $this->plan->fresh()->done_pct);                 // half of 10 of 40 periods
        $this->assertSame($second->id, collect($res->json('options'))->first()['topics'][1]['id']);

        // saving the same period again updates the one row
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['item_id' => $item->id, 'topic_id' => $first->id, 'coverage' => 50, 'homework' => true]))->assertOk();
        $this->assertSame(1, TeachingLog::count());
        $this->assertSame(25.0, $item->fresh()->progress);
        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->where('cards.0.log.homework', true));
    }

    public function test_a_chapter_without_topics_counts_periods(): void
    {
        $item = $this->plan->items()->get()[1];                    // Algebra, 10 periods, no topics
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['item_id' => $item->id]))->assertOk();

        $this->assertSame(10.0, $item->fresh()->progress);
    }

    public function test_class_not_held_needs_a_reason_and_adds_no_progress(): void
    {
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['held' => false]))->assertStatus(422);
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['held' => false, 'not_held_reason' => 'Holiday', 'item_id' => $this->plan->items()->first()->id]))->assertOk();

        $log = TeachingLog::firstOrFail();
        $this->assertSame(0, $log->coverage);
        $this->assertNull($log->understanding);
        $this->assertSame(0.0, $this->plan->fresh()->done_pct);
    }

    public function test_a_substitute_is_recorded_and_sees_the_class_but_cannot_write_it(): void
    {
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['substitute_id' => $this->teacher->id]))->assertStatus(422);
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['substitute_id' => $this->other->id]))->assertOk();

        $log = TeachingLog::firstOrFail();
        $this->assertSame($this->teacher->id, $log->teacher_id);
        $this->assertSame($this->other->id, $log->substitute_id);
        $this->actingAs($this->other)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->where('cards.0.covering', true));
        $this->actingAs($this->other)->putJson(route('teaching.log.save'), ['id' => $log->id, 'held' => true, 'coverage' => 0])->assertForbidden();
        $this->actingAs($this->other)->deleteJson(route('teaching.log.destroy', $log))->assertForbidden();
    }

    public function test_extra_classes_can_be_added_more_than_once(): void
    {
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['period_id' => null]))->assertOk();
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['period_id' => null]))->assertOk();

        $this->assertSame(2, TeachingLog::where('kind', 'extra')->count());
    }

    public function test_the_edit_window(): void
    {
        $old = $this->row(['log_date' => '2026-03-01']);                  // 14 days ago

        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $old)->assertStatus(422);
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['log_date' => '2026-03-16']))->assertStatus(422);        // tomorrow
        $this->actingAs($this->teacher)->get(route('teaching.log', ['date' => '2026-03-01']))->assertInertia(fn (Assert $p) => $p->where('canWrite', false)->where('locked', true));

        Settings::save(['edit_window_days' => 20]);
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $old)->assertOk();
        $this->assertSame(1, AuditLog::where('event', 'log.past_edit')->count());

        Settings::save(['edit_window_days' => 7]);
        $id = TeachingLog::value('id');
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), ['id' => $id, 'held' => true, 'coverage' => 50])->assertStatus(422);
        $this->actingAs($this->teacher)->deleteJson(route('teaching.log.destroy', $id))->assertStatus(422);

        // the permission opens older dates
        $this->teacher->forceFill(['extra_abilities' => ['teaching.edit_past']])->save();
        $this->actingAs($this->teacher->fresh())->putJson(route('teaching.log.save'), ['id' => $id, 'held' => true, 'coverage' => 50])->assertOk();
        $this->assertSame(2, AuditLog::where('event', 'log.past_edit')->count());
    }

    public function test_no_one_writes_in_another_teachers_name(): void
    {
        // claiming to be the owner
        $this->actingAs($this->other)->putJson(route('teaching.log.save'), $this->row())->assertForbidden();
        // own name, someone else's period
        $this->actingAs($this->other)->putJson(route('teaching.log.save'), $this->row(['teacher_id' => $this->other->id]))->assertStatus(422);

        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row())->assertOk();
        $log = TeachingLog::firstOrFail();
        $this->actingAs($this->other)->putJson(route('teaching.log.save'), ['id' => $log->id, 'held' => true, 'coverage' => 0])->assertForbidden();
        // naming the same slot cannot take it over
        $this->actingAs($this->other)->putJson(route('teaching.log.save'), $this->row(['teacher_id' => $this->other->id, 'subject_id' => $this->math->id]))->assertForbidden();
        $this->actingAs($this->head)->putJson(route('teaching.log.save'), ['id' => $log->id, 'held' => true, 'coverage' => 0])->assertForbidden();
        $this->actingAs($this->admin)->putJson(route('teaching.log.save'), ['id' => $log->id, 'held' => true, 'coverage' => 0])->assertOk();
    }

    public function test_a_topic_of_another_plan_is_refused(): void
    {
        $this->syllabus('Class 6', \App\Models\Subject::where('name', 'English')->first());
        $theirs = app(PlanService::class)->create($this->class, \App\Models\Subject::where('name', 'English')->first(), $this->other);

        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['item_id' => $theirs->items()->first()->id]))->assertStatus(422);
    }

    public function test_same_for_all_sections_uses_each_sections_own_plan(): void
    {
        $b = $this->makeClass('Class 6', 'B', 2026, ['subjects' => ['Mathematics']]);
        $b->assignSubjectTeacher('Mathematics', $this->teacher);
        $this->routineOn($b, 'Mathematics', $this->teacher, [0], 1);
        $planB = app(PlanService::class)->create($b, $this->math, $this->teacher);
        $item = $this->plan->items()->first();
        $topic = $item->chapter->topics[0];
        $second = app(RoutineService::class)->periodsForClass($b)->values()[1];

        $id = $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['item_id' => $item->id, 'topic_id' => $topic->id]))->json('log.id');
        $this->actingAs($this->teacher)->postJson(route('teaching.log.copy', $id), ['targets' => [['class_id' => $b->id, 'period_id' => $second->id]]])->assertOk();

        $copy = TeachingLog::where('class_id', $b->id)->firstOrFail();
        $this->assertSame($planB->id, $copy->plan_id);
        $this->assertSame($topic->id, $copy->topic_id);
        $this->assertSame(50.0, $planB->items()->first()->progress);
    }

    public function test_removing_a_log_takes_its_progress_back(): void
    {
        $item = $this->plan->items()->get()[1];
        $id = $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), $this->row(['item_id' => $item->id]))->json('log.id');

        $this->actingAs($this->teacher)->deleteJson(route('teaching.log.destroy', $id))->assertOk()->assertJsonPath('plan.done', 0);
        $this->assertSame(0.0, $item->fresh()->progress);
        $this->actingAs($this->teacher)->delete(route('teaching.plans.destroy', $this->plan))->assertSessionHasNoErrors();
    }

    public function test_the_day_screen_and_a_save_stay_small_in_queries(): void
    {
        $this->actingAs($this->teacher);
        DB::enableQueryLog();
        $this->get(route('teaching.log'))->assertOk();
        $page = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->putJson(route('teaching.log.save'), $this->row(['item_id' => $this->plan->items()->first()->id]))->assertOk();
        $save = count(DB::getQueryLog());

        $this->assertLessThan(35, $page);
        $this->assertLessThan(35, $save);
    }
}
