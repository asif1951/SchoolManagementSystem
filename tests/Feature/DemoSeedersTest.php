<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudyGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeedersTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();

        config(['demo.teachers' => 30, 'demo.students_per_section' => 4, 'demo.year' => null]);
        $this->year = (int) now()->year;

        $this->seed(DemoDataSeeder::class);
    }

    public function test_the_standard_subject_groups_and_curriculum_are_created(): void
    {
        $this->assertGreaterThanOrEqual(24, Subject::count());
        $this->assertEqualsCanonicalizing(['Science', 'Commerce', 'Arts'], StudyGroup::pluck('name')->all());
        $names = Subject::pluck('name')->map(fn ($n) => mb_strtolower($n));
        $this->assertSame($names->count(), $names->unique()->count(), 'no subject appears twice');
    }

    public function test_teachers_have_accounts_and_public_profiles(): void
    {
        $teachers = User::where('role', 'teacher')->where('email', 'like', '%@school.test')->get();

        $this->assertCount(30, $teachers);
        $this->assertSame(30, Teacher::count());
        $this->assertSame(30, $teachers->pluck('email')->unique()->count());
        $this->assertTrue($teachers->every(fn ($t) => $t->status === 'active' && $t->email_verified_at !== null));
        $this->assertGreaterThan(10, Teacher::distinct('department')->count('department'));   // many different specialties
    }

    public function test_the_classes_follow_the_bangladeshi_structure(): void
    {
        $classes = SchoolClass::where('academic_year', (string) $this->year)->get();

        // Class 1-8: sections A and B (16)  +  Class 9-10: A and B in each of 3 groups (12)
        $this->assertCount(28, $classes);
        $this->assertSame(16, $classes->whereNull('study_group_id')->count());

        foreach ($classes->whereNotNull('study_group_id') as $class) {
            $this->assertContains($class->class_name, ['Class 9', 'Class 10']);
        }

        $this->assertTrue($classes->every(fn ($c) => $c->class_teacher_id !== null), 'every class has a class teacher');
        $this->assertTrue($classes->every(fn ($c) => count($c->subjects) >= 6), 'every class has its subjects');
    }

    public function test_group_classes_get_the_right_subjects(): void
    {
        $subjectsOf = fn (string $group) => SchoolClass::where('class_name', 'Class 9')
            ->whereHas('studyGroup', fn ($q) => $q->where('name', $group))->firstOrFail()->subjects;

        $this->assertContains('Physics', $subjectsOf('Science'));
        $this->assertNotContains('Accounting', $subjectsOf('Science'));
        $this->assertContains('Accounting', $subjectsOf('Commerce'));
        $this->assertContains('Economics', $subjectsOf('Arts'));
        $this->assertContains('Bangla', $subjectsOf('Arts'));
    }

    public function test_every_subject_of_every_class_has_a_subject_teacher(): void
    {
        foreach (SchoolClass::where('academic_year', (string) $this->year)->get() as $class) {
            $current = $class->currentSubjectTeachers();

            foreach ($class->subjects as $subject) {
                $this->assertTrue($current->has($subject), "{$class->class_name} {$class->section} {$class->group_name}: {$subject} has no teacher");
            }
        }
    }

    public function test_a_teacher_teaches_the_same_subject_in_several_classes(): void
    {
        $math = Teacher::where('department', 'Mathematics')->first();
        $user = User::where('email', $math->email)->firstOrFail();

        $classes = $user->taughtSubjects();

        $this->assertGreaterThan(1, count($classes), 'a Math teacher teaches in more than one class');
        $this->assertTrue(collect($classes)->flatten()->every(fn ($s) => in_array($s, ['Mathematics', 'General Mathematics'], true)));
    }

    public function test_students_are_complete_and_consistent(): void
    {
        $students = Student::with('schoolClass')->where('academic_year', $this->year)->get();

        $this->assertCount(28 * 4, $students);
        $this->assertSame($students->count(), $students->pluck('student_id')->unique()->count());
        $this->assertSame($students->count(), $students->pluck('public_token')->unique()->count());

        foreach ($students as $s) {
            $this->assertNotEmpty($s->student_name);
            $this->assertNotEmpty($s->father_name);
            $this->assertMatchesRegularExpression('/^01[3-9]\d{8}$/', $s->father_phone);
            $this->assertSame($s->schoolClass->study_group_id, $s->study_group_id);
            $this->assertContains($s->gender, ['male', 'female']);
        }

        // roll numbers run 1..4 inside each class
        foreach ($students->groupBy('class_id') as $group) {
            $this->assertEquals([1, 2, 3, 4], $group->pluck('roll_number')->map(fn ($r) => (int) $r)->sort()->values()->all());
        }
    }

    public function test_optional_subject_choices_are_valid(): void
    {
        $rows = DB::table('student_optional_subjects')->get();
        $this->assertGreaterThan(0, $rows->count());

        foreach ($rows as $row) {
            $student = Student::with('schoolClass')->findOrFail($row->student_id);
            $plan    = collect($student->schoolClass->subjectPlan())->firstWhere('subject_id', $row->subject_id);

            $this->assertNotNull($plan, 'the class offers the chosen subject');
            $this->assertTrue($plan['is_optional']);
            $this->assertContains($plan['name'], array_merge($student->subjectsTaken(), []), 'the student takes what they chose');
        }

        // nobody picked more than one optional subject
        $this->assertSame(0, DB::table('student_optional_subjects')->select('student_id')->groupBy('student_id')->havingRaw('COUNT(*) > 1')->get()->count());
    }

    public function test_running_the_seeders_again_changes_nothing(): void
    {
        $before = [User::count(), Teacher::count(), SchoolClass::count(), Student::count(), Subject::count(),
            DB::table('class_subject_teachers')->count(), DB::table('student_optional_subjects')->count(),
            DB::table('results')->count(), DB::table('attendances')->count()];

        $this->seed(DemoDataSeeder::class);

        $after = [User::count(), Teacher::count(), SchoolClass::count(), Student::count(), Subject::count(),
            DB::table('class_subject_teachers')->count(), DB::table('student_optional_subjects')->count(),
            DB::table('results')->count(), DB::table('attendances')->count()];

        $this->assertSame($before, $after);
    }

    public function test_marks_and_attendance_are_seeded_and_every_class_tabulates_completely(): void
    {
        $this->assertGreaterThan(0, DB::table('results')->count());
        $this->assertGreaterThan(0, DB::table('attendances')->count());

        $admin = User::factory()->create(['role' => 'admin']);

        foreach (SchoolClass::where('academic_year', (string) $this->year)->get() as $class) {
            $sheet = app(\App\Services\TabulationBuilder::class)->build($class, 'final');

            $this->assertSame(0, $sheet['summary']['incomplete'], "{$class->class_name} {$class->section} {$class->group_name}: some marks are missing");
            $this->assertSame(40, $sheet['working_days']);
            $this->assertNotEmpty($sheet['subjects']);

            $this->actingAs($admin)->get("/tabulation/class/{$class->id}")->assertOk();
        }
    }

    public function test_every_result_document_can_be_printed_from_the_demo_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $class = SchoolClass::where('academic_year', (string) $this->year)->where('class_name', 'Class 9')->firstOrFail();
        $count = Student::where('class_id', $class->id)->count();

        $html = $this->actingAs($admin)->get("/reports/marksheet/class/{$class->id}")->assertOk()->getContent();
        $this->assertSame($count, substr_count($html, '<section class="sheet">'));

        $this->actingAs($admin)->get("/reports/summary/class/{$class->id}")->assertOk()->assertSee('Merit list');

        $this->actingAs($admin)->get("/reports/class-wise?academic_year={$this->year}")->assertOk()
            ->assertSee('size: A4 landscape', false)->assertSee('Total — 28 classes');
    }

    public function test_the_admin_account_is_never_touched(): void
    {
        $admin = User::factory()->create(['email' => 'boss@school.test', 'role' => 'admin']);
        $hash  = $admin->password;

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertSame('admin', $admin->fresh()->role);
    }
}
