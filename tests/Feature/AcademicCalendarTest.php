<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\Exam;
use App\Models\User;
use App\Services\AcademicCalendar;
use App\Services\CalendarEditor;
use App\Services\RoutineService;
use App\Services\Teaching\Insights;
use App\Services\Teaching\PlanService;
use App\Services\Teaching\Scheduler;
use App\Services\TabulationBuilder;
use App\Support\BanglaDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesTeachingData;
use Tests\TestCase;

class AcademicCalendarTest extends TestCase
{
    use RefreshDatabase, MakesTeachingData;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-15 08:00:00');                 // a Sunday
        $this->setUpTeaching();                                     // Sunday–Thursday school week
    }

    private function cal(): AcademicCalendar
    {
        return app(AcademicCalendar::class);
    }

    private function event(array $more = []): CalendarEvent
    {
        return app(CalendarEditor::class)->save($more + ['type' => 'holiday', 'title' => 'Holiday', 'starts_on' => '2026-03-17', 'audience' => 'all'], $this->admin);
    }

    // ---- the Bangla calendar --------------------------------------------------------------------------------------

    public function test_the_bangla_calendar_follows_the_official_rules(): void
    {
        foreach (['2026-04-14' => [1433, 1, 1], '2026-04-13' => [1432, 12, 30], '2026-02-21' => [1432, 11, 8], '2026-03-26' => [1432, 12, 12],
                  '2026-12-16' => [1433, 9, 1], '2026-10-17' => [1433, 7, 1], '2024-02-29' => [1430, 11, 16], '2024-03-14' => [1430, 11, 30], '2025-03-14' => [1431, 11, 29]] as $date => [$y, $m, $d]) {
            $this->assertSame(['year' => $y, 'month' => $m, 'day' => $d], BanglaDate::fromGregorian($date), $date);
            $this->assertSame($date, BanglaDate::toGregorian($y, $m, $d), $date);
        }

        // every day of four years goes there and back
        for ($d = Carbon::parse('2023-01-01'); $d->year < 2027; $d->addDay()) {
            $b = BanglaDate::fromGregorian($d->toDateString());
            $this->assertSame($d->toDateString(), BanglaDate::toGregorian($b['year'], $b['month'], $b['day']));
        }

        $this->assertNull(BanglaDate::toGregorian(1431, 11, 30));                      // that Falgun has 29 days
        $this->assertSame('2026-04-14', BanglaDate::parse('১ বৈশাখ ১৪৩৩'));
        $this->assertSame('2026-03-26', BanglaDate::parse('12 Chaitra 1432'));
        $this->assertSame('2026-03-26', BanglaDate::parse('1432-12-12 BS'));
    }

    // ---- days ------------------------------------------------------------------------------------------------------

    public function test_working_days_follow_off_days_holidays_audiences_and_makeup_days(): void
    {
        $b = $this->makeClass('Class 6', 'B', 2026);
        $this->assertFalse($this->cal()->isWorkingDay('2026-03-13'));                 // Friday
        $this->assertTrue($this->cal()->isWorkingDay('2026-03-17'));

        $this->event();                                                               // whole school
        $this->event(['starts_on' => '2026-03-18', 'audience' => 'classes', 'class_ids' => [$b->id]]);
        $this->event(['starts_on' => '2026-03-19', 'audience' => 'teachers']);        // never closes classes

        $this->assertFalse($this->cal()->isWorkingDay('2026-03-17', $this->class->id));
        $this->assertTrue($this->cal()->isWorkingDay('2026-03-18', $this->class->id));
        $this->assertFalse($this->cal()->isWorkingDay('2026-03-18', $b->id));
        $this->assertTrue($this->cal()->isWorkingDay('2026-03-18'));                  // the school as a whole is open
        $this->assertTrue($this->cal()->isWorkingDay('2026-03-19', $b->id));
        $this->assertSame(4, $this->cal()->countWorkingDays('2026-03-15', '2026-03-21'));
        $this->assertSame(3, $this->cal()->countWorkingDays('2026-03-15', '2026-03-21', $b->id));

        // a make-up day on a Saturday follows Tuesday's routine
        $this->event(['type' => 'makeup', 'title' => 'Make-up', 'starts_on' => '2026-03-21', 'follows_day' => 2]);
        $this->assertTrue($this->cal()->isWorkingDay('2026-03-21'));
        $this->assertSame(2, $this->cal()->routineDay('2026-03-21', $this->class->id));
    }

    public function test_exam_days_are_working_days_but_not_teaching_days(): void
    {
        $exam = Exam::create(['academic_year' => '2026', 'name' => 'Half yearly', 'weight' => 50, 'starts_on' => '2026-03-16', 'ends_on' => '2026-03-17', 'status' => 'draft', 'sort_order' => 1]);

        // the exam's dates are in the calendar by themselves
        $e = CalendarEvent::where('exam_id', $exam->id)->firstOrFail();
        $this->assertSame('exam', $e->type);
        $this->assertSame(5, $this->cal()->countWorkingDays('2026-03-15', '2026-03-19'));
        $this->assertSame(['2026-03-15', '2026-03-18', '2026-03-19'], $this->cal()->teachingDays('2026-03-15', '2026-03-19'));

        // changed in the calendar → the exam changes; changed in the exam → the calendar changes
        app(CalendarEditor::class)->move($e, '2026-03-18', $this->admin);
        $this->assertSame('2026-03-18', $exam->fresh()->starts_on->toDateString());
        $this->assertSame('2026-03-19', $exam->fresh()->ends_on->toDateString());

        $exam->fresh()->update(['starts_on' => '2026-05-03', 'ends_on' => '2026-05-07']);
        $this->assertSame('2026-05-07', $e->fresh()->ends_on->toDateString());
        $exam->fresh()->update(['starts_on' => null, 'ends_on' => null]);
        $this->assertSame(0, CalendarEvent::count());

        $exam->update(['starts_on' => '2026-05-03']);
        $exam->delete();
        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_yearly_events_repeat_by_the_english_or_the_bangla_date(): void
    {
        $this->event(['title' => 'Victory Day', 'starts_on' => '2025-12-16', 'repeat' => 'english']);
        $this->event(['title' => 'Pohela Falgun', 'starts_on' => '2024-02-14', 'repeat' => 'bangla']);        // 1 Falgun
        $find = fn (string $y, string $title) => collect($this->cal()->events("{$y}-01-01", "{$y}-12-31"))->where('title', $title)->pluck('starts_on')->all();

        $this->assertSame(['2027-12-16'], $find('2027', 'Victory Day'));
        $this->assertSame([], $find('2024', 'Victory Day'));                          // not before it began
        $this->assertSame(['2026-02-14'], $find('2026', 'Pohela Falgun'));
        $this->assertSame(['2028-02-14'], $find('2028', 'Pohela Falgun'));

        // one year only can be moved or removed; the others stay
        $v = CalendarEvent::where('title', 'Victory Day')->first();
        $this->actingAs($this->admin)->put(route('calendar.events.update', $v), ['type' => 'holiday', 'title' => 'Victory Day', 'starts_on' => '2027-12-17', 'audience' => 'all', 'year' => 2027])->assertSessionHasNoErrors();
        $this->assertSame(['2027-12-17'], $find('2027', 'Victory Day'));
        $this->assertSame(['2028-12-16'], $find('2028', 'Victory Day'));
        $this->actingAs($this->admin)->delete(route('calendar.events.destroy', $v), ['year' => 2028]);
        $this->assertSame([], $find('2028', 'Victory Day'));
        $this->assertSame(['2029-12-16'], $find('2029', 'Victory Day'));

        // changing "every year" from a later year keeps the earlier years
        $this->actingAs($this->admin)->put(route('calendar.events.update', $v), ['type' => 'holiday', 'title' => 'Bijoy Dibosh', 'starts_on' => '2029-12-16', 'ends_on' => '2029-12-16', 'audience' => 'all', 'repeat' => 'english'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['2026-12-16'], $find('2026', 'Bijoy Dibosh'));
        $this->assertSame('2025-12-16', $v->fresh()->starts_on->toDateString());

        $this->actingAs($this->admin)->post(route('calendar.events.store'), ['type' => 'holiday', 'title' => 'X', 'starts_on' => '2026-05-01', 'audience' => 'classes', 'class_ids' => [$this->class->id], 'repeat' => 'english'])
            ->assertSessionHasErrors('repeat');
    }

    public function test_confirming_a_tentative_date_moves_everything_that_depends_on_it(): void
    {
        $this->routineOn($this->class, 'Mathematics', $this->teacher);
        $this->syllabus();
        $plan = app(PlanService::class)->create($this->class, $this->math, $this->teacher);
        $eid = $this->event(['title' => 'Eid', 'starts_on' => '2026-03-22', 'ends_on' => '2026-03-24', 'tentative' => true]);
        app(Scheduler::class)->auto($plan);
        $this->assertNotNull($plan->fresh()->summary_on);

        $this->actingAs($this->admin)->post(route('calendar.events.confirm', $eid), ['starts_on' => '2026-03-23'])->assertSessionHasNoErrors();

        $eid->refresh();
        $this->assertFalse($eid->tentative);
        $this->assertSame('2026-03-25', $eid->ends_on->toDateString());              // the length is kept
        $this->assertTrue($this->cal()->isWorkingDay('2026-03-22'));
        $this->assertFalse($this->cal()->isWorkingDay('2026-03-25'));
        $this->assertNull($plan->fresh()->summary_on);                                // worked out again when next shown
        $this->assertTrue(AuditLog::where('event', 'cal.confirmed')->exists());
    }

    // ---- the other modules ------------------------------------------------------------------------------------------

    public function test_a_holiday_removes_classes_from_the_daily_log_and_pending_list(): void
    {
        $this->routineOn($this->class, 'Mathematics', $this->teacher);
        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->has('cards', 1));
        $due = fn () => count(app(Insights::class)->pending('2026-03-08', '2026-03-12', $this->teacher->id));
        $this->assertSame(5, $due());

        $this->event(['starts_on' => '2026-03-15']);
        $this->event(['starts_on' => '2026-03-10']);

        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->has('cards', 0));
        $this->assertSame(4, $due());

        $period = app(RoutineService::class)->periodsForClass($this->class)->first();
        $this->actingAs($this->teacher)->putJson(route('teaching.log.save'), ['log_date' => '2026-03-15', 'class_id' => $this->class->id, 'subject_id' => $this->math->id,
            'teacher_id' => $this->teacher->id, 'period_id' => $period->id, 'held' => true, 'coverage' => 100])->assertStatus(422)->assertJsonPath('errors.log.0', fn ($m) => str_contains($m, 'Holiday'));
    }

    public function test_a_makeup_day_lists_the_followed_weekdays_classes(): void
    {
        $this->routineOn($this->class, 'Mathematics', $this->teacher, [2]);           // Tuesdays only
        Carbon::setTestNow('2026-03-21 08:00:00');                                    // a Saturday
        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->has('cards', 0));

        $this->event(['type' => 'makeup', 'title' => 'Make-up', 'starts_on' => '2026-03-21', 'follows_day' => 2]);

        $this->actingAs($this->teacher)->get(route('teaching.log'))->assertInertia(fn (Assert $p) => $p->has('cards', 1));
    }

    public function test_attendance_is_refused_and_not_counted_on_closed_days(): void
    {
        $class = $this->class;
        $student = $this->makeStudent($class);
        $mark = fn (string $date, string $status) => Attendance::create(['student_id' => $student->id, 'class_id' => $class->id, 'attendance_date' => $date, 'status' => $status, 'academic_year' => '2026', 'marked_by' => $this->admin->id]);
        $mark('2026-03-08', 'present');
        $mark('2026-03-09', 'absent');                                                // later declared closed

        $this->event(['type' => 'closure', 'title' => 'Flood', 'starts_on' => '2026-03-09']);

        $this->actingAs($this->admin)->post(route('attendance.store'), ['class_id' => $class->id, 'date' => '2026-03-09', 'attendance' => [['student_id' => $student->id, 'status' => 'absent']]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Flood'));
        // a weekly off day only warns on screen: a class really held can be recorded, and stays counted
        $this->actingAs($this->admin)->post(route('attendance.store'), ['class_id' => $class->id, 'date' => '2026-03-07', 'attendance' => [['student_id' => $student->id, 'status' => 'present']]])
            ->assertSessionHas('success');

        $this->actingAs($this->admin)->get(route('attendance.show', ['classId' => $class->id, 'date' => '2026-03-09']))->assertInertia(fn (Assert $p) => $p
            ->where('schoolDay.working', false)->where('students.data.0.stats.absent_days', fn ($v) => (int) $v === 0)->where('students.data.0.stats.total_days', fn ($v) => (int) $v === 2));

        $json = $this->actingAs($this->admin)->getJson(route('attendance.records', ['class' => $class->id, 'period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-14']))->assertOk()->json();
        $this->assertSame(0, $json['summary']['absent']);
        $this->assertSame(2, $json['summary']['total']);

        $sheet = app(TabulationBuilder::class)->build($class, 'Half yearly');
        $row = $sheet['rows'][0] ?? null;
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('"absent":1', json_encode($row));
    }

    public function test_an_emergency_closure_with_a_makeup_day_updates_teaching_plans(): void
    {
        $this->routineOn($this->class, 'Mathematics', $this->teacher);
        $this->syllabus();
        $plan = app(PlanService::class)->create($this->class, $this->math, $this->teacher);
        app(Scheduler::class)->auto($plan);

        $this->actingAs($this->teacher)->post(route('calendar.closure'), ['reason' => 'Flood', 'starts_on' => '2026-03-16', 'audience' => 'all'])->assertForbidden();
        $this->actingAs($this->head)->post(route('calendar.closure'), ['reason' => 'Flood', 'starts_on' => '2026-03-16', 'ends_on' => '2026-03-26', 'audience' => 'all', 'makeup_on' => '2026-03-20'])->assertSessionHasErrors('makeup_on');

        $this->actingAs($this->head)->post(route('calendar.closure'), ['reason' => 'Flood', 'starts_on' => '2026-03-16', 'ends_on' => '2026-03-19', 'audience' => 'all', 'makeup_on' => '2026-03-21', 'reschedule' => true])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->cal()->isWorkingDay('2026-03-17', $this->class->id));
        $this->assertSame(1, $this->cal()->routineDay('2026-03-21', $this->class->id));          // Monday, the first lost day
        foreach ($plan->items()->get() as $item) {
            $this->assertNotContains($item->starts_on->toDateString(), ['2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19']);
        }
        $this->assertTrue(AuditLog::where('event', 'cal.closure')->exists());
    }

    // ---- many at once ------------------------------------------------------------------------------------------------

    public function test_a_pasted_list_is_checked_then_added_once(): void
    {
        $text = "2026-02-21 | Language Day | Holiday | ভাষা দিবস\n14/06/2026 to 25/06/2026 | Summer vacation | Vacation\n১ বৈশাখ ১৪৩৩ | Pohela Boishakh\nsoon | Bad date | Holiday\n2026-05-01 | =HYPERLINK(1) | Holiday\n2026-05-02 | Thing | Party";

        $rows = $this->actingAs($this->admin)->postJson(route('calendar.bulk.preview'), ['text' => $text])->assertOk()->json('rows');

        $this->assertSame(3, count(array_filter($rows, fn ($r) => ! $r['error'])));
        $this->assertSame('2026-04-14', $rows[2]['data']['starts_on']);
        $this->assertSame(0, CalendarEvent::count());                                  // nothing saved by a preview

        $events = array_values(array_map(fn ($r) => $r['data'], array_filter($rows, fn ($r) => ! $r['error'])));
        $this->actingAs($this->admin)->post(route('calendar.bulk.store'), ['events' => $events])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('calendar.bulk.store'), ['events' => $events])->assertSessionHas('success', '0 dates were added.');
        $this->assertSame(3, CalendarEvent::count());
        $this->assertTrue(CalendarEvent::where('title', 'Summer vacation')->first()->off);

        $csv = UploadedFile::fake()->createWithContent('c.csv', "Date,End date,Title,Type,Title (Bangla)\n2026-09-01,,Sports day,School event,\n");
        $this->actingAs($this->admin)->post(route('calendar.bulk.preview'), ['file' => $csv])->assertOk()->assertJsonPath('rows.0.data.type', 'event');
        $this->actingAs($this->admin)->get(route('calendar.bulk.template'))->assertOk();
    }

    public function test_last_years_calendar_is_copied_to_the_new_year(): void
    {
        $this->event(['title' => 'Eid', 'starts_on' => '2026-03-21']);
        $this->event(['title' => 'Victory Day', 'starts_on' => '2026-12-16', 'repeat' => 'english']);
        $this->event(['title' => 'Class trip', 'type' => 'event', 'starts_on' => '2026-05-03', 'audience' => 'classes', 'class_ids' => [$this->class->id]]);

        $list = $this->actingAs($this->admin)->postJson(route('calendar.copy.preview'), ['from' => 2026])->assertOk()->json('events');
        $this->assertCount(2, $list);                                                  // the yearly one needs no copy
        $this->assertNotNull(collect($list)->firstWhere('data.title', 'Class trip')['skip']);

        $this->actingAs($this->admin)->post(route('calendar.copy.store'), ['from' => 2026, 'ids' => array_column($list, 'id'), 'tentative' => true])->assertSessionHasNoErrors();
        $copy = CalendarEvent::where('starts_on', '2027-03-21')->firstOrFail();
        $this->assertTrue($copy->tentative);
        $this->assertSame(4, CalendarEvent::count());
    }

    // ---- screens and permissions -------------------------------------------------------------------------------------

    public function test_people_see_what_concerns_them(): void
    {
        $b = $this->makeClass('Class 9', 'C', 2026, ['subjects' => ['Mathematics']]);
        $this->event(['title' => 'All', 'type' => 'event']);
        $this->event(['title' => 'Staff', 'type' => 'meeting', 'audience' => 'teachers']);
        $this->event(['title' => 'My class', 'type' => 'event', 'audience' => 'classes', 'class_ids' => [$this->class->id]]);
        $this->event(['title' => 'Other class', 'type' => 'event', 'audience' => 'classes', 'class_ids' => [$b->id]]);
        $see = fn (User $u) => collect($this->cal()->eventsFor($u, '2026-03-01', '2026-03-31'))->pluck('title')->sort()->values()->all();

        $this->assertSame(['All', 'My class', 'Other class', 'Staff'], $see($this->admin));
        $this->assertSame(['All', 'My class', 'Staff'], $see($this->teacher->fresh()));
        $this->assertSame(['All'], $see($this->makeUser()));
    }

    public function test_the_screens_print_and_off_days(): void
    {
        $this->event(['title' => 'Independence Day', 'title_bn' => 'স্বাধীনতা দিবস', 'starts_on' => '2026-03-26']);
        $this->event(['type' => 'term', 'title' => 'First term', 'starts_on' => '2026-01-01', 'ends_on' => '2026-04-30']);

        foreach (['month', 'week', 'year', 'list'] as $view) {
            $this->actingAs($this->teacher)->get(route('calendar.index', ['view' => $view, 'date' => '2026-03-26']))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->component('Calendar/Index')->where('can.manage', false)->has('stats.months', 12)->has('stats.terms', 1));
        }

        $this->actingAs($this->admin)->get(route('calendar.index', ['view' => 'list', 'types' => ['holiday']]))->assertInertia(fn (Assert $p) => $p->has('events', 1));
        $this->actingAs($this->admin)->get(route('calendar.print', ['year' => 2026, 'calendar' => 'bangla']))->assertOk()->assertSee('স্বাধীনতা দিবস')->assertSee('বৈশাখ');
        $this->actingAs($this->admin)->get(route('calendar.print', ['year' => 2026, 'calendar' => 'english']))->assertOk()->assertSee('Independence Day');
        $this->actingAs($this->teacher)->getJson(route('calendar.upcoming'))->assertOk()->assertJsonPath('holiday.title', 'Independence Day');

        $before = $this->cal()->statistics(2026)['total'];
        $this->actingAs($this->admin)->put(route('calendar.off-days'), ['off' => [5]])->assertSessionHasNoErrors();      // Friday only
        $this->assertGreaterThan($before, $this->cal()->statistics(2026)['total']);
        $this->assertContains(6, app(RoutineService::class)->workingDays());          // the routine's setting
    }

    public function test_only_those_allowed_change_the_calendar(): void
    {
        $e = $this->event();
        $data = ['type' => 'holiday', 'title' => 'Hack', 'starts_on' => '2026-04-01', 'audience' => 'all'];

        $this->actingAs($this->teacher)->post(route('calendar.events.store'), $data)->assertForbidden();
        $this->actingAs($this->teacher)->put(route('calendar.events.update', $e), $data)->assertForbidden();
        $this->actingAs($this->teacher)->delete(route('calendar.events.destroy', $e))->assertForbidden();
        $this->actingAs($this->teacher)->post(route('calendar.events.move', $e), ['starts_on' => '2026-04-01'])->assertForbidden();
        $this->actingAs($this->teacher)->post(route('calendar.bulk.store'), ['events' => [$data + ['ends_on' => '2026-04-01']]])->assertForbidden();
        $this->actingAs($this->teacher)->put(route('calendar.off-days'), ['off' => []])->assertForbidden();
        $this->actingAs($this->makeUser())->get(route('calendar.index'))->assertForbidden();
        $this->actingAs($this->makeUser())->getJson(route('calendar.upcoming'))->assertForbidden();
        $this->assertSame(1, CalendarEvent::count());
    }
}
