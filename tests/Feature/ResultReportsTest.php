<?php

namespace Tests\Feature;

use App\Models\GradingScale;
use App\Models\ResultInput;
use App\Models\ResultPolicy;
use App\Models\SchoolClass;
use App\Models\Shift;
use App\Models\Student;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Services\TabulationBuilder;
use Illuminate\Support\Facades\Storage;
use App\Services\ResultReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class ResultReportsTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData, \Tests\Concerns\RecordsAttendance;

    private User $teacher;
    private User $admin;
    private SchoolClass $class;
    /** @var array<int,Student> */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = $this->makeUser(User::ROLE_ADMIN);
        $this->teacher = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Class Teacher']);
        $this->class   = $this->makeClass('Class 6', 'A', attrs: ['subjects' => ['Bangla', 'English', 'Math']], teacher: $this->teacher);

        foreach ([1, 2, 3, 4] as $roll) {
            $this->s[$roll] = $this->makeStudent($this->class, "Student {$roll}", ['roll_number' => (string) $roll, 'father_name' => "Father {$roll}"]);
        }

        // 1: 90/85/80   2: 70/65/60   3: 75/72/20 (fails Math)   4: Math not entered
        $this->marks($this->s[1], ['Bangla' => 90, 'English' => 85, 'Math' => 80]);
        $this->marks($this->s[2], ['Bangla' => 70, 'English' => 65, 'Math' => 60]);
        $this->marks($this->s[3], ['Bangla' => 75, 'English' => 72, 'Math' => 20]);
        $this->marks($this->s[4], ['Bangla' => 80, 'English' => 80]);

        $this->class->assignSubjectTeacher('Math', $this->makeUser(User::ROLE_TEACHER, ['name' => 'Math Sir']));
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

    private function open(string $url, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->get($url);
    }

    // ------------------------------------------------------------------
    // marksheet
    // ------------------------------------------------------------------

    public function test_a_marksheet_shows_the_students_marks_grades_and_result(): void
    {
        $this->open("/reports/marksheet/student/{$this->s[2]->id}")->assertOk()
            ->assertSee('Marksheet — Final Examination, ' . $this->class->academic_year)
            ->assertSee('Student 2')->assertSee('Father 2')->assertSee($this->s[2]->student_id)
            ->assertSee('Bangla')->assertSee('English')->assertSee('Math')
            ->assertSee('Total of subjects')
            ->assertSee('195')                      // 70 + 65 + 60
            ->assertSee('3.67')                     // GPA
            ->assertSee('2 of 3')                   // position among the three whose result is complete
            ->assertSee('Passed')
            ->assertSee('size: A4 portrait', false);
    }

    public function test_a_failed_students_marksheet_says_where_they_failed(): void
    {
        $this->open("/reports/marksheet/student/{$this->s[3]->id}")->assertOk()
            ->assertSee('Failed in Math')
            ->assertSee('20*', false);              // the failing mark is starred
    }

    public function test_a_marksheet_with_missing_marks_is_withheld_and_names_the_subject(): void
    {
        $this->open("/reports/marksheet/student/{$this->s[4]->id}")->assertOk()
            ->assertSee('Result withheld: marks not entered for Math')
            ->assertSee('Incomplete');
    }

    public function test_attendance_marks_appear_on_the_marksheet_when_they_count(): void
    {
        foreach (range(1, 10) as $d) {
            $this->recordAttendance($this->s[1], now()->subDays($d)->toDateString(), $d <= 9 ? 'present' : 'absent');
        }

        $this->open("/reports/marksheet/student/{$this->s[1]->id}")->assertOk()->assertDontSee('Attendance marks')->assertSee('90%');

        ResultPolicy::forYear((string) $this->class->academic_year)->update(['attendance_marks' => 10]);

        $this->open("/reports/marksheet/student/{$this->s[1]->id}")->assertOk()
            ->assertSee('Attendance marks')->assertSee('Grand total')
            ->assertSee('264');                      // 255 + 9
    }

    public function test_the_whole_class_prints_one_page_per_student_and_can_be_limited(): void
    {
        $html = $this->open("/reports/marksheet/class/{$this->class->id}")->assertOk()->getContent();
        $this->assertSame(4, substr_count($html, '<section class="sheet">'));

        $some = $this->open("/reports/marksheet/class/{$this->class->id}?students[]={$this->s[1]->id}&students[]={$this->s[3]->id}")->getContent();
        $this->assertSame(2, substr_count($some, '<section class="sheet">'));
        $this->assertStringContainsString('Student 3', $some);
        $this->assertStringNotContainsString('Student 2', $some);
    }

    // ------------------------------------------------------------------
    // class summary
    // ------------------------------------------------------------------

    public function test_the_class_summary_data_is_worked_out_from_the_marks(): void
    {
        $s = app(ResultReports::class)->classSummary($this->class, 'final');

        $this->assertSame(4, $s['summary']['students']);
        $this->assertSame(3, $s['summary']['appeared']);
        $this->assertSame(2, $s['summary']['passed']);
        $this->assertSame(1, $s['summary']['failed']);
        $this->assertSame(1, $s['summary']['incomplete']);
        $this->assertSame(66.7, $s['summary']['pass_rate']);
        $this->assertEquals(255, $s['summary']['highest_total']);
        $this->assertSame(5.0, $s['summary']['highest_gpa']);

        // grade spread covers the three students with a complete result: A+ (5.00), A- (3.67), F
        $spread = collect($s['grades'])->pluck('count', 'grade');
        $this->assertSame(1, $spread['A+']);
        $this->assertSame(1, $spread['A-']);
        $this->assertSame(1, $spread['F']);
        $this->assertSame(3, $spread->sum());

        // subject by subject
        $math = collect($s['subjects'])->firstWhere('name', 'Math');
        $this->assertSame('Math Sir', $math['teacher']);
        $this->assertSame(4, $math['taking']);
        $this->assertSame(3, $math['appeared']);            // one mark missing
        $this->assertSame(1, $math['failed']);
        $this->assertSame(66.7, $math['pass_rate']);
        $this->assertEquals(80, $math['highest']);
        $this->assertEquals(20, $math['lowest']);
        $this->assertEquals(53.3, $math['average']);

        // merit list: passed students only, best first; failures name the subject
        $this->assertSame([1, 2], collect($s['merit'])->pluck('rank')->all());
        $this->assertSame('Student 3', $s['failures'][0]['name']);
        $this->assertSame('Math', $s['failures'][0]['subjects']);
    }

    public function test_the_class_summary_page(): void
    {
        $this->open("/reports/summary/class/{$this->class->id}")->assertOk()
            ->assertSee('Result Summary — Final Examination')
            ->assertSee('Result at a glance')->assertSee('Grade distribution')->assertSee('Subject-wise result')
            ->assertSee('Merit list')->assertSee('Students who failed')
            ->assertSee('Math Sir')->assertSee('66.7%')
            ->assertSee('size: A4 portrait', false);
    }

    // ------------------------------------------------------------------
    // class-wise result
    // ------------------------------------------------------------------

    private function secondClass(): SchoolClass
    {
        $other = $this->makeClass('Class 6', 'B', attrs: ['subjects' => ['Bangla', 'English', 'Math']], teacher: $this->makeUser(User::ROLE_TEACHER, ['name' => 'Other Teacher']));
        $kid   = $this->makeStudent($other, 'Kid B', ['roll_number' => '1']);
        $this->marks($kid, ['Bangla' => 60, 'English' => 60, 'Math' => 60]);
        $kid->update(['class_id' => $other->id]);
        ResultInput::where('student_id', $kid->id)->update(['class_id' => $other->id]);

        return $other;
    }

    public function test_class_wise_result_adds_up_every_class_with_subtotals_and_a_grand_total(): void
    {
        $this->secondClass();
        $year = $this->class->academic_year;

        $r = app(ResultReports::class)->schoolResult((string) $year, 'final', SchoolClass::where('academic_year', $year)->get());

        $this->assertSame(2, $r['class_count']);
        $this->assertCount(1, $r['blocks']);                                // both sections are "Class 6"
        $this->assertSame(['A', 'B'], collect($r['blocks'][0]['entries'])->pluck('section')->all());
        $this->assertSame(5, $r['blocks'][0]['subtotal']['students']);       // 4 + 1
        $this->assertSame(5, $r['total']['students']);
        $this->assertSame(3, $r['total']['passed']);
        $this->assertSame(1, $r['total']['failed']);
        $this->assertSame(1, $r['total']['incomplete']);

        $a = $r['blocks'][0]['entries'][0];
        $this->assertSame('Student 1', $a['topper']['name']);
        $this->assertSame(66.7, $a['pass_rate']);

        $this->open("/reports/class-wise?academic_year={$year}")->assertOk()
            ->assertSee('Class-wise Result — Final Examination')
            ->assertSee('Class 6 — all sections')->assertSee('Total — 2 classes')
            ->assertSee('Class Teacher')->assertSee('Other Teacher');
    }

    public function test_a_wide_table_prints_landscape_and_a_narrow_one_portrait(): void
    {
        $year = $this->class->academic_year;

        // the default scale has six grades: 11 + 7 columns, too wide for portrait
        $this->open("/reports/class-wise?academic_year={$year}")->assertSee('size: A4 landscape', false)->assertSee('Automatic (landscape)');

        // the toolbar can force either direction
        $this->open("/reports/class-wise?academic_year={$year}&orientation=portrait")->assertSee('size: A4 portrait', false);
        $this->open("/reports/summary/class/{$this->class->id}?orientation=landscape")->assertSee('size: A4 landscape', false);

        // a plain pass / fail scale has only two grade columns: the table fits portrait
        $scale = GradingScale::create(['name' => 'Pass or fail']);
        $scale->rows()->createMany([
            ['min_percent' => 40, 'grade' => 'P', 'grade_point' => 1, 'is_fail' => false],
            ['min_percent' => 0, 'grade' => 'F', 'grade_point' => 0, 'is_fail' => true],
        ]);
        ResultPolicy::forYear((string) $year)->update(['grading_scale_id' => $scale->id]);

        $this->open("/reports/class-wise?academic_year={$year}")->assertSee('size: A4 portrait', false)->assertSee('Automatic (portrait)');
    }

    public function test_class_wise_result_filters_by_class_and_shift(): void
    {
        $year  = $this->class->academic_year;
        $day   = Shift::where('name', 'Day')->firstOrFail();
        $dayClass = $this->makeClass('Class 7', 'A', attrs: ['shift_id' => $day->id], teacher: $this->makeUser(User::ROLE_TEACHER, ['name' => 'Day Teacher']));
        $this->makeStudent($dayClass, 'Day Kid');

        $all = $this->open("/reports/class-wise?academic_year={$year}")->assertSee('Class 7')->assertSee('Class 6');

        $this->open("/reports/class-wise?academic_year={$year}&shift={$day->id}")->assertOk()
            ->assertSee('Day Teacher')->assertDontSee('Class Teacher</td>', false)
            ->assertSee('Showing:')->assertSee('Day');

        $this->open("/reports/class-wise?academic_year={$year}&class_name=Class 6")->assertOk()
            ->assertDontSee('Day Teacher');
    }

    public function test_class_wise_result_with_no_classes_says_so(): void
    {
        $this->open('/reports/class-wise?academic_year=2001')->assertOk()->assertSee('No classes to show');
    }

    // ------------------------------------------------------------------
    // grading table, logo, photo, border
    // ------------------------------------------------------------------

    public function test_every_result_document_shows_the_grading_table(): void
    {
        $urls = [
            "/reports/marksheet/student/{$this->s[1]->id}",
            "/reports/summary/class/{$this->class->id}",
            "/reports/class-wise?academic_year={$this->class->academic_year}",
            "/tabulation/class/{$this->class->id}",
        ];

        foreach ($urls as $url) {
            $this->open($url)->assertOk()
                ->assertSee('Grading system')->assertSee('Letter grade')->assertSee('Grade point')
                ->assertSee('80 – 100')->assertSee('70 – 79')->assertSee('33 – 39')->assertSee('0 – 32');
        }
    }

    public function test_grading_ranges_follow_the_schools_own_scale(): void
    {
        $scale = GradingScale::create(['name' => 'Custom']);
        $scale->rows()->createMany([
            ['min_percent' => 90, 'grade' => 'A', 'grade_point' => 4, 'is_fail' => false],
            ['min_percent' => 49.5, 'grade' => 'B', 'grade_point' => 3, 'is_fail' => false],
            ['min_percent' => 0, 'grade' => 'F', 'grade_point' => 0, 'is_fail' => true],
        ]);

        $table = app(TabulationBuilder::class)->gradingTable($scale->load('rows'));

        $this->assertSame(['90 – 100', '49.5 – below 90', '0 – below 49.5'], array_column($table, 'range'));
        $this->assertSame(['A', 'B', 'F'], array_column($table, 'grade'));
        $this->assertTrue($table[2]['is_fail']);
    }

    public function test_the_logo_and_the_students_photo_are_printed_when_they_exist(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('website/logo/school.png', 'x');
        Storage::disk('public')->put('students/kid.jpg', 'x');

        WebsiteSetting::current()->update(['school_logo' => 'website/logo/school.png']);
        $this->s[1]->update(['student_photo' => 'students/kid.jpg']);

        $html = $this->open("/reports/marksheet/student/{$this->s[1]->id}")->assertOk()->getContent();

        $this->assertStringContainsString('storage/website/logo/school.png', $html);
        $this->assertStringContainsString('storage/students/kid.jpg', $html);
        $this->assertStringNotContainsString('photo empty', $html);

        // student 2 has no photo: an empty frame is printed for one to be pasted in
        $other = $this->open("/reports/marksheet/student/{$this->s[2]->id}")->assertOk()->getContent();
        $this->assertStringContainsString('photo empty', $other);
        $this->assertStringNotContainsString('storage/students/kid.jpg', $other);

        // and sits faintly behind every marksheet page
        $this->assertStringContainsString('class="wm"', $html);
        $all = $this->open("/reports/marksheet/class/{$this->class->id}")->getContent();
        $this->assertSame(4, substr_count($all, 'class="wm"'));          // one per student page

        // the logo also heads the other documents
        $this->open("/reports/summary/class/{$this->class->id}")->assertSee('storage/website/logo/school.png', false);
        $this->open("/tabulation/class/{$this->class->id}")->assertSee('storage/website/logo/school.png', false);
    }

    public function test_a_logo_or_photo_whose_file_is_gone_is_left_out_instead_of_breaking_the_page(): void
    {
        Storage::fake('public');

        WebsiteSetting::current()->update(['school_logo' => 'website/logo/missing.png']);
        $this->s[1]->update(['student_photo' => 'students/missing.jpg']);

        $this->open("/reports/marksheet/student/{$this->s[1]->id}")->assertOk()
            ->assertDontSee('class="wm"', false)                 // no logo, no watermark
            ->assertDontSee('missing.png')->assertDontSee('missing.jpg')
            ->assertSee('No school logo yet');                 // the screen-only reminder
    }

    public function test_the_page_border_uses_the_colour_chosen_in_settings(): void
    {
        $urls = ["/reports/marksheet/student/{$this->s[1]->id}", "/reports/summary/class/{$this->class->id}", "/tabulation/class/{$this->class->id}"];

        foreach ($urls as $url) {
            $this->open($url)->assertSee('--frame: #1f3a5f', false)->assertSee('class="frame"', false);   // the default
        }

        WebsiteSetting::current()->update(['print_color' => '#8b0000']);

        foreach ($urls as $url) {
            $this->open($url)->assertSee('--frame: #8b0000', false);
        }

        // a damaged value in the database can never reach the stylesheet
        WebsiteSetting::query()->update(['print_color' => '#zz;}<s']);
        $this->open($urls[0])->assertSee('--frame: #1f3a5f', false)->assertDontSee('<script>', false);
    }

    public function test_the_border_colour_is_set_from_the_website_settings_screen(): void
    {
        $post = fn (array $over) => $this->actingAs($this->admin)->post(route('website-management.settings.update'), array_merge(['school_name' => 'Test School'], $over));

        $post(['print_color' => '#006400'])->assertSessionHasNoErrors();
        $this->assertSame('#006400', WebsiteSetting::current()->print_color);

        $post(['print_color' => 'red'])->assertSessionHasErrors('print_color');
        $post(['print_color' => '#12345'])->assertSessionHasErrors('print_color');
        $this->assertSame('#006400', WebsiteSetting::current()->print_color);

        $post(['print_color' => ''])->assertSessionHasNoErrors();              // cleared: back to the default
        $this->assertSame('#1f3a5f', WebsiteSetting::current()->print_color);
    }

    // ------------------------------------------------------------------
    // who may open what
    // ------------------------------------------------------------------

    public function test_only_the_class_teacher_exam_controller_and_admin_may_open_the_documents(): void
    {
        $urls = [
            "/reports/marksheet/student/{$this->s[1]->id}",
            "/reports/marksheet/class/{$this->class->id}",
            "/reports/summary/class/{$this->class->id}",
        ];

        foreach ($urls as $url) {
            $this->open($url, $this->teacher)->assertOk();
            $this->open($url, $this->admin)->assertOk();
            $this->open($url, $this->makeUser(User::ROLE_EXAM_CONTROLLER))->assertOk();

            $this->open($url, $this->makeUser(User::ROLE_TEACHER))->assertForbidden();        // another class's teacher
            $this->open($url, $this->makeUser(User::ROLE_CASHIER))->assertForbidden();
        }

        $mathTeacher = User::where('name', 'Math Sir')->firstOrFail();
        $this->open($urls[2], $mathTeacher)->assertForbidden();                                // a subject teacher sees only their subject

        \Illuminate\Support\Facades\Auth::guard('web')->logout();
        $this->get($urls[0])->assertRedirect('/login');
    }

    public function test_a_class_teachers_class_wise_result_holds_only_their_own_class(): void
    {
        $this->secondClass();                                      // Class 6 B belongs to someone else

        $html = $this->open("/reports/class-wise?academic_year={$this->class->academic_year}", $this->teacher)->assertOk()->getContent();

        $this->assertStringContainsString('Class Teacher', $html);
        $this->assertStringNotContainsString('Other Teacher', $html);
        $this->assertStringContainsString('Total — 1 class', $html);

        $this->open("/reports/class-wise?academic_year={$this->class->academic_year}", $this->makeUser(User::ROLE_CASHIER))->assertForbidden();
    }
}
