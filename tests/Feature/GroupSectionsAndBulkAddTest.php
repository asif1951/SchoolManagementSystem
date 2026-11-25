<?php

namespace Tests\Feature;

use App\Models\CurriculumSubject;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudyGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class GroupSectionsAndBulkAddTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private User $teacher;
    private StudyGroup $science;
    private StudyGroup $arts;
    private string $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = $this->makeUser(User::ROLE_ADMIN);
        $this->teacher = $this->makeUser(User::ROLE_TEACHER);
        $this->science = StudyGroup::create(['name' => 'Science', 'sort_order' => 1]);
        $this->arts    = StudyGroup::create(['name' => 'Arts', 'sort_order' => 2]);
        $this->year    = (string) now()->year;
    }

    private function createClass(array $extra = [])
    {
        return $this->actingAs($this->admin)->post('/classesmanagement', array_merge([
            'className' => 'Class 9', 'section' => 'A', 'academicYear' => $this->year,
            'classTeacher' => $this->teacher->id, 'status' => 'active', 'subjects' => ['Physics'],
        ], $extra));
    }

    // ------------------------------------------------------------------
    // each group has its own sections
    // ------------------------------------------------------------------

    public function test_several_sections_are_created_at_once_for_a_group(): void
    {
        $this->createClass(['section' => 'A, B, C', 'studyGroup' => $this->science->id])->assertSessionHas('success');

        $classes = SchoolClass::where('class_name', 'Class 9')->orderBy('section')->get();

        $this->assertSame(['A', 'B', 'C'], $classes->pluck('section')->all());
        $this->assertTrue($classes->every(fn ($c) => $c->study_group_id === $this->science->id));
        $this->assertTrue($classes->every(fn ($c) => $c->class_teacher_id === $this->teacher->id));
        $this->assertTrue($classes->every(fn ($c) => $c->subjects === ['Physics']));
    }

    public function test_the_same_section_name_can_exist_in_every_group(): void
    {
        $this->createClass(['section' => 'A, B', 'studyGroup' => $this->science->id])->assertSessionHasNoErrors();
        $this->createClass(['section' => 'A', 'studyGroup' => $this->arts->id])->assertSessionHasNoErrors();

        $this->assertSame(3, SchoolClass::where('class_name', 'Class 9')->count());
        $this->assertSame(2, SchoolClass::where('class_name', 'Class 9')->where('section', 'A')->count());
    }

    public function test_existing_sections_are_skipped_and_reported(): void
    {
        $this->createClass(['section' => 'A', 'studyGroup' => $this->science->id]);

        $this->createClass(['section' => 'A, B', 'studyGroup' => $this->science->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'skipped') && str_contains($m, 'A'));

        $this->assertSame(2, SchoolClass::where('study_group_id', $this->science->id)->count());

        // nothing new at all is an error, not a silent success
        $this->createClass(['section' => 'A', 'studyGroup' => $this->science->id])->assertSessionHasErrors('className');
    }

    public function test_a_new_group_class_without_subjects_gets_its_curriculum(): void
    {
        $physics = Subject::findOrCreateByName('Physics');
        CurriculumSubject::create(['class_name' => 'Class 9', 'study_group_id' => $this->science->id, 'subject_id' => $physics->id, 'sort_order' => 1]);

        $this->createClass(['section' => 'A, B', 'studyGroup' => $this->science->id, 'subjects' => []]);

        $this->assertTrue(SchoolClass::where('class_name', 'Class 9')->get()->every(fn ($c) => $c->subjects === ['Physics']));
    }

    // ------------------------------------------------------------------
    // admitting students into the right group's section
    // ------------------------------------------------------------------

    private function admit(array $extra = [])
    {
        static $n = 0;
        $n++;

        return $this->actingAs($this->admin)->post('/students', array_merge([
            'studentName' => "Kid {$n}", 'studentId' => sprintf('9100%04d', $n), 'academicYear' => (int) $this->year,
            'class' => 'Class 9', 'section' => 'A', 'admissionDate' => now()->toDateString(), 'status' => 'active',
        ], $extra));
    }

    public function test_admission_asks_for_the_group_when_a_section_exists_in_several(): void
    {
        $sci  = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->science->id]);
        $arts = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->arts->id]);

        $this->admit()->assertSessionHasErrors('class');                 // ambiguous: which group?
        $this->assertSame(0, Student::count());

        $this->admit(['studyGroup' => $this->arts->id])->assertSessionHasNoErrors();

        $kid = Student::firstOrFail();
        $this->assertSame($arts->id, $kid->class_id);                    // landed in Arts A, not Science A
        $this->assertSame($this->arts->id, $kid->study_group_id);
        $this->assertNotSame($sci->id, $kid->class_id);
    }

    public function test_admission_without_groups_still_works_as_before(): void
    {
        $plain = $this->makeClass('Class 5', 'B');

        $this->admit(['class' => 'Class 5', 'section' => 'B'])->assertSessionHasNoErrors();

        $this->assertSame($plain->id, Student::firstOrFail()->class_id);
    }

    public function test_editing_a_student_keeps_them_in_their_own_groups_section(): void
    {
        $sci = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->science->id]);
        $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->arts->id]);
        $kid = $this->makeStudent($sci, 'Sci Kid', ['study_group_id' => $this->science->id]);

        // the edit form posts class + section only (the student's own group is the fallback)
        $this->actingAs($this->admin)->put("/studentmanagement/{$kid->id}", [
            'studentName' => 'Sci Kid Renamed', 'academicYear' => (int) $this->year, 'class' => 'Class 9', 'section' => 'A',
            'admissionDate' => now()->toDateString(), 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame($sci->id, $kid->fresh()->class_id);
        $this->assertSame('Sci Kid Renamed', $kid->fresh()->student_name);
    }

    // ------------------------------------------------------------------
    // the result pages know about groups
    // ------------------------------------------------------------------

    public function test_result_pages_show_the_group_and_filter_by_it(): void
    {
        $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->science->id]);
        $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->arts->id]);

        foreach (['/student-results', '/results'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $this->assertEqualsCanonicalizing(['Science', 'Arts'], collect($props['groups'])->pluck('name')->all());

                $rows = collect($props['classes']['data'] ?? $props['classes']);
                $this->assertEqualsCanonicalizing(['Science', 'Arts'], $rows->pluck('group')->all());
            });

            $this->actingAs($this->admin)->get($url . '?group=' . $this->arts->id)->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $rows  = collect($props['classes']['data'] ?? $props['classes']);

                $this->assertSame(['Arts'], $rows->pluck('group')->all());
                $this->assertSame((string) $this->arts->id, (string) $props['filters']['group']);
            });
        }
    }

    public function test_a_class_pages_header_data_carries_the_group(): void
    {
        $class = $this->makeClass('Class 9', 'A', attrs: ['study_group_id' => $this->science->id]);

        $this->actingAs($this->admin)->get("/student-results/class/{$class->id}")
            ->assertInertia(fn ($page) => $page->where('classInfo.group', 'Science'));
        $this->actingAs($this->admin)->get("/results/class/{$class->id}")
            ->assertInertia(fn ($page) => $page->where('classInfo.group', 'Science'));
    }

    // ------------------------------------------------------------------
    // adding many subjects at once
    // ------------------------------------------------------------------

    public function test_several_subjects_can_be_added_to_a_template_in_one_click(): void
    {
        $ids = collect(['Physics', 'Chemistry', 'Biology'])->map(fn ($n) => Subject::findOrCreateByName($n)->id)->all();

        $post = fn (array $subjects, bool $optional = false) => $this->actingAs($this->admin)->post('/curriculum/bulk', [
            'class_name' => 'Class 9', 'study_group_id' => $this->science->id, 'subject_ids' => $subjects, 'is_optional' => $optional,
        ]);

        $post($ids)->assertSessionHas('success', fn ($m) => str_contains($m, '3 subject'));
        $this->assertSame(3, CurriculumSubject::where('class_name', 'Class 9')->where('study_group_id', $this->science->id)->count());

        // already-present subjects are skipped, new ones are added (as optional)
        $extra = Subject::findOrCreateByName('Higher Mathematics')->id;
        $post([$ids[0], $extra], true)->assertSessionHas('success', fn ($m) => str_contains($m, '1 subject'));

        $this->assertSame(4, CurriculumSubject::where('class_name', 'Class 9')->where('study_group_id', $this->science->id)->count());
        $this->assertTrue(CurriculumSubject::where('subject_id', $extra)->value('is_optional'));
        $this->assertFalse(CurriculumSubject::where('subject_id', $ids[0])->value('is_optional'));
    }

    public function test_bulk_template_add_is_admin_only_and_validates(): void
    {
        $sid = Subject::findOrCreateByName('Physics')->id;

        $this->actingAs($this->teacher)->post('/curriculum/bulk', ['class_name' => 'Class 9', 'subject_ids' => [$sid]])->assertForbidden();
        $this->actingAs($this->admin)->post('/curriculum/bulk', ['class_name' => 'Class 9', 'subject_ids' => []])->assertSessionHasErrors('subject_ids');
        $this->actingAs($this->admin)->post('/curriculum/bulk', ['class_name' => 'Class 9', 'subject_ids' => [999999]])->assertSessionHasErrors('subject_ids.0');
    }

    public function test_a_pasted_list_of_subjects_is_added_skipping_what_exists(): void
    {
        Subject::findOrCreateByName('Physics');

        $this->actingAs($this->admin)->post('/subjects/bulk', ['names' => "Physics\n  chemistry \nBiology, Economics;\n\nECONOMICS"])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '3 subject') && str_contains($m, '1 already'));

        $names = Subject::pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        sort($names);

        $this->assertSame(['biology', 'chemistry', 'economics', 'physics'], $names);      // each once, whatever the case

        $this->actingAs($this->admin)->post('/subjects/bulk', ['names' => " \n , "])->assertSessionHas('error');
        $this->actingAs($this->teacher)->post('/subjects/bulk', ['names' => 'Math'])->assertForbidden();
    }
}
