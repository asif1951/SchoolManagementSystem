<?php

namespace Tests\Feature;

use App\Models\Attendance\Leave;
use App\Models\Attendance\Record;
use App\Models\Attendance\Session;
use App\Models\Student;
use App\Models\User;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\Modes;
use App\Services\Attendance\Periods;
use App\Services\Attendance\Register;
use App\Services\Attendance\Reports;
use App\Services\Attendance\Roster;
use App\Services\Attendance\Settings;
use App\Services\CalendarEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesTeachingData;
use Tests\Concerns\RecordsAttendance;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase, MakesTeachingData, RecordsAttendance;

    private const TODAY = '2026-03-15';                                    // a Sunday

    private Student $a;
    private Student $b;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY . ' 08:00:00');
        $this->setUpTeaching();                                             // Sunday–Thursday; maths by Karim, English by Salma
        $this->class->update(['class_teacher_id' => $this->teacher->id]);
        $this->teacher = $this->teacher->fresh();
        $this->a = $this->pupil('Amina', '1');
        $this->b = $this->pupil('Babul', '2');
    }

    private function pupil(string $name, string $roll, array $more = []): Student
    {
        $s = $this->makeStudent($this->class, $name);
        $s->update($more + ['roll_number' => $roll, 'admission_date' => '2026-01-01']);

        return $s->fresh();
    }

    private function send(User $who, array $statuses, array $more = [])
    {
        return $this->actingAs($who)->post(route('attendance.store'), $more + ['class_id' => $this->class->id, 'date' => self::TODAY,
            'entries' => collect($statuses)->map(fn ($s, $id) => ['student_id' => $id, 'status' => $s])->values()->all()]);
    }

    private function periodMode(string $from = self::TODAY): void
    {
        app(Modes::class)->save($this->class->id, 'period', $from, $this->admin->id);
        $this->routineOn($this->class, 'Mathematics', $this->teacher, [0, 1, 2, 3, 4], 0);
        $this->routineOn($this->class, 'English', $this->other, [0, 1, 2, 3, 4], 1);
    }

    private function slots(): array
    {
        return array_column(app(Register::class)->periods($this->class, self::TODAY), 'period_id');
    }

    private function figures(Student $s, array $f = []): array
    {
        return app(Calculator::class)->students($f + ['from' => '2026-01-01', 'to' => '2026-12-31', 'student_ids' => [$s->id]])[$s->id];
    }

    // ---- daily ----------------------------------------------------------------------------------------------------

    public function test_daily_submission_is_saved_once_corrected_with_history_and_never_duplicated(): void
    {
        $this->send($this->teacher, [$this->a->id => 'present', $this->b->id => 'absent'])->assertSessionHasNoErrors();
        $session = Session::sole();
        $this->assertSame(['daily', $this->teacher->id, 2], [$session->mode, $session->taken_by, $session->records()->count()]);

        // the same screen sent twice does not make a second sheet
        $this->send($this->teacher, [$this->a->id => 'present', $this->b->id => 'present'])->assertSessionHasErrors('attendance');
        $this->send($this->teacher, [$this->a->id => 'present', $this->b->id => 'late'], ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 'late'], [Session::count(), Session::sole()->revision, Record::where('student_id', $this->b->id)->value('status')]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'attendance.edited', 'user_id' => $this->teacher->id]);

        // a stale screen cannot overwrite the correction
        $this->send($this->teacher, [$this->a->id => 'absent', $this->b->id => 'absent'], ['revision' => 1])->assertSessionHasErrors('attendance');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Record::create(['session_id' => $session->id, 'student_id' => $this->a->id, 'class_id' => $this->class->id, 'date' => self::TODAY, 'slot_key' => 0, 'status' => 'present']);
    }

    public function test_every_student_must_be_marked_and_switched_off_statuses_are_refused(): void
    {
        $this->send($this->teacher, [$this->a->id => 'present'])->assertSessionHasErrors('attendance');
        Settings::save(['allow_late' => false]);
        $this->send($this->teacher, [$this->a->id => 'late', $this->b->id => 'present'])->assertSessionHasErrors('attendance');
        $this->assertSame(0, Session::count());
    }

    public function test_the_daily_percentage_is_present_and_late_over_working_days_and_closed_days_are_left_out(): void
    {
        foreach (['2026-03-01' => 'present', '2026-03-02' => 'present', '2026-03-03' => 'late', '2026-03-04' => 'absent', '2026-03-05' => 'leave', '2026-03-08' => 'absent'] as $d => $s) {
            $this->recordAttendance($this->a, $d, $s);
        }

        $this->assertSame(50.0, $this->figures($this->a)['percent']);          // 3 of 6; leave is not present
        // declared closed afterwards: the record stays, the figures leave it out and the admin is told
        app(CalendarEditor::class)->save(['type' => 'closure', 'title' => 'Flood', 'starts_on' => '2026-03-08', 'audience' => 'all'], $this->admin);
        $row = $this->figures($this->a);
        $this->assertSame([5, 60.0, 1], [$row['total'], $row['percent'], $row['leave']]);
        $this->assertSame(6, Record::count());
        $this->assertCount(1, app(Register::class)->conflicts(null, '2026-03-01', '2026-03-31'));
    }

    public function test_the_calendar_decides_the_days_including_make_up_days_and_class_events(): void
    {
        $other = $this->makeClass('Class 7', 'A', 2026);
        app(CalendarEditor::class)->save(['type' => 'holiday', 'title' => 'Trip', 'starts_on' => self::TODAY, 'audience' => 'classes', 'class_ids' => [$this->class->id]], $this->admin);
        $this->send($this->teacher, [$this->a->id => 'present', $this->b->id => 'present'])->assertSessionHasErrors('attendance');
        $this->assertTrue(app(Register::class)->sheet($this->admin, $other, self::TODAY)['day']['working']);

        // Saturday is a weekly off day until a make-up day opens it
        app(CalendarEditor::class)->save(['type' => 'makeup', 'title' => 'Make-up', 'starts_on' => '2026-03-14', 'audience' => 'all', 'follows_day' => 0], $this->admin);
        $this->send($this->teacher, [$this->a->id => 'present', $this->b->id => 'present'], ['date' => '2026-03-14'])->assertSessionHasNoErrors();
        $this->assertSame('makeup', Session::sole()->day_type);
        $this->assertSame(1, $this->figures($this->a)['total']);
    }

    public function test_old_days_need_the_extra_permission(): void
    {
        $marks = [$this->a->id => 'present', $this->b->id => 'present'];
        $this->send($this->teacher, $marks, ['date' => '2026-03-01'])->assertSessionHasErrors('attendance');
        $this->send($this->admin, $marks, ['date' => '2026-03-01'])->assertSessionHasNoErrors();
        $this->send($this->teacher, $marks, ['date' => '2026-03-16'])->assertSessionHasErrors('attendance');

        $this->send($this->teacher, $marks)->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-03-22 08:00:00');
        $this->send($this->teacher, [$this->a->id => 'absent', $this->b->id => 'present'], ['revision' => 1])->assertSessionHasErrors('attendance');
    }

    // ---- period-wise ----------------------------------------------------------------------------------------------

    public function test_periods_come_from_the_routine_and_each_has_its_own_record(): void
    {
        $this->periodMode();
        [$first, $second] = $this->slots();
        $sheet = app(Register::class)->sheet($this->teacher, $this->class, self::TODAY, $first);
        $this->assertSame(['period', 'Mathematics', 'Karim Sir', 'pending'], [$sheet['mode'], $sheet['period']['subject_name'], $sheet['period']['teacher_name'], $sheet['period']['state']]);

        $this->send($this->teacher, [$this->a->id => 'present', $this->b->id => 'present'], ['period_id' => $first])->assertSessionHasNoErrors();
        $this->send($this->other, [$this->a->id => 'present', $this->b->id => 'absent'], ['period_id' => $second])->assertSessionHasNoErrors();

        $day = app(Calculator::class)->days(['from' => self::TODAY, 'to' => self::TODAY, 'student_ids' => [$this->b->id]])[0];
        $this->assertSame(['partial', 2, 1, 1, 50.0], [$day['status'], $day['total'], $day['present'], $day['absent'], $day['percent']]);

        // "class held" reaches the teaching log without a second entry
        $this->assertSame(2, DB::table('teaching_logs')->where('log_date', self::TODAY)->where('held', true)->count());

        // a later timetable change does not rewrite what was recorded
        DB::table('class_routine_slots')->delete();
        $kept = collect(app(Register::class)->periods($this->class, self::TODAY))->firstWhere('period_id', $first);
        $this->assertSame(['Mathematics', 'Karim Sir', 'submitted'], [$kept['subject_name'], $kept['teacher_name'], $kept['state']]);
    }

    public function test_the_period_percentage_and_subject_percentages_use_conducted_periods_only(): void
    {
        $maths = $this->math->id;
        $statuses = ['present', 'present', 'late', 'absent'];

        foreach ($statuses as $i => $s) {
            $this->recordAttendance($this->a, '2026-03-0' . ($i + 1), $s, ['slot_key' => 1, 'period_id' => 1, 'subject_id' => $maths, 'subject_name' => 'Mathematics']);
        }

        $this->recordAttendance($this->a, '2026-03-01', 'absent', ['slot_key' => 2, 'period_id' => 2, 'subject_id' => $maths + 1, 'subject_name' => 'English']);

        $row = $this->figures($this->a);
        $this->assertSame([5, 60.0, 'period'], [$row['total'], $row['percent'], $row['mode']]);
        $this->assertSame(75.0, $this->figures($this->a, ['subject_id' => $maths])['percent']);

        $report = app(Reports::class)->build($this->admin, 'subjects', ['year' => '2026', 'period' => 'month']);
        $this->assertEqualsCanonicalizing([75.0, 0.0], array_column($report['rows'], 'percent'));
    }

    public function test_only_students_who_take_an_optional_subject_are_listed(): void
    {
        $this->periodMode();
        DB::table('class_subjects')->where('class_id', $this->class->id)->where('subject_id', $this->math->id)->update(['is_optional' => true]);
        DB::table('student_optional_subjects')->insert(['student_id' => $this->b->id, 'subject_id' => $this->math->id, 'created_at' => now(), 'updated_at' => now()]);

        $class = $this->class->fresh();
        $this->assertSame([$this->b->id], app(Roster::class)->students($class, self::TODAY, $this->math->id)->pluck('id')->all());
        $this->send($this->teacher, [$this->a->id => 'present', $this->b->id => 'present'], ['period_id' => $this->slots()[0]])->assertSessionHasErrors('attendance');
    }

    public function test_a_substitute_is_recorded_and_strangers_are_refused(): void
    {
        $this->periodMode();
        [$first] = $this->slots();
        $stranger = $this->makeUser(User::ROLE_TEACHER);
        $marks = [$this->a->id => 'present', $this->b->id => 'present'];

        $this->send($stranger, $marks, ['period_id' => $first])->assertSessionHasErrors('attendance');
        $this->send($this->other, $marks, ['period_id' => $first])->assertSessionHasNoErrors();
        $this->assertTrue(Session::sole()->substitute);
        $this->actingAs($stranger)->get(route('attendance.index', ['class' => $this->class->id]))->assertForbidden();
        // a subject teacher does not take the class's daily attendance
        app(Modes::class)->save($this->class->id, 'daily', '2026-03-16', $this->admin->id);
        Carbon::setTestNow('2026-03-16 08:00:00');
        $this->send($this->other->fresh(), $marks, ['date' => '2026-03-16'])->assertSessionHasErrors('attendance');
    }

    // ---- modes ----------------------------------------------------------------------------------------------------

    public function test_the_school_default_class_override_and_history_of_modes(): void
    {
        $modes = app(Modes::class);
        $other = $this->makeClass('Class 7', 'A', 2026);
        $modes->save(0, 'period', '2026-03-20', $this->admin->id);
        $modes->save($this->class->id, 'daily', '2026-03-20', $this->admin->id);

        $this->assertSame('daily', $modes->for($other->id, '2026-03-19'));
        $this->assertSame('period', $modes->for($other->id, '2026-03-20'));
        $this->assertSame('daily', $modes->for($this->class->id, '2026-03-25'));

        $this->recordAttendance($this->a, self::TODAY, 'present');

        foreach ([['2026-03-10', 'period'], [self::TODAY, 'period']] as [$from, $mode]) {
            try {
                $modes->save($this->class->id, $mode, $from, $this->admin->id);
                $this->fail("A mode change from {$from} must be refused.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        // after a change both kinds are kept and counted their own way
        $modes->save($this->class->id, 'period', '2026-03-22', $this->admin->id);
        $this->recordAttendance($this->a, '2026-03-22', 'present', ['slot_key' => 1, 'period_id' => 1]);
        $this->recordAttendance($this->a, '2026-03-22', 'absent', ['slot_key' => 2, 'period_id' => 2]);
        $row = $this->figures($this->a);
        $this->assertSame(['mixed', 100.0, 50.0, 75.0], [$row['mode'], $row['daily']['percent'], $row['period']['percent'], $row['percent']]);
        $this->assertSame('daily', Session::whereDate('date', self::TODAY)->value('mode'));
    }

    // ---- who is counted -------------------------------------------------------------------------------------------

    public function test_late_admissions_leavers_and_section_moves_are_respected(): void
    {
        $late = $this->pupil('Late', '3', ['admission_date' => '2026-03-10']);
        $gone = $this->pupil('Gone', '4');
        $gone->update(['status' => 'dropped', 'status_changed_on' => '2026-03-05']);
        $to = $this->makeClass('Class 6', 'B', 2026);
        $this->b->update(['class_id' => $to->id]);                                  // moved today

        $ids = fn ($class, $date) => app(Roster::class)->students($class, $date)->pluck('id')->all();
        $this->assertSame([$this->a->id, $this->b->id, $gone->id], $ids($this->class, '2026-03-05'));
        $this->assertSame([$this->a->id, $this->b->id, $late->id], $ids($this->class, '2026-03-12'));
        $this->assertSame([$this->a->id, $late->id], $ids($this->class, self::TODAY));
        $this->assertSame([], $ids($to, '2026-03-12'));
        $this->assertSame([$this->b->id], $ids($to, self::TODAY));
    }

    // ---- leave ----------------------------------------------------------------------------------------------------

    public function test_approved_leave_marks_the_sheet_changes_absences_and_can_be_undone(): void
    {
        $this->recordAttendance($this->a, '2026-03-12', 'absent');

        $this->actingAs($this->teacher)->post(route('attendance.leave.store'), ['student_id' => $this->a->id, 'type' => 'Sick', 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-17', 'reason' => 'Fever'])->assertSessionHasNoErrors();
        $leave = Leave::sole();
        $this->actingAs($this->teacher)->post(route('attendance.leave.decide', $leave), ['status' => 'approved'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('attendance.leave.decide', $leave), ['status' => 'approved'])->assertSessionHasNoErrors();

        $this->assertSame('leave', Record::sole()->status);
        $sheet = app(Register::class)->sheet($this->teacher, $this->class, self::TODAY);
        $this->assertSame(['leave', 'present'], array_column($sheet['students'], 'status'));
        $this->assertSame(1, Record::count());                                       // nothing is written for days not taken
        $this->assertSame(0.0, $this->figures($this->a)['percent']);

        $this->actingAs($this->admin)->post(route('attendance.leave.decide', $leave), ['status' => 'cancelled'])->assertSessionHasNoErrors();
        $this->assertSame('absent', Record::sole()->status);

        $this->actingAs($this->teacher)->post(route('attendance.leave.store'), ['student_id' => $this->a->id, 'type' => 'Holiday', 'starts_on' => '2026-03-20', 'ends_on' => '2026-03-20', 'reason' => 'x'])->assertSessionHasErrors();
    }

    // ---- reports --------------------------------------------------------------------------------------------------

    public function test_reports_filter_and_half_years_follow_terms_or_working_days(): void
    {
        foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $d) {
            $this->recordAttendance($this->a, $d, 'present');
            $this->recordAttendance($this->b, $d, 'absent');
        }

        $reports = app(Reports::class);
        $build = fn (string $type, array $f = []) => $reports->build($this->admin, $type, $f + ['year' => '2026', 'period' => 'month']);

        $this->assertSame(['Babul'], array_column($build('low')['rows'], 'name'));
        $this->assertSame(['Amina'], array_column($build('perfect')['rows'], 'name'));
        $this->assertSame([3], array_column($build('consecutive')['rows'], 'longest'));
        $this->assertCount(3, $build('status', ['status' => 'absent'])['rows']);
        $this->assertCount(3, $build('summary')['rows']);
        $this->assertSame(50.0, $build('classes')['rows'][0]['percent']);
        $this->assertCount(1, $build('students', ['student_id' => $this->a->id])['rows']);
        $this->assertSame(0, $build('summary', ['period' => 'custom', 'from' => '2026-04-01', 'to' => '2026-04-30'])['cards']['total']);

        $periods = app(Periods::class);
        $halves = $periods->parts('2026');
        $this->assertCount(2, $halves);
        $this->assertSame(['2026-01-01', '2026-12-31'], [$halves[0]['from'], $halves[1]['to']]);
        $this->assertSame(['2026-01-01', '2026-12-31'], array_values(array_slice($periods->resolve('year', '2026'), 0, 2)));
        app(CalendarEditor::class)->save(['type' => 'term', 'title' => 'Spring', 'starts_on' => '2026-02-01', 'ends_on' => '2026-05-31', 'audience' => 'all'], $this->admin);
        $this->assertSame(['2026-02-01', '2026-05-31', 'Spring'], array_values($periods->resolve('half', '2026')));

        // a teacher reports only on their classes
        $this->makeStudent($this->makeClass('Class 9', 'A', 2026), 'Hidden');
        $this->assertSame([$this->class->id], $reports->classes($this->teacher, ['year' => '2026'])->pluck('id')->all());
    }

    public function test_the_pages_exports_and_printed_documents_work(): void
    {
        $this->recordAttendance($this->a, self::TODAY, 'present');
        $this->periodMode('2026-03-16');
        $this->actingAs($this->admin);

        foreach (['attendance.index' => 'Attendance/Take', 'attendance.records' => 'Attendance/Records', 'attendance.register' => 'Attendance/Register',
                  'attendance.leave' => 'Attendance/Leave', 'attendance.reports' => 'Attendance/Reports', 'attendance.settings' => 'Attendance/Settings'] as $name => $component) {
            $this->get(route($name))->assertOk()->assertInertia(fn (Assert $p) => $p->component($component));
        }

        $this->get(route('attendance.register', ['class' => $this->class->id, 'month' => '2026-03']))->assertInertia(fn (Assert $p) => $p->has('register.students', 2)->has('register.days', 31));

        foreach (array_keys(Reports::TYPES) as $type) {
            $this->get(route('attendance.reports', ['type' => $type, 'period' => 'year', 'sort' => 'name']))->assertOk();
        }

        $this->get(route('attendance.print.sheet', ['type' => 'daily', 'class' => $this->class->id, 'date' => self::TODAY]))->assertOk()->assertSee('Daily Attendance Sheet')->assertSeeInOrder(['Amina', 'Babul'])->assertSee('NotoSansBengali');
        $first = app(Register::class)->periods($this->class, '2026-03-16')[0]['period_id'];
        $this->get(route('attendance.print.sheet', ['type' => 'period', 'class' => $this->class->id, 'date' => '2026-03-16', 'period' => $first]))->assertOk()->assertSee('Mathematics')->assertSee('Karim Sir');
        $this->get(route('attendance.print.sheet', ['type' => 'fullday', 'class' => $this->class->id, 'date' => '2026-03-16', 'cal' => 'both']))->assertOk()->assertSee('P2')->assertSee('LV = Leave');
        $this->get(route('attendance.print.register', ['class' => $this->class->id, 'month' => '2026-03']))->assertOk()->assertSee('Amina');
        $this->get(route('attendance.print.report', ['type' => 'students', 'class_id' => $this->class->id]))->assertOk()->assertSee('Generated on');
        $this->get(route('attendance.reports.export', ['type' => 'students', 'format' => 'csv', 'period' => 'month']))->assertOk()->assertDownload();
        $this->getJson(route('attendance.student', $this->a))->assertOk()->assertJsonPath('summary.present', 1);
        $this->getJson(route('attendance.today'))->assertOk()->assertJsonPath('date', self::TODAY);

        $this->withSession(['locale' => 'bn'])->get(route('attendance.print.sheet', ['type' => 'daily', 'class' => $this->class->id, 'cal' => 'bangla']))->assertSee('দৈনিক হাজিরা শিট');
    }

    public function test_permissions_keep_people_out(): void
    {
        $cashier = $this->makeUser('cashier');
        $this->actingAs($cashier)->get(route('attendance.index'))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('attendance.settings'))->assertForbidden();
        $far = $this->makeClass('Class 9', 'B', 2026);
        $this->actingAs($this->teacher)->get(route('attendance.print.sheet', ['type' => 'daily', 'class' => $far->id]))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('attendance.student', $this->makeStudent($far)))->assertForbidden();
    }

    public function test_old_history_was_carried_over_by_the_migration_shape(): void
    {
        $this->actingAs($this->admin)->put(route('attendance.settings.update'), Settings::all())->assertSessionHasNoErrors();
        $this->assertSame(Settings::defaults()['low_threshold'], Settings::get('low_threshold'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('attendances'));
    }
}
