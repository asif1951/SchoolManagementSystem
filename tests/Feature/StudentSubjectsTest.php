<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudyGroup;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentRegistry;
use App\Services\StudentSubjects;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class StudentSubjectsTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private SchoolClass $class;
    private StudyGroup $science;
    private StudyGroup $arts;
    private Subject $physics;
    private Subject $history;
    private Subject $agri;
    private Subject $bangla;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->class = $this->makeClass('Class 9', 'A');
        $this->science = StudyGroup::create(['name' => 'Science', 'is_active' => true]);
        $this->arts = StudyGroup::create(['name' => 'Arts', 'is_active' => true]);
        $this->bangla = Subject::create(['name' => 'Bangla']);
        $this->physics = Subject::create(['name' => 'Physics']);
        $this->history = Subject::create(['name' => 'History']);
        $this->agri = Subject::create(['name' => 'Agriculture']);

        $id = fn (Subject $s, bool $optional) => DB::table('class_subjects')->insertGetId(['class_id' => $this->class->id, 'subject_id' => $s->id, 'is_optional' => $optional, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $id($this->bangla, false);
        DB::table('class_subject_groups')->insert(['class_subject_id' => $id($this->physics, false), 'study_group_id' => $this->science->id]);
        DB::table('class_subject_groups')->insert(['class_subject_id' => $id($this->history, false), 'study_group_id' => $this->arts->id]);
        $id($this->agri, true);
    }

    private function kid(): Student
    {
        $this->n++;

        return app(StudentRegistry::class)->admit([
            'name_en' => 'Student ' . chr(64 + $this->n) . 'ahim', 'dob' => now()->subYears(14)->addDays($this->n)->toDateString(),
            'guardian_mobile' => '0173' . str_pad((string) $this->n, 7, '0', STR_PAD_LEFT), 'class_id' => $this->class->id, 'roll_number' => (string) $this->n,
        ], $this->admin);
    }

    private function svc(): StudentSubjects
    {
        return app(StudentSubjects::class);
    }

    public function test_plan_depends_on_the_group(): void
    {
        $sci = $this->svc()->planFor($this->class, $this->science->id);
        $names = array_column($sci['compulsory'], 'name');
        $this->assertContains('Bangla', $names);
        $this->assertContains('Physics', $names);
        $this->assertNotContains('History', $names);
        $this->assertSame(['Agriculture'], array_column($sci['optional'], 'name'));

        $arts = $this->svc()->planFor($this->class, $this->arts->id);
        $names = array_column($arts['compulsory'], 'name');
        $this->assertContains('History', $names);
        $this->assertNotContains('Physics', $names);

        // no group: only the subjects open to everyone
        $open = array_column($this->svc()->planFor($this->class, null)['compulsory'], 'name');
        $this->assertContains('Bangla', $open);
        $this->assertNotContains('Physics', $open);
        $this->assertNotContains('History', $open);
    }

    public function test_assign_group_and_optional_subject_and_audit(): void
    {
        $s = $this->kid();

        $this->svc()->assign($s, $this->science->id, [$this->agri->id], $this->admin);

        $this->assertSame($this->science->id, (int) $s->fresh()->study_group_id);
        $this->assertSame([$this->agri->id], DB::table('student_optional_subjects')->where('student_id', $s->id)->pluck('subject_id')->all());
        $this->assertSame(1, AuditLog::where('event', 'subjects_assigned')->where('auditable_id', $s->id)->count());

        // saving the same thing again changes nothing and logs nothing
        $this->svc()->assign($s, $this->science->id, [$this->agri->id], $this->admin);
        $this->assertSame(1, AuditLog::where('event', 'subjects_assigned')->count());

        // clearing removes the choice
        $this->svc()->assign($s, $this->science->id, [], $this->admin);
        $this->assertSame(0, DB::table('student_optional_subjects')->where('student_id', $s->id)->count());
    }

    public function test_a_subject_outside_the_plan_or_over_the_limit_is_refused(): void
    {
        $s = $this->kid();

        try {
            $this->svc()->assign($s, $this->science->id, [$this->physics->id], $this->admin); // compulsory, not optional
            $this->fail('expected a refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('optional', $e->errors());
        }

        $extra = Subject::create(['name' => 'Music']);
        DB::table('class_subjects')->insert(['class_id' => $this->class->id, 'subject_id' => $extra->id, 'is_optional' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(ValidationException::class);
        $this->svc()->assign($s, null, [$this->agri->id, $extra->id], $this->admin); // limit is 1
    }

    public function test_class_of_one_group_rejects_another_group(): void
    {
        $arts = $this->makeClass('Class 10', 'A');
        $arts->update(['study_group_id' => $this->arts->id]);
        $s = app(StudentRegistry::class)->admit(['name_en' => 'Mina Akter', 'dob' => '2012-01-02', 'guardian_mobile' => '01731111111', 'class_id' => $arts->id, 'roll_number' => '1'], $this->admin);

        $this->expectException(ValidationException::class);
        $this->svc()->assign($s, $this->science->id, [], $this->admin);
    }

    public function test_bulk_group_and_subject_and_foreign_students_are_refused(): void
    {
        $a = $this->kid();
        $b = $this->kid();

        $changed = $this->svc()->bulk($this->class, [$a->id, $b->id], $this->science->id, $this->agri->id, $this->admin);
        $this->assertSame(2, $changed);
        $this->assertSame(2, DB::table('student_optional_subjects')->where('subject_id', $this->agri->id)->count());
        $this->assertSame(0, $this->svc()->bulk($this->class, [$a->id, $b->id], $this->science->id, $this->agri->id, $this->admin)); // nothing new

        $other = $this->makeClass('Class 8', 'A');
        $stranger = app(StudentRegistry::class)->admit(['name_en' => 'Outsider Boy', 'dob' => '2013-03-03', 'guardian_mobile' => '01732222222', 'class_id' => $other->id, 'roll_number' => '1'], $this->admin);

        $this->expectException(ValidationException::class);
        $this->svc()->bulk($this->class, [$a->id, $stranger->id], $this->science->id, null, $this->admin);
    }

    public function test_class_scoped_permission_is_enforced(): void
    {
        $s = $this->kid();
        Role::firstOrCreate(['slug' => 'nobody_s'], ['name' => 'nobody', 'abilities' => [], 'is_system' => false]);
        $nobody = $this->makeUser('nobody_s');

        $this->expectException(AuthorizationException::class);
        $this->svc()->assign($s, null, [], $nobody);
    }

    public function test_results_follow_the_assignment(): void
    {
        $a = $this->kid();
        $b = $this->kid();
        $this->svc()->assign($a, $this->science->id, [$this->agri->id], $this->admin);
        $this->svc()->assign($b, $this->science->id, [], $this->admin);

        $takers = $this->class->studentsTaking('Agriculture')->pluck('students.id')->all();

        $this->assertContains($a->id, $takers);
        $this->assertNotContains($b->id, $takers);
    }

    public function test_page_and_endpoints(): void
    {
        $s = $this->kid();
        $year = (string) $this->class->academic_year;

        $this->actingAs($this->admin)->get(route('sm.subjects.index', ['session' => $year, 'class_id' => $this->class->id]))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('StudentModule/StudentSubjects')->has('matrix.students', 1)->where('matrix.max', 1));

        $this->actingAs($this->admin)->put(route('sm.subjects.update', $s), ['group_id' => $this->science->id, 'optional' => [$this->agri->id]])->assertRedirect();
        $this->assertSame($this->science->id, (int) $s->fresh()->study_group_id);

        $this->actingAs($this->admin)->put(route('sm.subjects.update', $s), ['group_id' => $this->science->id, 'optional' => [$this->physics->id]])->assertSessionHasErrors('optional');

        $this->actingAs($this->admin)->post(route('sm.subjects.bulk', $this->class), ['students' => [$s->id]])->assertStatus(422);
        $this->actingAs($this->admin)->post(route('sm.subjects.bulk', $this->class), ['students' => [$s->id], 'group_id' => $this->arts->id])->assertRedirect();
        $this->assertSame($this->arts->id, (int) $s->fresh()->study_group_id);
    }

    public function test_user_without_permission_gets_an_empty_class_list(): void
    {
        Role::firstOrCreate(['slug' => 'nobody_s'], ['name' => 'nobody', 'abilities' => [], 'is_system' => false]);
        $nobody = $this->makeUser('nobody_s');

        $this->actingAs($nobody)->get(route('sm.subjects.index'))->assertForbidden();
    }
}
