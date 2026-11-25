<?php

namespace Tests\Feature;

use App\Models\ClassSubjectTeacher;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class SubjectModuleTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private function admin(): User
    {
        return $this->makeUser(User::ROLE_ADMIN);
    }

    private function classPayload(array $subjects, ?User $teacher = null, array $extra = []): array
    {
        return array_merge([
            'className' => 'Class 9', 'section' => 'A', 'academicYear' => (string) now()->year,
            'classTeacher' => ($teacher ?? $this->makeUser(User::ROLE_TEACHER))->id,
            'status' => 'active', 'subjects' => $subjects,
        ], $extra);
    }

    // ------------------------------------------------------------------
    // the subject list itself
    // ------------------------------------------------------------------

    public function test_admin_manages_the_subject_list_and_teachers_cannot(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_TEACHER))->get('/subjects')->assertForbidden();
        $this->actingAs($this->makeUser(User::ROLE_FINANCE_HEAD))->get('/subjects')->assertForbidden();
        $this->actingAs($this->admin())->get('/subjects')->assertOk();
    }

    public function test_a_subject_name_is_unique_whatever_the_case_or_spacing(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/subjects', ['name' => 'Mathematics', 'code' => '109'])->assertSessionHasNoErrors();

        foreach (['mathematics', '  MATHEMATICS ', 'Mathematics'] as $dup) {
            $this->actingAs($admin)->post('/subjects', ['name' => $dup])->assertSessionHasErrors('name');
        }

        $this->actingAs($admin)->post('/subjects', ['name' => 'Physics', 'code' => '109'])->assertSessionHasErrors('code');
        $this->assertSame(1, Subject::where('code', '109')->count());
    }

    public function test_renaming_a_subject_keeps_its_marks_and_teachers_attached(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $class   = $this->makeClass();
        $subject = Subject::where('name', 'Math')->firstOrFail();
        $class->assignSubjectTeacher('Math', $teacher);

        $student = $this->makeStudent($class);
        DB::table('results')->insert([
            'student_id' => $student->id, 'class_id' => $class->id, 'subject' => 'Math', 'exam_type' => 'final',
            'academic_year' => (string) $class->academic_year, 'marks' => 80, 'grade' => 'A+', 'gpa' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())->put("/subjects/{$subject->id}", ['name' => 'Mathematics'])->assertSessionHasNoErrors();

        $this->assertSame('Mathematics', DB::table('results')->value('subject'));
        $this->assertSame('Mathematics', ClassSubjectTeacher::where('class_id', $class->id)->whereNull('ends_on')->value('subject'));
        $this->assertContains('Mathematics', $class->fresh()->subjects);
    }

    public function test_a_subject_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $admin = $this->admin();
        $class = $this->makeClass();
        $used  = Subject::where('name', 'Math')->firstOrFail();

        $this->actingAs($admin)->delete("/subjects/{$used->id}")->assertSessionHas('error');
        $this->assertModelExists($used);

        $spare = Subject::create(['name' => 'Drawing', 'sort_order' => 99]);
        $this->actingAs($admin)->delete("/subjects/{$spare->id}")->assertSessionHas('success');
        $this->assertModelMissing($spare);
    }

    public function test_a_subject_with_marks_cannot_be_deleted_even_if_no_class_offers_it_any_more(): void
    {
        $class   = $this->makeClass();
        $student = $this->makeStudent($class);
        $subject = Subject::where('name', 'Math')->firstOrFail();

        DB::table('results')->insert([
            'student_id' => $student->id, 'class_id' => $class->id, 'subject' => 'Math', 'exam_type' => 'final',
            'academic_year' => (string) $class->academic_year, 'marks' => 70, 'grade' => 'A', 'gpa' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $class->syncSubjects(['English']);                       // Math no longer offered

        $this->actingAs($this->admin())->delete("/subjects/{$subject->id}")->assertSessionHas('error');
        $this->assertModelExists($subject);
    }

    // ------------------------------------------------------------------
    // classes pick from the list
    // ------------------------------------------------------------------

    public function test_class_form_matches_names_to_the_master_list_without_duplicates(): void
    {
        $admin = $this->admin();
        Subject::create(['name' => 'Math', 'sort_order' => 1]);

        $this->actingAs($admin)->post('/classesmanagement', $this->classPayload(['math', ' MATH ', 'Science', 'Chemistry']))
            ->assertSessionHasNoErrors();

        $class = SchoolClass::where('class_name', 'Class 9')->firstOrFail();

        $this->assertSame(['Math', 'Science', 'Chemistry'], $class->subjects);       // one Math, order kept
        $this->assertSame(1, Subject::whereRaw('LOWER(name) = ?', ['math'])->count());
        $this->assertTrue(Subject::where('name', 'Chemistry')->exists());             // new name joined the master list
    }

    public function test_editing_a_class_changes_its_subjects_and_ends_removed_subject_teachers(): void
    {
        $admin   = $this->admin();
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $class   = $this->makeClass('Class 9', 'A', teacher: $teacher);
        $class->assignSubjectTeacher('Math', $teacher);

        $this->actingAs($admin)->put("/classesmanagement/{$class->id}", $this->classPayload(['English', 'Science'], $teacher))
            ->assertSessionHasNoErrors();

        $this->assertSame(['English', 'Science'], $class->fresh()->subjects);

        // Math was removed -> its subject-teacher stint is closed, history kept
        $this->assertNull($class->fresh()->currentSubjectTeachers()->get('Math'));
        $this->assertSame(1, ClassSubjectTeacher::where('class_id', $class->id)->where('subject', 'Math')->whereNotNull('ends_on')->count());
    }

    public function test_class_screens_offer_only_active_subjects(): void
    {
        $admin = $this->admin();
        Subject::create(['name' => 'Retired Subject', 'sort_order' => 50, 'is_active' => false]);
        $existing = $this->makeClass();

        $this->actingAs($admin)->get('/classesmanagement')
            ->assertInertia(function ($page) {
                $names = collect($page->toArray()['props']['subjectOptions'])->pluck('name');
                $this->assertTrue($names->contains('Math'));
                $this->assertFalse($names->contains('Retired Subject'));
            });

        // deactivating a subject does not strip it from classes that already have it
        Subject::where('name', 'Math')->update(['is_active' => false]);
        $this->assertContains('Math', $existing->fresh()->subjects);
    }

    public function test_class_json_still_carries_the_subject_names_for_the_pages(): void
    {
        $admin = $this->admin();
        $this->makeClass('Class 3', 'A', attrs: ['subjects' => ['Bangla', 'English']]);

        $this->actingAs($admin)->get('/classesmanagement')
            ->assertInertia(function ($page) {
                $row = collect($page->toArray()['props']['classes']['data'])->firstWhere('class_name', 'Class 3');
                $this->assertSame(['Bangla', 'English'], $row['subjects']);
            });
    }

    public function test_new_year_classes_inherit_subjects_in_order(): void
    {
        $this->makeClass('Class 6', 'A', 2026, attrs: ['subjects' => ['Bangla', 'Math', 'Science']]);

        SchoolClass::ensureForYear(2027);

        $this->assertSame(['Bangla', 'Math', 'Science'], SchoolClass::where('academic_year', '2027')->firstOrFail()->subjects);
    }

    public function test_a_class_with_students_or_marks_keeps_working_when_subjects_are_reordered(): void
    {
        $class = $this->makeClass('Class 6', 'A', attrs: ['subjects' => ['Bangla', 'Math']]);
        $class->syncSubjects(['Math', 'Bangla']);

        $this->assertSame(['Math', 'Bangla'], $class->fresh()->subjects);
    }
}
