<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ResultInput;
use App\Models\ResultPolicy;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\TabulationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class TabulationTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $teacher;
    private SchoolClass $class;
    /** @var array<int,Student> */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Class Teacher']);
        $this->class   = $this->makeClass('Class 6', 'A', attrs: ['subjects' => ['Bangla', 'English', 'Math']], teacher: $this->teacher);

        foreach ([1, 2, 3, 4] as $roll) {
            $this->s[$roll] = $this->makeStudent($this->class, "Student {$roll}", ['roll_number' => (string) $roll]);
        }

        // 1: 90/85/80  2: 70/65/60  3: 75/72/20 (fails Math)  4: Math not entered
        $this->marks($this->s[1], ['Bangla' => 90, 'English' => 85, 'Math' => 80]);
        $this->marks($this->s[2], ['Bangla' => 70, 'English' => 65, 'Math' => 60]);
        $this->marks($this->s[3], ['Bangla' => 75, 'English' => 72, 'Math' => 20]);
        $this->marks($this->s[4], ['Bangla' => 80, 'English' => 80]);
    }

    private function marks(Student $student, array $bySubject, string $exam = 'final'): void
    {
        foreach ($bySubject as $subject => $mark) {
            ResultInput::create([
                'student_id' => $student->id, 'class_id' => $student->class_id, 'subject' => $subject, 'exam_type' => $exam,
                'academic_year' => (string) $this->class->academic_year, 'marks' => $mark, 'total_marks' => 0, 'grade' => 'A', 'gpa' => 4,
            ]);
        }
    }

    private function sheet(?SchoolClass $class = null, string $exam = 'final', int $perSheet = 10): array
    {
        return app(TabulationBuilder::class)->build($class ?? $this->class, $exam, $perSheet);
    }

    private function row(array $sheet, int $roll): array
    {
        return collect($sheet['rows'])->firstWhere('roll', (string) $roll);
    }

    // ------------------------------------------------------------------
    // the figures
    // ------------------------------------------------------------------

    public function test_totals_gpa_grade_status_and_position_are_worked_out_from_the_marks(): void
    {
        $sheet = $this->sheet();

        $r1 = $this->row($sheet, 1);
        $this->assertEquals(255, $r1['total']);
        $this->assertSame(5.0, $r1['gpa']);
        $this->assertSame('A+', $r1['grade']);
        $this->assertSame('Passed', $r1['status']);
        $this->assertSame(1, $r1['rank']);

        $r2 = $this->row($sheet, 2);
        $this->assertEquals(195, $r2['total']);
        $this->assertSame(3.67, $r2['gpa']);          // (4 + 3.5 + 3.5) / 3
        $this->assertSame('A-', $r2['grade']);        // best grade not above 3.67
        $this->assertSame(2, $r2['rank']);

        $r3 = $this->row($sheet, 3);                   // failed Math
        $this->assertSame('Failed', $r3['status']);
        $this->assertSame(0.0, $r3['gpa']);
        $this->assertSame('F', $r3['grade']);
        $this->assertSame(3, $r3['rank']);             // after everyone who passed, even with a higher total than #2? no — total 167 < 195
        $this->assertTrue($r3['cells'][Subject::where('name', 'Math')->value('id')]['fail']);

        $r4 = $this->row($sheet, 4);                   // Math missing
        $this->assertSame('Incomplete', $r4['status']);
        $this->assertNull($r4['gpa']);
        $this->assertNull($r4['rank']);
    }

    public function test_a_failed_student_ranks_below_every_passed_student_whatever_the_total(): void
    {
        // make student 3's total the highest of all, but with one failed subject
        ResultInput::where('student_id', $this->s[3]->id)->where('subject', 'Math')->update(['marks' => 32]);
        ResultInput::where('student_id', $this->s[3]->id)->where('subject', 'Bangla')->update(['marks' => 100]);
        ResultInput::where('student_id', $this->s[3]->id)->where('subject', 'English')->update(['marks' => 100]);

        $sheet = $this->sheet();

        $this->assertEquals(232, $this->row($sheet, 3)['total']);   // beats student 2 (195)
        $this->assertSame('Failed', $this->row($sheet, 3)['status']);
        $this->assertSame(3, $this->row($sheet, 3)['rank']);
    }

    public function test_equal_results_share_a_position(): void
    {
        $this->marks($this->s[4], ['Math' => 80]);                      // complete now: 80 / 80 / 80 = 240
        ResultInput::where('student_id', $this->s[1]->id)->update(['marks' => 80]);   // 240 as well

        $sheet = $this->sheet();

        $this->assertSame(1, $this->row($sheet, 1)['rank']);
        $this->assertSame(1, $this->row($sheet, 4)['rank']);
        $this->assertSame(3, $this->row($sheet, 2)['rank']);            // next position skips, as in a real mark list
    }

    public function test_the_highest_mark_of_each_subject_and_the_class_figures(): void
    {
        $sheet = $this->sheet();
        $id = fn (string $n) => Subject::where('name', $n)->value('id');

        $this->assertEquals(90, $sheet['highest'][$id('Bangla')]);
        $this->assertEquals(85, $sheet['highest'][$id('English')]);
        $this->assertEquals(80, $sheet['highest'][$id('Math')]);

        $this->assertSame(4, $sheet['summary']['students']);
        $this->assertSame(3, $sheet['summary']['appeared']);
        $this->assertSame(2, $sheet['summary']['passed']);
        $this->assertSame(1, $sheet['summary']['failed']);
        $this->assertSame(1, $sheet['summary']['incomplete']);
        $this->assertSame(66.7, $sheet['summary']['pass_rate']);
    }

    public function test_each_examination_has_its_own_figures(): void
    {
        $this->marks($this->s[1], ['Bangla' => 40, 'English' => 40, 'Math' => 40], 'first_terminal');

        $this->assertEquals(120, $this->row($this->sheet(null, 'first_terminal'), 1)['total']);
        $this->assertEquals(255, $this->row($this->sheet(null, 'final'), 1)['total']);
        $this->assertSame('Incomplete', $this->row($this->sheet(null, 'first_terminal'), 2)['status']);   // nothing entered yet
    }

    public function test_the_admins_grading_scale_is_what_decides_grades(): void
    {
        // a stricter scale: A+ only from 90
        $scale = \App\Models\GradingScale::create(['name' => 'Strict']);
        $scale->rows()->createMany([
            ['min_percent' => 90, 'grade' => 'A+', 'grade_point' => 5, 'is_fail' => false],
            ['min_percent' => 50, 'grade' => 'B', 'grade_point' => 3, 'is_fail' => false],
            ['min_percent' => 0, 'grade' => 'F', 'grade_point' => 0, 'is_fail' => true],
        ]);
        ResultPolicy::forYear((string) $this->class->academic_year)->update(['grading_scale_id' => $scale->id]);

        $this->assertSame(3.0, $this->row($this->sheet(), 2)['gpa']);     // 70 / 65 / 60 are all "B" now
        $this->assertSame('B', $this->row($this->sheet(), 2)['grade']);
    }

    // ------------------------------------------------------------------
    // optional subject
    // ------------------------------------------------------------------

    public function test_optional_subject_adds_only_the_points_above_the_threshold_and_never_fails_a_student(): void
    {
        $class = $this->makeClass('Class 9', 'A', attrs: ['subjects' => ['Bangla', 'English']], teacher: $this->teacher);
        $class->syncSubjects([['name' => 'Bangla'], ['name' => 'English'], ['name' => 'Higher Math', 'optional' => true]]);

        $a = $this->makeStudent($class, 'Takes HM', ['roll_number' => '1']);
        $b = $this->makeStudent($class, 'No HM', ['roll_number' => '2']);
        $c = $this->makeStudent($class, 'Fails HM', ['roll_number' => '3']);
        $hm = Subject::where('name', 'Higher Math')->value('id');
        foreach ([$a, $c] as $st) {
            DB::table('student_optional_subjects')->insert(['student_id' => $st->id, 'subject_id' => $hm, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach ([$a, $b, $c] as $st) {
            $this->marks($st, ['Bangla' => 60, 'English' => 60]);          // 3.5 + 3.5
        }
        $this->marks($a, ['Higher Math' => 70]);                           // 4.0 -> 2.0 above the threshold
        $this->marks($c, ['Higher Math' => 10]);                           // fail

        $sheet = $this->sheet($class);

        $this->assertSame(4.5, $this->row($sheet, 1)['gpa']);               // (3.5 + 3.5 + 2.0) / 2
        $this->assertSame(3.5, $this->row($sheet, 2)['gpa']);               // does not take it
        $this->assertFalse($this->row($sheet, 2)['cells'][$hm]);            // shown as "not taken"
        $this->assertSame('Passed', $this->row($sheet, 3)['status']);       // an optional fail does not fail the student
        $this->assertSame(3.5, $this->row($sheet, 3)['gpa']);
    }

    // ------------------------------------------------------------------
    // attendance
    // ------------------------------------------------------------------

    private function attendance(Student $student, array $statuses): void
    {
        foreach ($statuses as $i => $status) {
            Attendance::create([
                'student_id' => $student->id, 'class_id' => $student->class_id, 'attendance_date' => now()->subDays($i + 1)->toDateString(),
                'status' => $status, 'academic_year' => (string) $this->class->academic_year,
            ]);
        }
    }

    public function test_attendance_is_counted_from_the_daily_records(): void
    {
        $this->attendance($this->s[1], array_merge(array_fill(0, 9, 'present'), ['absent']));                 // 9 / 10
        $this->attendance($this->s[2], array_merge(array_fill(0, 7, 'present'), ['late', 'leave', 'absent'])); // 8 / 10 (late counts)

        $sheet = $this->sheet();

        $this->assertSame(10, $sheet['working_days']);
        $this->assertSame(9, $this->row($sheet, 1)['present']);
        $this->assertSame(90.0, $this->row($sheet, 1)['att_percent']);
        $this->assertSame(8, $this->row($sheet, 2)['present']);
        $this->assertSame(80.0, $this->row($sheet, 2)['att_percent']);
        $this->assertEquals(0, $this->row($sheet, 1)['att_marks']);          // reported, but earns nothing by default
        $this->assertEquals(255, $this->row($sheet, 1)['total']);
    }

    public function test_attendance_can_carry_marks_when_the_exam_controller_turns_it_on(): void
    {
        $this->attendance($this->s[1], array_merge(array_fill(0, 9, 'present'), ['absent']));
        $this->attendance($this->s[2], array_fill(0, 10, 'present'));

        $controller = $this->makeUser(User::ROLE_EXAM_CONTROLLER);
        $policy     = ResultPolicy::forYear((string) $this->class->academic_year);

        $this->actingAs($controller)->post('/exam-policy', [
            'academic_year' => $policy->academic_year, 'calculation_mode' => $policy->calculation_mode,
            'pass_rule_scope' => $policy->pass_rule_scope, 'require_component_pass' => true,
            'grading_scale_id' => $policy->grading_scale_id, 'optional_rule' => $policy->optional_rule,
            'optional_threshold' => 2, 'attendance_marks' => 10,
        ])->assertSessionHas('success');

        $sheet = $this->sheet();

        $this->assertEquals(10, $sheet['attendance_marks']);
        $this->assertEquals(9, $this->row($sheet, 1)['att_marks']);                     // 90% of 10
        $this->assertEquals(255 + 9, $this->row($sheet, 1)['total']);
        $this->assertEquals(195 + 10, $this->row($sheet, 2)['total']);                  // 100%
        $this->assertSame(1, $this->row($sheet, 1)['rank']);                            // still first: 264 vs 205
    }

    // ------------------------------------------------------------------
    // the printed page
    // ------------------------------------------------------------------

    public function test_the_page_is_a_portrait_a4_sheet_with_the_class_data(): void
    {
        $this->attendance($this->s[1], array_fill(0, 5, 'present'));

        $this->actingAs($this->teacher)->get("/tabulation/class/{$this->class->id}?exam_type=final")
            ->assertOk()
            ->assertSee('size: A4 portrait', false)
            ->assertSee('Tabulation Sheet', false)
            ->assertSee('Final Examination')
            ->assertSee('Class 6')
            ->assertSee('Student 1')->assertSee('Student 3')->assertSee('Student 4')
            ->assertSee('Class Teacher')
            ->assertSee('Highest mark')
            ->assertSee('Working days')
            ->assertSee('Incomplete');
    }

    public function test_a_wide_class_continues_on_further_sheets(): void
    {
        $class = $this->makeClass('Class 8', 'A', attrs: ['subjects' => ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7']], teacher: $this->teacher);
        $this->makeStudent($class, 'Wide Kid', ['roll_number' => '1']);

        // 7 subjects, 3 per sheet -> 3 subject sheets + 1 summary sheet
        $html = $this->actingAs($this->teacher)->get("/tabulation/class/{$class->id}?per_sheet=3")->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, '<section class="sheet">'));
        $this->assertStringContainsString('Sheet 4 of 4', $html);
        $this->assertSame(4, substr_count($html, 'Wide Kid'));             // the student columns repeat on every sheet

        // everything fits one sheet when it can
        $one = $this->actingAs($this->teacher)->get("/tabulation/class/{$class->id}?per_sheet=10")->getContent();
        $this->assertSame(1, substr_count($one, '<section class="sheet">'));
    }

    public function test_the_print_design_is_plain(): void
    {
        $html = $this->actingAs($this->teacher)->get("/tabulation/class/{$this->class->id}")->getContent();

        $this->assertStringNotContainsString('gradient', $html);
        $this->assertStringNotContainsString('box-shadow: 0 0 5px', substr($html, strpos($html, '@media print')));   // no shadow when printed
        $this->assertStringContainsString('table-header-group', $html);                                              // headings repeat on each page
        $this->assertStringContainsString('break-inside: avoid', $html);                                             // a row never splits across pages
    }

    // ------------------------------------------------------------------
    // who may open it
    // ------------------------------------------------------------------

    public function test_only_the_class_teacher_exam_controller_and_admin_may_open_it(): void
    {
        $url = "/tabulation/class/{$this->class->id}";

        $this->actingAs($this->teacher)->get($url)->assertOk();
        $this->actingAs($this->makeUser(User::ROLE_ADMIN))->get($url)->assertOk();
        $this->actingAs($this->makeUser(User::ROLE_EXAM_CONTROLLER))->get($url)->assertOk();

        // another teacher
        $this->actingAs($this->makeUser(User::ROLE_TEACHER))->get($url)->assertForbidden();

        // a teacher who only teaches one subject there
        $subjectTeacher = $this->makeUser(User::ROLE_TEACHER);
        $this->class->assignSubjectTeacher('Math', $subjectTeacher);
        $this->actingAs($subjectTeacher)->get($url)->assertForbidden();

        $this->actingAs($this->makeUser(User::ROLE_CASHIER))->get($url)->assertForbidden();
        \Illuminate\Support\Facades\Auth::guard('web')->logout();      // the cashier above is still signed in on this test's guard
        $this->get($url)->assertRedirect('/login');
    }
}
