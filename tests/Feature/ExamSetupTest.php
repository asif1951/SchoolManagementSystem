<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamComponentType;
use App\Models\ExamPaper;
use App\Models\GradingScale;
use App\Models\ResultPolicy;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class ExamSetupTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $controller;
    private string $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = $this->makeUser(User::ROLE_EXAM_CONTROLLER);
        $this->year       = (string) now()->year;
    }

    private function exam(string $name, float $weight, string $status = 'draft'): Exam
    {
        return Exam::create(['academic_year' => $this->year, 'name' => $name, 'weight' => $weight, 'status' => $status, 'sort_order' => Exam::count() + 1]);
    }

    private function type(string $name): ExamComponentType
    {
        return ExamComponentType::where('name', $name)->firstOrFail();
    }

    private function parts(array $spec): array
    {
        return array_map(fn ($row) => ['component_type_id' => $this->type($row[0])->id, 'full_marks' => $row[1], 'pass_marks' => $row[2] ?? 0], $spec);
    }

    // ------------------------------------------------------------------
    // who may do this
    // ------------------------------------------------------------------

    public function test_only_the_exam_controller_and_admin_reach_exam_management(): void
    {
        foreach ([User::ROLE_TEACHER, User::ROLE_CASHIER, User::ROLE_FINANCE_HEAD, User::ROLE_USER] as $role) {
            $this->actingAs($this->makeUser($role))->get('/exams')->assertForbidden();
        }

        $this->actingAs($this->controller)->get('/exams')->assertOk();
        $this->actingAs($this->makeUser(User::ROLE_ADMIN))->get('/exams')->assertOk();
    }

    public function test_defaults_exist_without_any_setup(): void
    {
        $this->assertEqualsCanonicalizing(['Written', 'CQ', 'MCQ', 'Practical', 'Viva'], ExamComponentType::pluck('name')->all());
        $this->assertSame('Bangladesh 5-point scale', GradingScale::default()->name);

        $policy = ResultPolicy::forYear($this->year);
        $this->assertSame('per_subject', $policy->calculation_mode);
        $this->assertSame(GradingScale::default()->id, $policy->grading_scale_id);
    }

    // ------------------------------------------------------------------
    // exams and weights
    // ------------------------------------------------------------------

    public function test_exam_weights_cannot_exceed_100_percent(): void
    {
        $this->actingAs($this->controller)->post('/exams', ['academic_year' => $this->year, 'name' => '1st Terminal', 'weight' => 30])->assertSessionHasNoErrors();
        $this->actingAs($this->controller)->post('/exams', ['academic_year' => $this->year, 'name' => 'Half Yearly', 'weight' => 30])->assertSessionHasNoErrors();
        $this->actingAs($this->controller)->post('/exams', ['academic_year' => $this->year, 'name' => 'Annual', 'weight' => 41])->assertSessionHasErrors('weight');
        $this->actingAs($this->controller)->post('/exams', ['academic_year' => $this->year, 'name' => 'Annual', 'weight' => 40])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(100, Exam::weightTotal($this->year), 0.001);

        // same name twice in a year is refused, but fine in another year
        $this->actingAs($this->controller)->post('/exams', ['academic_year' => $this->year, 'name' => ' annual ', 'weight' => 0])->assertSessionHasErrors('name');
        $this->actingAs($this->controller)->post('/exams', ['academic_year' => '2099', 'name' => 'Annual', 'weight' => 100])->assertSessionHasNoErrors();
    }

    public function test_status_flow_and_publish_rules(): void
    {
        $exam = $this->exam('Annual', 60);
        $this->exam('Mid', 20);                       // total 80, not 100

        $go = fn (string $to) => $this->actingAs($this->controller)->post("/exams/{$exam->id}/status", ['status' => $to]);

        $go('published')->assertSessionHas('error');  // draft -> published is not allowed
        $go('open')->assertSessionHas('success');
        $go('closed')->assertSessionHas('success');
        $go('published')->assertSessionHas('error');  // weights are 80, not 100

        Exam::where('name', 'Mid')->update(['weight' => 40]);
        $go('published')->assertSessionHas('error');  // no papers yet

        ExamPaper::create(['exam_id' => $exam->id, 'class_id' => $this->makeClass()->id, 'subject_id' => Subject::first()->id,
            'component_type_id' => $this->type('Written')->id, 'full_marks' => 100, 'pass_marks' => 33]);
        $go('published')->assertSessionHas('success');
        $this->assertSame('published', $exam->fresh()->status);

        // a published exam cannot be edited or deleted
        $this->actingAs($this->controller)->put("/exams/{$exam->id}", ['academic_year' => $this->year, 'name' => 'X', 'weight' => 60])->assertSessionHas('error');
        $this->actingAs($this->controller)->delete("/exams/{$exam->id}")->assertSessionHas('error');
    }

    public function test_publishing_needs_the_publish_ability(): void
    {
        $role = \App\Models\Role::create(['slug' => 'setup_only', 'name' => 'Setup only', 'abilities' => ['exams.manage'], 'is_system' => false]);
        $user = $this->makeUser('setup_only');
        $exam = $this->exam('Annual', 100, 'closed');

        $this->actingAs($user)->post("/exams/{$exam->id}/status", ['status' => 'published'])->assertForbidden();
    }

    // ------------------------------------------------------------------
    // result rules
    // ------------------------------------------------------------------

    public function test_the_controller_changes_the_result_rules_and_they_freeze_when_published(): void
    {
        $scale = GradingScale::default();

        $rules = [
            'academic_year' => $this->year, 'calculation_mode' => 'overall_percentage', 'pass_rule_scope' => 'each_exam',
            'require_component_pass' => false, 'grading_scale_id' => $scale->id, 'optional_rule' => 'none', 'optional_threshold' => 2,
        ];

        $this->actingAs($this->controller)->post('/exam-policy', $rules)->assertSessionHas('success');

        $policy = ResultPolicy::forYear($this->year);
        $this->assertSame('overall_percentage', $policy->calculation_mode);
        $this->assertSame('each_exam', $policy->pass_rule_scope);
        $this->assertFalse($policy->require_component_pass);
        $this->assertSame('none', $policy->optional_rule);

        $this->actingAs($this->controller)->post('/exam-policy', array_merge($rules, ['calculation_mode' => 'nonsense']))->assertSessionHasErrors('calculation_mode');

        $this->exam('Annual', 100, 'published');
        $this->actingAs($this->controller)->post('/exam-policy', array_merge($rules, ['calculation_mode' => 'per_subject']))->assertSessionHas('error');
        $this->assertSame('overall_percentage', $policy->fresh()->calculation_mode);
    }

    // ------------------------------------------------------------------
    // grading scale + part types are editable
    // ------------------------------------------------------------------

    public function test_a_custom_grading_scale_grades_marks_correctly(): void
    {
        $this->actingAs($this->controller)->post('/grading-scales', [
            'name' => 'Strict',
            'rows' => [
                ['min_percent' => 90, 'grade' => 'Gold',   'grade_point' => 5, 'is_fail' => false],
                ['min_percent' => 50, 'grade' => 'Pass',   'grade_point' => 2, 'is_fail' => false],
                ['min_percent' => 0,  'grade' => 'Fail',   'grade_point' => 0, 'is_fail' => true],
            ],
        ])->assertSessionHasNoErrors();

        $scale = GradingScale::where('name', 'Strict')->firstOrFail();

        $this->assertSame('Gold', $scale->gradeFor(95)['grade']);
        $this->assertSame('Pass', $scale->gradeFor(50)['grade']);
        $this->assertSame('Pass', $scale->gradeFor(89.99)['grade']);
        $this->assertTrue($scale->gradeFor(49.9)['is_fail']);
    }

    public function test_the_default_bangladesh_scale_boundaries(): void
    {
        $scale = GradingScale::default();

        foreach ([[100, 'A+', 5], [80, 'A+', 5], [79.99, 'A', 4], [70, 'A', 4], [60, 'A-', 3.5], [50, 'B', 3], [40, 'C', 2], [33, 'D', 1], [32.99, 'F', 0], [0, 'F', 0]] as [$pct, $grade, $point]) {
            $g = $scale->gradeFor($pct);
            $this->assertSame($grade, $g['grade'], "{$pct}%");
            $this->assertEquals($point, $g['point'], "{$pct}%");
        }
    }

    public function test_a_scale_must_cover_zero_and_have_a_failing_grade(): void
    {
        $bad = fn (array $rows) => $this->actingAs($this->controller)->post('/grading-scales', ['name' => 'Bad ' . uniqid(), 'rows' => $rows]);

        $bad([['min_percent' => 50, 'grade' => 'A', 'grade_point' => 5, 'is_fail' => false], ['min_percent' => 20, 'grade' => 'F', 'grade_point' => 0, 'is_fail' => true]])
            ->assertStatus(422);                                              // nothing starts at 0%
        $bad([['min_percent' => 40, 'grade' => 'A', 'grade_point' => 5], ['min_percent' => 0, 'grade' => 'B', 'grade_point' => 2]])
            ->assertStatus(422);                                              // no failing grade
        $bad([['min_percent' => 40, 'grade' => 'A', 'grade_point' => 5], ['min_percent' => 40, 'grade' => 'B', 'grade_point' => 2, 'is_fail' => true], ['min_percent' => 0, 'grade' => 'F', 'grade_point' => 0, 'is_fail' => true]])
            ->assertStatus(422);                                              // two grades start at 40%
    }

    public function test_the_default_scale_cannot_be_deleted_or_a_scale_in_use(): void
    {
        $default = GradingScale::default();
        $other   = GradingScale::create(['name' => 'Other']);
        $other->rows()->createMany([
            ['min_percent' => 0, 'grade' => 'F', 'grade_point' => 0, 'is_fail' => true],
            ['min_percent' => 50, 'grade' => 'P', 'grade_point' => 1, 'is_fail' => false],
        ]);

        $this->actingAs($this->controller)->delete("/grading-scales/{$default->id}")->assertSessionHas('error');

        ResultPolicy::forYear($this->year)->update(['grading_scale_id' => $other->id]);
        $this->actingAs($this->controller)->delete("/grading-scales/{$other->id}")->assertSessionHas('error');

        $this->actingAs($this->controller)->post("/grading-scales/{$other->id}/default");
        $this->assertTrue($other->fresh()->is_default);
        $this->assertFalse($default->fresh()->is_default);
    }

    public function test_part_types_are_editable_and_protected_while_used(): void
    {
        $this->actingAs($this->controller)->post('/exam-component-types', ['name' => 'Lab Report', 'code' => 'LAB'])->assertSessionHasNoErrors();
        $this->actingAs($this->controller)->post('/exam-component-types', ['name' => ' lab report '])->assertSessionHasErrors('name');

        $lab = ExamComponentType::where('name', 'Lab Report')->firstOrFail();
        ExamPaper::create(['exam_id' => $this->exam('Annual', 100)->id, 'class_id' => $this->makeClass()->id,
            'subject_id' => Subject::first()->id, 'component_type_id' => $lab->id, 'full_marks' => 20]);

        $this->actingAs($this->controller)->delete("/exam-component-types/{$lab->id}")->assertSessionHas('error');
        $this->assertModelExists($lab);
    }

    // ------------------------------------------------------------------
    // per-class, per-subject setup
    // ------------------------------------------------------------------

    public function test_a_subject_can_have_several_parts_or_a_single_mcq_part(): void
    {
        $exam  = $this->exam('Annual', 100);
        $class = $this->makeClass('Class 9', 'A', attrs: ['subjects' => ['Physics', 'Religion']]);
        $physics  = Subject::where('name', 'Physics')->firstOrFail();
        $religion = Subject::where('name', 'Religion')->firstOrFail();

        $save = fn (Subject $s, array $papers) => $this->actingAs($this->controller)
            ->post("/exams/{$exam->id}/setup/subject", ['class_id' => $class->id, 'subject_id' => $s->id, 'papers' => $papers]);

        $save($physics, $this->parts([['CQ', 50, 17], ['MCQ', 25, 8], ['Practical', 25, 8]]))->assertSessionHas('success');
        $save($religion, $this->parts([['MCQ', 50, 17]]))->assertSessionHas('success');

        $this->assertSame(3, ExamPaper::where('exam_id', $exam->id)->where('subject_id', $physics->id)->count());
        $this->assertEquals(100, ExamPaper::where('exam_id', $exam->id)->where('subject_id', $physics->id)->sum('full_marks'));
        $this->assertSame(1, ExamPaper::where('exam_id', $exam->id)->where('subject_id', $religion->id)->count());

        // saving again REPLACES the parts; an empty list removes them
        $save($physics, $this->parts([['Written', 100, 33]]))->assertSessionHas('success');
        $this->assertSame(1, ExamPaper::where('subject_id', $physics->id)->count());
        $save($physics, [])->assertSessionHas('success');
        $this->assertSame(0, ExamPaper::where('subject_id', $physics->id)->count());
    }

    public function test_setup_rejects_bad_input(): void
    {
        $exam  = $this->exam('Annual', 100);
        $class = $this->makeClass('Class 9', 'A', attrs: ['subjects' => ['Physics']]);
        $other = $this->makeClass('Class 8', 'A', attrs: ['subjects' => ['Bangla']]);
        $physics = Subject::where('name', 'Physics')->firstOrFail();

        $post = fn (array $d) => $this->actingAs($this->controller)->post("/exams/{$exam->id}/setup/subject", $d + ['class_id' => $class->id, 'subject_id' => $physics->id]);

        $post(['papers' => $this->parts([['CQ', 50, 60]])])->assertSessionHas('error');                     // pass > full
        $post(['papers' => $this->parts([['CQ', 50], ['CQ', 20]])])->assertSessionHas('error');             // same part twice
        $post(['papers' => $this->parts([['CQ', 0]])])->assertSessionHasErrors('papers.0.full_marks');      // zero marks
        $post(['subject_id' => Subject::findOrCreateByName('Chemistry')->id, 'papers' => $this->parts([['CQ', 50]])])->assertSessionHas('error');   // class does not offer it

        $oldYear = $this->makeClass('Class 9', 'B', 2001, attrs: ['subjects' => ['Physics']]);
        $post(['class_id' => $oldYear->id, 'papers' => $this->parts([['CQ', 50]])])->assertSessionHas('error');   // other year

        $this->assertSame(0, ExamPaper::count());
    }

    public function test_the_setup_of_a_published_exam_is_locked(): void
    {
        $exam  = $this->exam('Annual', 100, 'published');
        $class = $this->makeClass('Class 9', 'A', attrs: ['subjects' => ['Physics']]);

        $this->actingAs($this->controller)->post("/exams/{$exam->id}/setup/subject", [
            'class_id' => $class->id, 'subject_id' => Subject::where('name', 'Physics')->value('id'), 'papers' => $this->parts([['CQ', 50]]),
        ])->assertSessionHas('error');

        $this->assertSame(0, ExamPaper::count());
    }

    public function test_bulk_tools_fill_a_class_copy_to_sections_and_copy_between_exams(): void
    {
        $annual = $this->exam('Annual', 50);
        $mid    = $this->exam('Mid', 50);
        $a = $this->makeClass('Class 6', 'A', attrs: ['subjects' => ['Bangla', 'English', 'Math']]);
        $b = $this->makeClass('Class 6', 'B', attrs: ['subjects' => ['Bangla', 'English', 'Science']]);

        // fill every subject of 6-A with CQ 70 + MCQ 30
        $this->actingAs($this->controller)->post("/exams/{$annual->id}/setup/fill", [
            'class_id' => $a->id, 'papers' => $this->parts([['CQ', 70, 23], ['MCQ', 30, 10]]),
        ])->assertSessionHas('success');
        $this->assertSame(6, ExamPaper::where('class_id', $a->id)->count());

        // copy 6-A's setup to 6-B: only the subjects 6-B also offers (Bangla, English)
        $this->actingAs($this->controller)->post("/exams/{$annual->id}/setup/apply", ['class_id' => $a->id, 'target_ids' => [$b->id]])->assertSessionHas('success');
        $this->assertSame(4, ExamPaper::where('class_id', $b->id)->count());
        $this->assertFalse(ExamPaper::where('class_id', $b->id)->where('subject_id', Subject::where('name', 'Science')->value('id'))->exists());

        // a second "fill" skips subjects already set up unless replace is chosen
        $this->actingAs($this->controller)->post("/exams/{$annual->id}/setup/fill", [
            'class_id' => $a->id, 'papers' => $this->parts([['Written', 100, 33]]),
        ]);
        $this->assertSame(6, ExamPaper::where('class_id', $a->id)->count());   // unchanged

        // copy the whole setup to another exam of the same year
        $this->actingAs($this->controller)->post("/exams/{$mid->id}/setup/copy", ['source_exam_id' => $annual->id])->assertSessionHas('success');
        $this->assertSame(ExamPaper::where('exam_id', $annual->id)->count(), ExamPaper::where('exam_id', $mid->id)->count());
    }

    public function test_the_setup_screen_lists_a_classes_subjects_with_their_parts(): void
    {
        $exam  = $this->exam('Annual', 100);
        $class = $this->makeClass('Class 9', 'A', attrs: ['subjects' => ['Physics']]);
        $this->actingAs($this->controller)->post("/exams/{$exam->id}/setup/subject", [
            'class_id' => $class->id, 'subject_id' => Subject::where('name', 'Physics')->value('id'), 'papers' => $this->parts([['CQ', 70, 23], ['MCQ', 30, 10]]),
        ]);

        $this->actingAs($this->controller)->get("/exams/{$exam->id}/setup?class_id={$class->id}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $physics = collect($props['subjects'])->firstWhere('name', 'Physics');
                $this->assertCount(2, $physics['papers']);
                $this->assertSame(1, collect($props['classes'])->first()['subjects_set_up']);
            });
    }
}
