<?php

namespace Tests\Feature;

use App\Models\Teaching\SyllabusChapter;
use App\Services\CalendarEditor;
use App\Services\Teaching\PeriodBudget;
use App\Services\Teaching\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesTeachingData;
use Tests\TestCase;

class TeachingPeriodBudgetTest extends TestCase
{
    use RefreshDatabase, MakesTeachingData;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-15 08:00:00');
        $this->setUpTeaching();                                             // Sunday–Thursday school week
    }

    private function budget(): array
    {
        return app(PeriodBudget::class)->for($this->class->id, $this->math->id, '2026');
    }

    private function publish(int $classId): void
    {
        $routine = DB::table('class_routines')->where('class_id', $classId)->value('id');
        $slots = DB::table('class_routine_slots')->where('class_routine_id', $routine)->get(['day', 'period_id', 'subject_id', 'label', 'teacher_id']);
        DB::table('class_routines')->where('id', $routine)->update(['published_slots' => $slots->toJson(), 'published_at' => now()]);
    }

    /** one maths period every Sunday, and the school closed except a known number of Sundays */
    private function fortyPeriods(): void
    {
        $this->routineOn($this->class, 'Mathematics', $this->teacher, [0]);
        $this->publish($this->class->id);
        $sundays = [];

        for ($d = Carbon::parse('2026-01-04'); $d->year === 2026; $d->addWeek()) {
            $sundays[] = $d->toDateString();
        }

        // leave exactly 40 Sundays open
        app(CalendarEditor::class)->save(['type' => 'holiday', 'title' => 'Closed', 'starts_on' => $sundays[40], 'ends_on' => '2026-12-31', 'audience' => 'all'], $this->admin);
    }

    public function test_the_year_is_counted_from_the_routine_and_the_calendar(): void
    {
        $this->assertNull($this->budget()['total']);                          // nothing to go by: no limit
        $this->routineOn($this->class, 'Mathematics', $this->teacher, [0]);
        $this->assertNull($this->budget()['total']);                          // a draft routine is not enough

        $this->fortyPeriods();
        $this->assertSame(40, $this->budget()['total']);

        // a holiday and an exam day each take a period away; a make-up day gives one back
        $editor = app(CalendarEditor::class);
        $editor->save(['type' => 'holiday', 'title' => 'H', 'starts_on' => '2026-02-01', 'audience' => 'all'], $this->admin);
        $editor->save(['type' => 'exam', 'title' => 'E', 'starts_on' => '2026-02-08', 'audience' => 'all'], $this->admin);
        $this->assertSame(38, $this->budget()['total']);
        $editor->save(['type' => 'makeup', 'title' => 'M', 'starts_on' => '2026-02-06', 'audience' => 'all', 'follows_day' => 0], $this->admin);
        $this->assertSame(39, $this->budget()['total']);

        // however many sections, the cost stays a handful of queries
        $b = $this->makeClass('Class 6', 'B', 2026, ['subjects' => ['Mathematics']]);
        $this->routineOn($b, 'Mathematics', $this->other, [1]);
        $this->publish($b->id);
        DB::enableQueryLog();
        $level = app(PeriodBudget::class)->forLevel('2026', 'Class 6', $this->math->id);
        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
        // one section answers for the class level
        $this->assertSame([$this->budget()['total'], 'A'], [$level['limit'], $level['section']]);
    }

    public function test_the_setup_page_refuses_too_many_periods_and_accepts_fewer(): void
    {
        $this->fortyPeriods();
        $chapters = $this->syllabus();                                        // 10 + 10 + 20 = 40
        $ids = $chapters->pluck('id')->all();
        $save = fn (array $p) => $this->actingAs($this->head)->put(route('teaching.syllabus.periods'),
            ['academic_year' => '2026', 'class_name' => 'Class 6', 'subject_id' => $this->math->id, 'periods' => array_combine($ids, $p)]);

        $this->actingAs($this->head)->get(route('teaching.syllabus', ['session' => '2026', 'class' => 'Class 6', 'subject' => $this->math->id]))
            ->assertInertia(fn (Assert $p) => $p->where('budget.limit', 40));

        $save([10, 13, 20])->assertSessionHasErrors('periods');               // 43 of 40
        $this->assertSame(40, (int) SyllabusChapter::sum('periods'));         // nothing was saved
        $save([10, 5, 20])->assertSessionHasNoErrors();                       // fewer is the teacher's choice
        $this->assertSame(35, (int) SyllabusChapter::sum('periods'));

        // new chapters and single edits cannot get round it
        $this->actingAs($this->head)->post(route('teaching.syllabus.store'), ['academic_year' => '2026', 'class_name' => 'Class 6', 'subject_id' => $this->math->id, 'text' => 'Extra | 6'])->assertSessionHasErrors('text');
        $this->assertSame(3, SyllabusChapter::count());
        $this->actingAs($this->head)->put(route('teaching.syllabus.update', $ids[0]), ['periods' => 30])->assertSessionHasErrors('periods');
        $this->actingAs($this->head)->post(route('teaching.syllabus.store'), ['academic_year' => '2026', 'class_name' => 'Class 6', 'subject_id' => $this->math->id, 'text' => 'Extra | 5'])->assertSessionHasNoErrors();
    }

    public function test_an_import_that_overfills_a_syllabus_saves_nothing_and_says_which(): void
    {
        $this->fortyPeriods();
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('syllabus-imports/x.dat', "Class,Subject,Chapter,Topic,Periods\nClass 6,Mathematics,Big,,41\n");
        $task = \App\Models\BackgroundTask::queue('syllabus_import', $this->head, ['file' => 'syllabus-imports/x.dat', 'year' => '2026']);

        app()->call([new \App\Jobs\ImportSyllabus($task->id), 'handle']);

        $this->assertSame(0, SyllabusChapter::count());
        $this->assertStringContainsString('Class 6, Mathematics', (string) $task->fresh()->error);
        $this->assertStringContainsString('40', (string) $task->fresh()->error);
    }

    public function test_missing_plans_arrive_once_with_shift_and_medium_and_plan_filters_do_not_ask_again(): void
    {
        $shift = DB::table('shifts')->insertGetId(['name' => 'Evening X', 'created_at' => now(), 'updated_at' => now()]);
        $medium = DB::table('mediums')->insertGetId(['name' => 'Medium X', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('classes')->where('id', $this->class->id)->update(['shift_id' => $shift, 'medium_id' => $medium]);

        $page = $this->actingAs($this->admin)->get(route('teaching.plans', ['session' => '2026']))->assertOk();
        $page->assertInertia(fn (Assert $p) => $p->has('missing', 2)->where('plannedCount', 0)->where('missing.0.shift', 'Evening X')->where('missing.0.medium', 'Medium X')->where('missing.0.level', 'Class 6')->where('missing.0.section', 'A'));

        DB::enableQueryLog();
        $this->get(route('teaching.plans', ['session' => '2026', 'health' => 'red']), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Teaching/Plans',
            'X-Inertia-Partial-Data' => 'plans,filters,flash', 'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()) ?? ''])
            ->assertOk()->assertJsonMissingPath('props.missing');
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'class_subject_teachers')));
    }

    public function test_a_yearly_plan_cannot_hold_more_periods_than_the_year(): void
    {
        $this->fortyPeriods();
        $this->syllabus();
        $plan = app(PlanService::class)->create($this->class, $this->math, $this->teacher);
        $ids = $plan->items()->pluck('id')->all();
        $save = fn (array $p) => $this->actingAs($this->teacher)->put(route('teaching.plans.periods', $plan), ['periods' => array_combine($ids, $p)]);

        $this->actingAs($this->teacher)->get(route('teaching.plans.show', $plan))->assertInertia(fn (Assert $p) => $p->where('budget.total', 40));
        $save([13, 10, 20])->assertSessionHasErrors('periods');
        $this->assertSame(40, (int) $plan->items()->sum('planned_periods'));
        $save([5, 10, 20])->assertSessionHasNoErrors();
        $this->assertSame(35, (int) $plan->items()->sum('planned_periods'));
        $this->actingAs($this->teacher)->put(route('teaching.items.update', $ids[0]), ['planned_periods' => 11])->assertSessionHasErrors('plan');
        $this->actingAs($this->other)->put(route('teaching.plans.periods', $plan), ['periods' => [$ids[0] => 1]])->assertForbidden();

        // already over (the calendar changed later): taking periods away is still allowed
        app(CalendarEditor::class)->save(['type' => 'holiday', 'title' => 'Flood', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'audience' => 'all'], $this->admin);
        $this->actingAs($this->teacher)->put(route('teaching.items.update', $ids[2]), ['planned_periods' => 19])->assertSessionHasNoErrors();
    }
}
