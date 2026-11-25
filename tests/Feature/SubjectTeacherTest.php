<?php

namespace Tests\Feature;

use App\Models\ClassSubjectTeacher;
use App\Models\ResultInput;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class SubjectTeacherTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private User $classTeacher;   // Asif  — class teacher
    private User $mathTeacher;    // Rahim — teaches Math
    private User $englishTeacher; // Karim — teaches English
    private SchoolClass $class;
    /** @var array<int,Student> */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin          = $this->makeUser(User::ROLE_ADMIN);
        $this->classTeacher   = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Asif']);
        $this->mathTeacher    = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Rahim']);
        $this->englishTeacher = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Karim']);

        $this->class = $this->makeClass('Class 6', 'A', teacher: $this->classTeacher);
        $this->class->assignSubjectTeacher('Math', $this->mathTeacher);
        $this->class->assignSubjectTeacher('English', $this->englishTeacher);

        $this->students = [
            $this->makeStudent($this->class, 'S1', ['father_name' => 'Secret Father', 'blood_group' => 'O+']),
            $this->makeStudent($this->class, 'S2'),
            $this->makeStudent($this->class, 'S3'),
        ];
    }

    private function marks(string $subject, array $marks): array
    {
        $rows = [];
        foreach (array_values($this->students) as $i => $student) {
            $rows[] = ['student_id' => $student->id, 'subject' => $subject, 'marks' => $marks[$i]];
        }

        return ['exam_type' => 'final', 'results' => $rows];
    }

    private function enter(User $by, string $subject, array $marks)
    {
        return $this->actingAs($by)->post("/results/class/{$this->class->id}", $this->marks($subject, $marks));
    }

    // ------------------------------------------------------------------
    // what a subject teacher can see
    // ------------------------------------------------------------------

    public function test_subject_teacher_sees_only_their_subject_and_no_private_data(): void
    {
        $this->enter($this->classTeacher, 'English', [70, 80, 90]);   // data exists for another subject

        $this->actingAs($this->mathTeacher)->get("/results/class/{$this->class->id}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertSame(['Math'], array_values($props['subjects']));
                $this->assertTrue($props['restricted']);

                $first = $props['students']['data'][0];
                $this->assertArrayNotHasKey('father_name', $first);
                $this->assertArrayNotHasKey('blood_group', $first);
                $this->assertNull($first['rank']);
                $this->assertNull($first['total_marks']);
                $this->assertArrayNotHasKey('English', $first['marks']);
            });
    }

    public function test_class_teacher_and_admin_see_every_subject_with_totals(): void
    {
        $this->enter($this->mathTeacher, 'Math', [50, 60, 70]);
        $this->enter($this->englishTeacher, 'English', [70, 80, 90]);

        foreach ([$this->classTeacher, $this->admin] as $viewer) {
            $this->actingAs($viewer)->get("/results/class/{$this->class->id}")
                ->assertInertia(function ($page) {
                    $props = $page->toArray()['props'];
                    $this->assertEqualsCanonicalizing(['Math', 'English'], $props['subjects']);
                    $this->assertFalse($props['restricted']);

                    $row = collect($props['students']['data'])->firstWhere('student_name', 'S3');
                    $this->assertEquals(160, $row['total_marks']);      // 70 + 90
                    $this->assertSame(1, $row['rank']);
                });
        }
    }

    public function test_subject_teacher_cannot_open_the_complete_class_result_or_ranking(): void
    {
        $this->actingAs($this->mathTeacher)->get("/student-results/class/{$this->class->id}")->assertForbidden();

        $this->actingAs($this->mathTeacher)->get('/student-results')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('classes', 0));

        // the class teacher can
        $this->actingAs($this->classTeacher)->get("/student-results/class/{$this->class->id}")->assertOk();
    }

    // ------------------------------------------------------------------
    // entering marks
    // ------------------------------------------------------------------

    public function test_subject_teacher_can_enter_only_their_own_subject(): void
    {
        $this->enter($this->mathTeacher, 'Math', [50, 60, 70])->assertSessionHas('success');
        $this->assertSame(3, ResultInput::where('subject', 'Math')->count());

        $this->enter($this->mathTeacher, 'English', [1, 2, 3])->assertSessionHas('error');
        $this->assertSame(0, ResultInput::where('subject', 'English')->count());
    }

    public function test_a_mixed_save_keeps_the_allowed_subject_and_reports_the_rest(): void
    {
        $payload = $this->marks('Math', [50, 60, 70]);
        $payload['results'] = array_merge($payload['results'], $this->marks('English', [9, 9, 9])['results']);

        $this->actingAs($this->mathTeacher)->post("/results/class/{$this->class->id}", $payload)
            ->assertSessionHas('success');

        $this->assertSame(3, ResultInput::where('subject', 'Math')->count());
        $this->assertSame(0, ResultInput::where('subject', 'English')->count());
    }

    public function test_class_teacher_can_enter_marks_for_an_unlocked_subject_on_behalf(): void
    {
        $this->enter($this->classTeacher, 'Math', [55, 65, 75])->assertSessionHas('success');

        $this->assertSame(3, ResultInput::where('subject', 'Math')->count());
        $this->assertSame($this->classTeacher->id, ResultInput::where('subject', 'Math')->first()->entered_by);
    }

    public function test_once_the_subject_teacher_locks_it_the_class_teacher_cannot_change_it(): void
    {
        $this->enter($this->mathTeacher, 'Math', [50, 60, 70]);

        $this->actingAs($this->mathTeacher)
            ->post("/results/class/{$this->class->id}/lock", ['exam_type' => 'final', 'subject' => 'Math']);

        $this->enter($this->classTeacher, 'Math', [99, 99, 99])->assertSessionHas('error');
        $this->assertEquals([50, 60, 70], ResultInput::where('subject', 'Math')->orderBy('student_id')->pluck('marks')->map(fn ($m) => (float) $m)->all());

        // the subject teacher themself cannot reopen it either
        $this->enter($this->mathTeacher, 'Math', [1, 1, 1])->assertSessionHas('error');

        // admin can override
        $this->enter($this->admin, 'Math', [60, 70, 80])->assertSessionHas('success');
        $this->assertEquals([60, 70, 80], ResultInput::where('subject', 'Math')->orderBy('student_id')->pluck('marks')->map(fn ($m) => (float) $m)->all());
    }

    public function test_only_admin_can_unlock_and_then_the_class_teacher_may_enter_again(): void
    {
        $this->enter($this->mathTeacher, 'Math', [50, 60, 70]);
        $this->actingAs($this->mathTeacher)->post("/results/class/{$this->class->id}/lock", ['exam_type' => 'final', 'subject' => 'Math']);

        $this->actingAs($this->mathTeacher)->post("/results/class/{$this->class->id}/unlock", ['exam_type' => 'final', 'subject' => 'Math'])->assertForbidden();
        $this->actingAs($this->classTeacher)->post("/results/class/{$this->class->id}/unlock", ['exam_type' => 'final', 'subject' => 'Math'])->assertForbidden();

        $this->actingAs($this->admin)->post("/results/class/{$this->class->id}/unlock", ['exam_type' => 'final', 'subject' => 'Math']);

        $this->enter($this->classTeacher, 'Math', [51, 61, 71])->assertSessionHas('success');
    }

    public function test_a_subject_teacher_can_lock_only_their_own_subject(): void
    {
        $this->enter($this->englishTeacher, 'English', [70, 80, 90]);

        $this->actingAs($this->mathTeacher)
            ->post("/results/class/{$this->class->id}/lock", ['exam_type' => 'final', 'subject' => 'English'])
            ->assertForbidden();

        $this->actingAs($this->englishTeacher)
            ->post("/results/class/{$this->class->id}/lock", ['exam_type' => 'final', 'subject' => 'English'])
            ->assertSessionMissing('errors');

        $this->assertTrue(ResultInput::where('subject', 'English')->first()->is_locked);
    }

    public function test_totals_are_the_sum_of_all_subjects_even_when_teachers_save_separately(): void
    {
        $this->enter($this->mathTeacher, 'Math', [50, 60, 70]);
        $this->enter($this->englishTeacher, 'English', [10, 20, 30]);
        $this->enter($this->mathTeacher, 'Math', [51, 61, 71]);   // Math teacher corrects their marks later

        $totals = ResultInput::query()->selectRaw('student_id, MAX(total_marks) AS t')->groupBy('student_id')->orderBy('student_id')->pluck('t')->map(fn ($t) => (float) $t)->all();

        $this->assertEquals([61, 81, 101], $totals);   // 51+10, 61+20, 71+30
    }

    public function test_marks_cannot_be_saved_for_other_classes_students_or_unknown_subjects(): void
    {
        $outsider = $this->makeStudent($this->makeClass('Class 7', 'A'), 'Outsider');

        $this->actingAs($this->classTeacher)->post("/results/class/{$this->class->id}", [
            'exam_type' => 'final',
            'results'   => [['student_id' => $outsider->id, 'subject' => 'Math', 'marks' => 90]],
        ])->assertSessionHas('error');

        $this->actingAs($this->classTeacher)->post("/results/class/{$this->class->id}", [
            'exam_type' => 'final',
            'results'   => [['student_id' => $this->students[0]->id, 'subject' => 'Astrology', 'marks' => 90]],
        ])->assertSessionHas('error');

        $this->assertSame(0, ResultInput::count());
    }

    // ------------------------------------------------------------------
    // one teacher, many subjects, many classes
    // ------------------------------------------------------------------

    public function test_one_teacher_can_teach_several_subjects_in_several_classes(): void
    {
        $other = $this->makeClass('Class 7', 'A', teacher: $this->classTeacher);
        $third = $this->makeClass('Class 8', 'A');                        // Rahim does NOT teach here

        $other->assignSubjectTeacher('Math', $this->mathTeacher);
        $other->assignSubjectTeacher('English', $this->mathTeacher);      // Rahim also teaches English in Class 7

        $this->assertEqualsCanonicalizing(['Math', 'English'], $this->mathTeacher->fresh()->taughtSubjects()[$other->id]);
        $this->assertEqualsCanonicalizing(['Math'], $this->mathTeacher->fresh()->taughtSubjects()[$this->class->id]);

        $this->actingAs($this->mathTeacher)->get("/results/class/{$other->id}")->assertOk();
        $this->actingAs($this->mathTeacher)->get("/results/class/{$third->id}")->assertForbidden();

        $this->actingAs($this->mathTeacher)->get('/results')->assertInertia(function ($page) use ($other, $third) {
            $ids = collect($page->toArray()['props']['classes']['data'])->pluck('id');
            $this->assertTrue($ids->contains($this->class->id));
            $this->assertTrue($ids->contains($other->id));
            $this->assertFalse($ids->contains($third->id));
        });
    }

    // ------------------------------------------------------------------
    // assigning (admin screen) + history
    // ------------------------------------------------------------------

    public function test_admin_assigns_changes_and_clears_a_subject_teacher_with_history(): void
    {
        $newTeacher = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Selim']);

        $assign = fn (?int $id) => $this->actingAs($this->admin)->post('/subject-teachers', [
            'class_id' => $this->class->id, 'subject' => 'Math', 'user_id' => $id,
        ]);

        $assign($newTeacher->id)->assertSessionHas('success');
        $this->assertSame($newTeacher->id, $this->class->currentSubjectTeachers()->get('Math')->user_id);

        // Rahim lost the subject -> loses access to the sheet
        $this->actingAs($this->mathTeacher->fresh())->get("/results/class/{$this->class->id}")->assertForbidden();

        $assign(null)->assertSessionHas('success');
        $this->assertNull($this->class->currentSubjectTeachers()->get('Math'));

        $history = ClassSubjectTeacher::where('class_id', $this->class->id)->where('subject', 'Math')->orderBy('id')->pluck('teacher_name')->all();
        $this->assertSame(['Rahim', 'Selim'], $history);   // both stints kept
    }

    public function test_assignment_rules(): void
    {
        $parent = $this->makeUser(User::ROLE_USER);

        // not a teacher account
        $this->actingAs($this->admin)->post('/subject-teachers', ['class_id' => $this->class->id, 'subject' => 'Math', 'user_id' => $parent->id])
            ->assertSessionHasErrors('user_id');

        // a subject the class does not have
        $this->actingAs($this->admin)->post('/subject-teachers', ['class_id' => $this->class->id, 'subject' => 'Astrology', 'user_id' => $this->mathTeacher->id])
            ->assertSessionHas('error');

        // teachers cannot assign teachers
        $this->actingAs($this->classTeacher)->post('/subject-teachers', ['class_id' => $this->class->id, 'subject' => 'Math', 'user_id' => null])
            ->assertForbidden();
        $this->actingAs($this->classTeacher)->get('/subject-teachers')->assertForbidden();
        $this->actingAs($this->admin)->get('/subject-teachers')->assertOk();
    }

    public function test_same_teacher_assigned_twice_creates_no_extra_history(): void
    {
        $this->class->assignSubjectTeacher('Math', $this->mathTeacher);
        $this->class->assignSubjectTeacher('Math', $this->mathTeacher);

        $this->assertSame(1, ClassSubjectTeacher::where('class_id', $this->class->id)->where('subject', 'Math')->count());
    }

    public function test_next_years_classes_inherit_the_subject_teachers(): void
    {
        $next = $this->makeClass('Class 7', 'A', now()->year + 1);   // pre-existing year? use ensureForYear instead
        $next->delete();

        SchoolClass::ensureForYear(now()->year + 1);

        $copied = SchoolClass::where('academic_year', (string) (now()->year + 1))->where('class_name', 'Class 6')->firstOrFail();

        $this->assertSame('Rahim', $copied->currentSubjectTeachers()->get('Math')->teacher_name);
        $this->assertSame('Karim', $copied->currentSubjectTeachers()->get('English')->teacher_name);
        $this->assertSame('Asif', $copied->class_teacher);
    }
}
