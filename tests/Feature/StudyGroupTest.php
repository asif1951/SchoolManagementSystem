<?php

namespace Tests\Feature;

use App\Models\CurriculumSubject;
use App\Models\SchoolClass;
use App\Models\StudyGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class StudyGroupTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private StudyGroup $science;
    private StudyGroup $commerce;
    private StudyGroup $arts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->science  = StudyGroup::create(['name' => 'Science',  'sort_order' => 1]);
        $this->commerce = StudyGroup::create(['name' => 'Commerce', 'sort_order' => 2]);
        $this->arts     = StudyGroup::create(['name' => 'Arts',     'sort_order' => 3]);
    }

    private function template(string $level, ?StudyGroup $group, string $subject, bool $optional = false, int $order = 0): void
    {
        CurriculumSubject::create([
            'class_name' => $level, 'study_group_id' => $group?->id,
            'subject_id' => Subject::findOrCreateByName($subject)->id, 'is_optional' => $optional, 'sort_order' => $order,
        ]);
    }

    /** a typical Class 9 curriculum */
    private function class9Template(): void
    {
        $this->template('Class 9', null, 'Bangla', false, 1);
        $this->template('Class 9', null, 'English', false, 2);
        $this->template('Class 9', null, 'General Math', false, 3);

        $this->template('Class 9', $this->science, 'Physics', false, 10);
        $this->template('Class 9', $this->science, 'Chemistry', false, 11);
        $this->template('Class 9', $this->science, 'Higher Math', true, 12);   // optional for Science

        $this->template('Class 9', $this->commerce, 'Accounting', false, 20);
        $this->template('Class 9', $this->commerce, 'Higher Math', true, 21);

        $this->template('Class 9', $this->arts, 'History', false, 30);
        $this->template('Class 9', $this->arts, 'Agriculture', true, 31);
    }

    // ------------------------------------------------------------------
    // the group list
    // ------------------------------------------------------------------

    public function test_admin_manages_groups_and_names_are_unique(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($this->makeUser(User::ROLE_TEACHER))->get('/study-groups')->assertForbidden();
        $this->actingAs($admin)->get('/study-groups')->assertOk();

        $this->actingAs($admin)->post('/study-groups', ['name' => 'Vocational', 'code' => 'VOC'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/study-groups', ['name' => ' vocational '])->assertSessionHasErrors('name');
        $this->assertTrue(StudyGroup::where('name', 'Vocational')->exists());
    }

    public function test_a_group_in_use_cannot_be_deleted(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->science->id]);

        $this->actingAs($admin)->delete("/study-groups/{$this->science->id}")->assertSessionHas('error');
        $this->assertModelExists($this->science);

        $this->actingAs($admin)->delete("/study-groups/{$this->arts->id}")->assertSessionHas('success');
        $this->assertModelMissing($this->arts);
    }

    public function test_the_same_class_can_exist_once_per_group(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $teacher = $this->makeUser(User::ROLE_TEACHER);

        $payload = fn (StudyGroup $g) => [
            'className' => 'Class 9', 'section' => 'A', 'academicYear' => (string) now()->year,
            'classTeacher' => $teacher->id, 'status' => 'active', 'studyGroup' => $g->id,
        ];

        $this->actingAs($admin)->post('/classesmanagement', $payload($this->science))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/classesmanagement', $payload($this->arts))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/classesmanagement', $payload($this->science))->assertSessionHasErrors('className');

        $this->assertSame(2, SchoolClass::where('class_name', 'Class 9')->count());
    }

    public function test_editing_a_class_through_the_old_form_keeps_its_group(): void
    {
        $admin   = $this->makeUser(User::ROLE_ADMIN);
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $class   = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->science->id]);

        // the old form sends no `studyGroup` field
        $this->actingAs($admin)->put("/classesmanagement/{$class->id}", [
            'className' => 'Class 9', 'section' => 'A', 'academicYear' => (string) $class->academic_year,
            'classTeacher' => $teacher->id, 'status' => 'active', 'subjects' => ['Math'],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->science->id, $class->fresh()->study_group_id);
    }

    // ------------------------------------------------------------------
    // curriculum templates
    // ------------------------------------------------------------------

    public function test_a_group_wise_class_gets_the_common_subjects_plus_its_groups_extras(): void
    {
        $this->class9Template();
        $science = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->science->id, 'subjects' => []]);

        $this->assertTrue($science->applyCurriculum());

        $this->assertSame(['Bangla', 'English', 'General Math', 'Physics', 'Chemistry', 'Higher Math'], $science->fresh()->subjects);

        $plan = collect($science->fresh()->subjectPlan())->keyBy('name');
        $this->assertTrue($plan['Higher Math']['is_optional']);
        $this->assertFalse($plan['Physics']['is_optional']);
        $this->assertNotContains('Accounting', $science->fresh()->subjects);
    }

    public function test_applying_a_template_never_overwrites_a_class_unless_asked(): void
    {
        $this->class9Template();
        $class = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->arts->id, 'subjects' => ['Custom Subject']]);

        $this->assertFalse($class->applyCurriculum());
        $this->assertSame(['Custom Subject'], $class->fresh()->subjects);

        $this->assertTrue($class->applyCurriculum(replace: true));
        $this->assertContains('History', $class->fresh()->subjects);
        $this->assertNotContains('Custom Subject', $class->fresh()->subjects);
    }

    // ------------------------------------------------------------------
    // who takes which subject
    // ------------------------------------------------------------------

    public function test_a_mixed_class_gives_each_student_their_groups_subjects(): void
    {
        $this->class9Template();
        $class = $this->makeClass('Class 9', 'A', attrs: ['subjects' => []]);   // mixed: no group
        $class->applyCurriculum();

        $sci = $this->makeStudent($class, 'Sci', ['study_group_id' => $this->science->id]);
        $com = $this->makeStudent($class, 'Com', ['study_group_id' => $this->commerce->id]);
        $art = $this->makeStudent($class, 'Art', ['study_group_id' => $this->arts->id]);

        $this->assertEqualsCanonicalizing(['Bangla', 'English', 'General Math', 'Physics', 'Chemistry'], $sci->subjectsTaken());
        $this->assertEqualsCanonicalizing(['Bangla', 'English', 'General Math', 'Accounting'], $com->subjectsTaken());
        $this->assertEqualsCanonicalizing(['Bangla', 'English', 'General Math', 'History'], $art->subjectsTaken());
    }

    public function test_optional_subjects_count_only_when_the_student_chose_them(): void
    {
        $this->class9Template();
        $class = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->science->id, 'subjects' => []]);
        $class->applyCurriculum();

        $kid = $this->makeStudent($class, 'Kid');
        $this->assertNotContains('Higher Math', $kid->subjectsTaken());

        $kid->chooseOptionalSubjects([Subject::where('name', 'Higher Math')->value('id')]);

        $this->assertContains('Higher Math', $kid->fresh()->subjectsTaken());
    }

    public function test_a_student_cannot_pick_a_compulsory_or_foreign_subject_as_their_optional(): void
    {
        $this->class9Template();
        $class = $this->makeClass('Class 9', 'A', attrs: ['subjects' => []]);   // mixed
        $class->applyCurriculum();

        $sci = $this->makeStudent($class, 'Sci', ['study_group_id' => $this->science->id]);

        // Physics is compulsory, Agriculture belongs to Arts only
        foreach (['Physics', 'Agriculture'] as $name) {
            try {
                $sci->chooseOptionalSubjects([Subject::where('name', $name)->value('id')]);
                $this->fail("{$name} should not be allowed as an optional subject");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('optional subject', $e->getMessage());
            }
        }

        $this->assertSame(0, $sci->optionalSubjects()->count());
    }

    public function test_the_marks_roster_for_a_subject_lists_only_students_who_take_it(): void
    {
        $this->class9Template();
        $class = $this->makeClass('Class 9', 'A', attrs: ['subjects' => []]);
        $class->applyCurriculum();

        $sci1 = $this->makeStudent($class, 'Sci1', ['study_group_id' => $this->science->id]);
        $sci2 = $this->makeStudent($class, 'Sci2', ['study_group_id' => $this->science->id]);
        $com  = $this->makeStudent($class, 'Com',  ['study_group_id' => $this->commerce->id]);
        $art  = $this->makeStudent($class, 'Art',  ['study_group_id' => $this->arts->id]);

        $names = fn (string $subject) => $class->studentsTaking($subject)->orderBy('student_name')->pluck('student_name')->all();

        $this->assertSame(['Art', 'Com', 'Sci1', 'Sci2'], $names('Bangla'));      // everyone
        $this->assertSame(['Sci1', 'Sci2'], $names('Physics'));                    // science only
        $this->assertSame(['Com'], $names('Accounting'));
        $this->assertSame([], $names('Higher Math'));                              // optional, nobody chose it yet

        $sci1->chooseOptionalSubjects([Subject::where('name', 'Higher Math')->value('id')]);
        $com->chooseOptionalSubjects([Subject::where('name', 'Higher Math')->value('id')]);

        $this->assertSame(['Com', 'Sci1'], $names('Higher Math'));                 // only those who chose it
        $this->assertSame([], $names('Not A Subject'));
    }

    public function test_in_a_group_wise_class_students_follow_the_class_group(): void
    {
        $this->class9Template();
        $class = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->commerce->id, 'subjects' => []]);
        $class->applyCurriculum();

        $kid = $this->makeStudent($class, 'Kid');   // no personal group set

        $this->assertSame($this->commerce->id, $kid->effectiveGroupId());
        $this->assertContains('Accounting', $kid->subjectsTaken());
        $this->assertNotContains('Physics', $kid->subjectsTaken());
    }

    // ------------------------------------------------------------------
    // continuity
    // ------------------------------------------------------------------

    public function test_next_year_keeps_the_group_and_every_subject_rule(): void
    {
        $this->class9Template();
        $class = $this->makeClass('Class 9', 'A', 2026, attrs: ['study_group_id' => $this->science->id, 'subjects' => []]);
        $class->applyCurriculum();

        SchoolClass::ensureForYear(2027);

        $next = SchoolClass::where('academic_year', '2027')->where('class_name', 'Class 9')->firstOrFail();

        $this->assertSame($this->science->id, $next->study_group_id);
        $this->assertSame($class->fresh()->subjectPlan(), $next->subjectPlan());            // order, optional flags, scopes
        $this->assertTrue(collect($next->subjectPlan())->firstWhere('name', 'Higher Math')['is_optional']);
    }

    public function test_resyncing_subjects_by_name_keeps_optional_flags_and_group_limits(): void
    {
        $this->class9Template();
        $class = $this->makeClass('Class 9', 'A', attrs: ['subjects' => []]);   // mixed
        $class->applyCurriculum();

        $before = collect($class->subjectPlan())->keyBy('name');
        $this->assertSame([$this->science->id], $before['Physics']['group_ids']);

        // an old form re-saves the same subject NAMES only
        $class->syncSubjects($class->subjects);

        $after = collect($class->fresh()->subjectPlan())->keyBy('name');
        $this->assertTrue($after['Higher Math']['is_optional']);
        $this->assertSame([$this->science->id], $after['Physics']['group_ids']);
    }

    // ------------------------------------------------------------------
    // the curriculum screen
    // ------------------------------------------------------------------

    public function test_admin_builds_a_template_through_the_curriculum_screen(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $bangla = Subject::findOrCreateByName('Bangla');
        $physics = Subject::findOrCreateByName('Physics');

        $this->actingAs($this->makeUser(User::ROLE_TEACHER))->get('/curriculum')->assertForbidden();
        $this->actingAs($admin)->get('/curriculum?class_name=Class 9')->assertOk();

        $this->actingAs($admin)->post('/curriculum', ['class_name' => 'Class 9', 'study_group_id' => null, 'subject_id' => $bangla->id])->assertSessionHas('success');
        $this->actingAs($admin)->post('/curriculum', ['class_name' => 'Class 9', 'study_group_id' => $this->science->id, 'subject_id' => $physics->id])->assertSessionHas('success');

        // the same subject twice in the same list is refused
        $this->actingAs($admin)->post('/curriculum', ['class_name' => 'Class 9', 'study_group_id' => null, 'subject_id' => $bangla->id])->assertSessionHas('error');
        $this->assertSame(2, CurriculumSubject::where('class_name', 'Class 9')->count());

        $row = CurriculumSubject::where('subject_id', $physics->id)->firstOrFail();
        $this->actingAs($admin)->put("/curriculum/{$row->id}", ['is_optional' => true]);
        $this->assertTrue($row->fresh()->is_optional);

        $this->actingAs($admin)->delete("/curriculum/{$row->id}");
        $this->assertModelMissing($row);
    }

    public function test_applying_a_template_fills_only_empty_classes_unless_replace_is_chosen(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->class9Template();

        $empty  = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->science->id, 'subjects' => []]);
        $filled = $this->makeClass('Class 9', 'B', attrs: ['study_group_id' => $this->science->id, 'subjects' => ['Custom']]);
        $year   = (string) $empty->academic_year;

        $this->actingAs($admin)->post('/curriculum/apply', ['class_name' => 'Class 9', 'year' => $year])->assertSessionHas('success');

        $this->assertContains('Physics', $empty->fresh()->subjects);
        $this->assertSame(['Custom'], $filled->fresh()->subjects);

        $this->actingAs($admin)->post('/curriculum/apply', ['class_name' => 'Class 9', 'year' => $year, 'replace' => true]);
        $this->assertContains('Physics', $filled->fresh()->subjects);

        $this->actingAs($admin)->post('/curriculum/apply', ['class_name' => 'Class 9', 'year' => '1999'])->assertSessionHas('error');
    }

    public function test_a_new_class_created_without_subjects_gets_its_template(): void
    {
        $admin   = $this->makeUser(User::ROLE_ADMIN);
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $this->class9Template();

        $this->actingAs($admin)->post('/classesmanagement', [
            'className' => 'Class 9', 'section' => 'C', 'academicYear' => (string) now()->year,
            'classTeacher' => $teacher->id, 'status' => 'active', 'studyGroup' => $this->commerce->id,
        ])->assertSessionHasNoErrors();

        $class = SchoolClass::where('section', 'C')->firstOrFail();

        $this->assertContains('Accounting', $class->subjects);
        $this->assertContains('Bangla', $class->subjects);
        $this->assertNotContains('Physics', $class->subjects);
    }
}
