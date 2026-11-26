<?php

namespace Tests\Feature;

use App\Models\BackgroundTask;
use App\Models\Teaching\LessonPlan;
use App\Models\Teaching\TeachingPlan;
use App\Services\RoutineService;
use App\Services\Teaching\PlanService;
use App\Services\Teaching\Settings;
use App\Support\SheetStream;
use Database\Seeders\TeachingPlanDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesTeachingData;
use Tests\TestCase;

/** Dashboards, reports, weekly and monthly views, lesson plans, settings, and who may open what. */
class TeachingDashboardTest extends TestCase
{
    use RefreshDatabase, MakesTeachingData;

    private TeachingPlan $plan;
    private TeachingPlan $english;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-15 08:00:00');                 // Sunday
        $this->setUpTeaching();
        $this->routineOn($this->class, 'Mathematics', $this->teacher);
        $this->routineOn($this->class, 'English', $this->other, [0, 1], 1);
        $this->syllabus();
        $eng = \App\Models\Subject::where('name', 'English')->first();
        $this->syllabus('Class 6', $eng);
        $svc = app(PlanService::class);
        $this->plan = $svc->create($this->class, $this->math, $this->teacher);
        $this->english = $svc->create($this->class, $eng, $this->other);

        // maths: first chapter due and not done (behind); English: first chapter done (on track)
        $svc->updateItem($this->plan->items()->first(), ['starts_on' => '2026-01-04', 'ends_on' => '2026-02-26']);
        $svc->updateItem($this->english->items()->first(), ['starts_on' => '2026-01-04', 'ends_on' => '2026-02-26', 'completed' => true]);
    }

    private function log(int $classPeriod = 0): void
    {
        $p = app(RoutineService::class)->periodsForClass($this->class)->values()[$classPeriod];
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), ['log_date' => '2026-03-15', 'class_id' => $this->class->id, 'subject_id' => $this->math->id,
            'teacher_id' => $this->teacher->id, 'period_id' => $p->id, 'held' => true, 'coverage' => 50])->assertOk();
    }

    public function test_the_school_dashboard_numbers(): void
    {
        $this->actingAs($this->head)->get(route('teaching.dashboard', ['session' => '2026']))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Teaching/Dashboard')
            ->where('summary.plans', 2)->where('summary.red', 1)->where('summary.green', 1)->where('summary.delayed', 1)
            ->where('summary.done', 12.5)->where('summary.expected', 25)
            ->where('behind.0.teacher', 'Karim Sir')->where('notLoggedCount', 2));

        $this->log();                                             // the numbers follow at once

        $this->actingAs($this->head)->get(route('teaching.dashboard', ['session' => '2026']))->assertInertia(fn (Assert $p) => $p->where('notLoggedCount', 1)->where('notLogged.0.name', 'Salma Madam'));
    }

    public function test_a_teachers_home_is_today_with_their_own_loose_ends(): void
    {
        $this->actingAs($this->teacher)->get(route('teaching.dashboard'))->assertRedirect(route('teaching.log'));

        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p
            ->has('cards', 1)->where('cards.0.log', null)->where('todo.plans', 1)->where('todo.missing', 0)
            ->has('todo.pending', 5)                                              // 8–12 March, Sunday to Thursday
            ->has('todo.behind', 1)->where('todo.behind.0.teacher', 'Karim Sir'));

        $this->log();
        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->where('cards.0.log.coverage', 50));
        // looking at someone else's day shows none of their loose ends
        $this->actingAs($this->head)->get(route('teaching.log', ['teacher' => $this->teacher->id]))->assertInertia(fn (Assert $p) => $p->where('todo', null));
    }

    public function test_one_click_makes_all_my_plans_with_dates(): void
    {
        $b = $this->makeClass('Class 6', 'B', 2026, ['subjects' => ['Mathematics', 'Art']]);
        $b->assignSubjectTeacher('Mathematics', $this->teacher);
        $b->assignSubjectTeacher('Art', $this->teacher);                     // no syllabus for Art

        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->where('todo.missing', 2));
        $this->actingAs($this->teacher)->post(route('teaching.plans.make-mine'), ['session' => '2026'])->assertSessionHas('error', fn ($m) => str_contains($m, 'Art'));

        $new = TeachingPlan::where('class_id', $b->id)->get();
        $this->assertCount(1, $new);
        $this->assertSame(0, $new[0]->items()->whereNull('starts_on')->count());
        $this->actingAs($this->head)->post(route('teaching.plans.make-mine'), ['session' => '2026'])->assertSessionHasNoErrors();
        $this->actingAs($this->makeUser())->post(route('teaching.plans.make-mine'), ['session' => '2026'])->assertForbidden();
    }

    public function test_the_setup_checklist_shows_what_is_left(): void
    {
        $this->actingAs($this->head)->get(route('teaching.dashboard', ['session' => '2026']))->assertInertia(fn (Assert $p) => $p
            ->has('setup', 6)->where('setup.0.done', true)->where('setup.2.key', 'holidays')->where('setup.2.done', false)->where('setup.2.url', '/calendar')      // the head teacher keeps the calendar
            ->where('setup.3.done', true)->where('setup.3.url', '/teaching/syllabus'));
    }

    public function test_progress_page_and_its_limits(): void
    {
        $this->actingAs($this->head)->get(route('teaching.progress', ['session' => '2026']))->assertInertia(fn (Assert $p) => $p
            ->has('plans.data', 2)->where('plans.data.0.health', 'red')->has('delayed', 1)->where('delayed.0.chapter', 'Numbers')->where('delayed.0.days', 17)->where('canReview', true));

        $this->actingAs($this->other)->get(route('teaching.progress', ['session' => '2026', 'teacher' => $this->teacher->id]))->assertInertia(fn (Assert $p) => $p
            ->has('plans.data', 1)->where('plans.data.0.teacher', 'Salma Madam')->has('delayed', 0)->where('canReview', false));
    }

    public function test_weekly_and_monthly_views_come_from_the_yearly_plan(): void
    {
        $item = $this->plan->items()->first();

        $this->actingAs($this->teacher)->get(route('teaching.period', ['mode' => 'month', 'date' => '2026-02-10']))->assertInertia(fn (Assert $p) => $p
            ->component('Teaching/Period')->has('plans', 1)->where('plans.0.items.0.chapter', 'Numbers')->where('plans.0.items.0.expected', 100));
        $this->actingAs($this->teacher)->get(route('teaching.period', ['mode' => 'week', 'date' => '2026-04-10']))->assertInertia(fn (Assert $p) => $p->has('plans', 0));

        // a date changed from the view is the yearly plan's date
        $this->actingAs($this->teacher)->put(route('teaching.items.update', $item), ['ends_on' => '2026-04-16'])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->get(route('teaching.period', ['mode' => 'week', 'date' => '2026-04-10']))->assertInertia(fn (Assert $p) => $p
            ->where('plans.0.items.0.id', $item->id)->where('plans.0.items.0.ends_on', '2026-04-16'));
        $this->assertSame('2026-04-16', $item->fresh()->ends_on->toDateString());

        $this->actingAs($this->teacher)->get(route('teaching.period.print', ['mode' => 'month', 'date' => '2026-02-10']))->assertOk()->assertSee('Numbers');
        // another teacher cannot print my plan
        $this->actingAs($this->other)->get(route('teaching.period.print', ['mode' => 'year', 'session' => '2026', 'plan' => $this->plan->id, 'teacher' => $this->teacher->id]))->assertOk()->assertDontSee('Mathematics —');
    }

    public function test_every_report_on_screen_print_and_excel(): void
    {
        Storage::fake('local');
        $this->log();

        foreach (['progress' => 2, 'delayed' => 1, 'completion' => 2, 'activity' => 1, 'pending' => 1] as $report => $rows) {
            $q = ['report' => $report, 'session' => '2026', 'from' => '2026-03-15', 'to' => '2026-03-15'];
            $this->actingAs($this->head)->get(route('teaching.reports', $q))->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', $rows));
            $this->actingAs($this->head)->get(route('teaching.reports.print', $q))->assertOk();

            $task = $this->actingAs($this->head)->postJson(route('teaching.reports.export'), $q)->assertOk()->json('task');
            $t = BackgroundTask::find($task);
            $this->assertSame('done', $t->status, $report . ' ' . $t->error);
            $this->assertSame($rows, $t->summary['rows']);
            $this->actingAs($this->head)->get(route('teaching.tasks.download', $task))->assertOk();
            $this->actingAs($this->teacher)->get(route('teaching.tasks.download', $task))->assertForbidden();
        }

        // a teacher's report holds only their own rows, whatever is asked
        $this->actingAs($this->other)->get(route('teaching.reports', ['report' => 'progress', 'session' => '2026', 'teacher' => $this->teacher->id]))
            ->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.teacher', 'Salma Madam'));
    }

    public function test_lesson_plans_templates_copy_and_ownership(): void
    {
        $base = ['title' => 'Fractions', 'class_id' => $this->class->id, 'subject_id' => $this->math->id, 'objectives' => 'Add fractions', 'is_template' => false];
        $this->actingAs($this->teacher)->post(route('teaching.lessons.store'), $base)->assertSessionHasNoErrors();
        $lesson = LessonPlan::firstOrFail();

        $b = $this->makeClass('Class 6', 'B', 2026, ['subjects' => ['Mathematics']]);
        $b->assignSubjectTeacher('Mathematics', $this->teacher);
        $this->actingAs($this->teacher)->post(route('teaching.lessons.copy', $lesson), ['class_ids' => [$b->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, LessonPlan::count());

        // from a daily log entry
        $this->log();
        $logId = \App\Models\Teaching\TeachingLog::value('id');
        $this->actingAs($this->teacher)->get(route('teaching.lessons', ['log' => $logId]))->assertInertia(fn (Assert $p) => $p->where('prefill.log_id', $logId)->where('prefill.subject_id', $this->math->id));
        $this->actingAs($this->other)->get(route('teaching.lessons', ['log' => $logId]))->assertForbidden();

        $this->actingAs($this->other)->put(route('teaching.lessons.update', $lesson), $base)->assertForbidden();
        $this->actingAs($this->other)->delete(route('teaching.lessons.destroy', $lesson))->assertForbidden();
        $this->actingAs($this->other)->post(route('teaching.lessons.copy', $lesson), ['class_ids' => [$b->id]])->assertForbidden();
        $this->actingAs($this->other)->get(route('teaching.lessons.print', $lesson))->assertForbidden();
        $this->actingAs($this->other)->post(route('teaching.lessons.store'), $base)->assertForbidden();              // not their subject
        $this->actingAs($this->other)->get(route('teaching.lessons'))->assertInertia(fn (Assert $p) => $p->has('lessons.data', 0));
        $this->actingAs($this->teacher)->get(route('teaching.lessons.print', $lesson))->assertOk()->assertSee('Add fractions');
    }

    public function test_settings_holidays_and_personal_permissions(): void
    {
        $this->actingAs($this->head)->get(route('teaching.settings'))->assertForbidden();
        $this->actingAs($this->teacher)->put(route('teaching.settings.save'), [])->assertForbidden();

        $this->actingAs($this->admin)->put(route('teaching.settings.save'), ['approval_required' => true, 'edit_window_days' => 3, 'amber_gap' => 30, 'red_gap' => 60])->assertSessionHasNoErrors();
        $this->assertTrue(Settings::get('approval_required'));
        $this->actingAs($this->head)->get(route('teaching.progress', ['session' => '2026']))->assertInertia(fn (Assert $p) => $p->where('summary.red', 0));     // new thresholds

        $this->actingAs($this->admin)->put(route('teaching.settings.grant', $this->teacher), ['abilities' => ['teaching.view_all']])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher->fresh())->get(route('teaching.plans.show', $this->english))->assertOk();
        $this->actingAs($this->admin)->put(route('teaching.settings.grant', $this->teacher), ['abilities' => ['users.manage']])->assertSessionHasErrors();
    }

    public function test_notices_are_shown_and_marked_read(): void
    {
        $this->actingAs($this->head)->post(route('teaching.plans.review', $this->plan), ['body' => 'Faster, please']);

        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->has('unread', 1)->where('unread.0.text', 'Head Teacher commented on your teaching progress.'));
        $this->actingAs($this->teacher)->post(route('teaching.notices.read'), [])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->teacher->unreadNotifications()->count());
    }

    public function test_people_without_a_teaching_permission_get_nothing(): void
    {
        $outsider = $this->makeUser();

        foreach (['teaching.dashboard', 'teaching.plans', 'teaching.log', 'teaching.period', 'teaching.lessons', 'teaching.syllabus', 'teaching.progress', 'teaching.reports', 'teaching.settings'] as $route) {
            $this->actingAs($outsider)->get(route($route))->assertForbidden();
            auth()->logout();
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_translations_exist_in_both_languages(): void
    {
        $en = \Illuminate\Support\Arr::dot(require lang_path('en/teaching.php'));
        $bn = \Illuminate\Support\Arr::dot(require lang_path('bn/teaching.php'));

        $this->assertSame([], array_diff(array_keys($en), array_keys($bn)));
        $this->assertSame([], array_diff(array_keys($bn), array_keys($en)));

        foreach (glob(resource_path('js/{Pages,Components}/Teaching/*.vue'), GLOB_BRACE) as $file) {
            preg_match_all("/(?<![\w.])t\('([a-z_0-9.]+)'/", file_get_contents($file), $m);
            $this->assertSame([], array_values(array_diff($m[1], array_keys($en))), basename($file));
        }
    }

    public function test_the_demo_seeder_builds_plans_at_different_paces(): void
    {
        TeachingPlan::query()->delete();
        $this->seed(TeachingPlanDemoSeeder::class);

        $this->assertSame(2, TeachingPlan::count());
        $this->assertGreaterThan(5, \App\Models\Teaching\TeachingLog::count());
        $this->assertGreaterThan(0, TeachingPlan::where('done_pct', '>', 0)->count());
        $this->seed(TeachingPlanDemoSeeder::class);                  // twice is harmless
        $this->assertSame(2, TeachingPlan::count());
    }
}
