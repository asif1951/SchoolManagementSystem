<?php

namespace Tests\Feature;

use App\Models\ClassTeacherAssignment;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class ClassTeacherTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    public function test_each_year_keeps_its_own_class_teacher(): void
    {
        $asif   = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Asif']);
        $keshob = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Keshob']);

        $class2026 = $this->makeClass('Class 6', 'A', 2026, teacher: $asif);
        $class2027 = $this->makeClass('Class 6', 'A', 2027, teacher: $keshob);

        $this->assertSame('Asif', $class2026->class_teacher);
        $this->assertSame('Keshob', $class2027->class_teacher);

        // The 2026 record still says Asif after 2027 exists.
        $this->assertSame('Asif', $class2026->teacherAssignments()->first()->teacher_name);
    }

    public function test_changing_the_teacher_closes_the_old_stint_and_keeps_history(): void
    {
        $admin  = $this->makeUser(User::ROLE_ADMIN);
        $asif   = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Asif']);
        $keshob = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Keshob']);
        $class  = $this->makeClass('Class 6', 'A', teacher: $asif);

        $class->assignTeacher($keshob, $admin, '2026-07-01');

        $history = $class->teacherAssignments()->get();

        $this->assertCount(2, $history);
        $this->assertSame($keshob->id, $class->fresh()->class_teacher_id);

        $old = $history->firstWhere('teacher_name', 'Asif');
        $this->assertSame('2026-07-01', $old->ends_on->toDateString());   // Asif's stint ended
        $new = $history->firstWhere('teacher_name', 'Keshob');
        $this->assertNull($new->ends_on);                                  // Keshob is current
        $this->assertSame($admin->id, $new->assigned_by);
    }

    public function test_assigning_the_same_teacher_again_adds_no_history(): void
    {
        $asif  = $this->makeUser(User::ROLE_TEACHER);
        $class = $this->makeClass(teacher: $asif);

        $class->assignTeacher($asif);
        $class->assignTeacher($asif);

        $this->assertSame(1, ClassTeacherAssignment::where('class_id', $class->id)->count());
    }

    public function test_only_the_current_class_teacher_may_manage_the_class(): void
    {
        $asif   = $this->makeUser(User::ROLE_TEACHER);
        $keshob = $this->makeUser(User::ROLE_TEACHER);
        $class  = $this->makeClass(teacher: $asif);

        $this->assertTrue($asif->canManageClass($class));
        $this->assertFalse($keshob->canManageClass($class));

        $class->assignTeacher($keshob);

        $this->assertFalse($asif->fresh()->canManageClass($class->fresh()));   // former teacher loses access
        $this->assertTrue($keshob->fresh()->canManageClass($class->fresh()));
    }

    public function test_access_is_tied_to_the_account_not_the_name(): void
    {
        $asif  = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Asif']);
        $other = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Asif']);   // same name, different person
        $class = $this->makeClass(teacher: $asif);

        $this->actingAs($other)->get("/attendance?class={$class->id}")->assertForbidden();
        $this->actingAs($asif)->get("/attendance?class={$class->id}")->assertOk();

        // Renaming the teacher must not lock them out.
        $asif->update(['name' => 'Asif Rahman']);
        $this->actingAs($asif->fresh())->get("/attendance?class={$class->id}")->assertOk();
    }

    public function test_admin_changes_teacher_through_the_class_screen_and_history_is_written(): void
    {
        $admin  = $this->makeUser(User::ROLE_ADMIN);
        $asif   = $this->makeUser(User::ROLE_TEACHER);
        $keshob = $this->makeUser(User::ROLE_TEACHER);
        $class  = $this->makeClass('Class 6', 'A', teacher: $asif);

        $payload = fn (User $t) => [
            'className' => 'Class 6', 'section' => 'A', 'academicYear' => (string) $class->academic_year,
            'classTeacher' => $t->id, 'status' => 'active', 'subjects' => ['Math'],
        ];

        $this->actingAs($admin)->put("/classesmanagement/{$class->id}", $payload($keshob))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame($keshob->id, $class->fresh()->class_teacher_id);
        $this->assertSame(2, $class->teacherAssignments()->count());
    }

    public function test_only_an_active_teacher_can_be_made_class_teacher(): void
    {
        $admin  = $this->makeUser(User::ROLE_ADMIN);
        $parent = $this->makeUser(User::ROLE_USER);
        $gone   = $this->makeUser(User::ROLE_TEACHER, ['status' => User::STATUS_SUSPENDED]);

        foreach ([$parent, $gone] as $bad) {
            $this->actingAs($admin)->post('/classesmanagement', [
                'className' => 'Class 6', 'section' => 'A', 'academicYear' => '2026',
                'classTeacher' => $bad->id, 'status' => 'active', 'subjects' => ['Math'],
            ])->assertSessionHasErrors('classTeacher');
        }

        $this->assertSame(0, SchoolClass::count());
    }

    public function test_same_class_can_exist_in_two_years(): void
    {
        $admin   = $this->makeUser(User::ROLE_ADMIN);
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $this->makeClass('Class 6', 'A', 2026, teacher: $teacher);

        $this->actingAs($admin)->post('/classes', [
            'className' => 'Class 6', 'section' => 'A', 'academicYear' => '2027',
            'classTeacher' => $teacher->id, 'status' => 'active', 'subjects' => ['Math'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, SchoolClass::where('class_name', 'Class 6')->count());
    }

    public function test_new_year_classes_copy_the_teacher_with_a_dated_assignment(): void
    {
        $asif = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Asif']);
        $this->makeClass('Class 6', 'A', 2026, teacher: $asif);

        SchoolClass::ensureForYear(2027);

        $next = SchoolClass::where('academic_year', '2027')->firstOrFail();
        $this->assertSame('Asif', $next->class_teacher);
        $this->assertSame('2027-01-01', $next->teacherAssignments()->first()->starts_on->toDateString());
    }

    public function test_history_survives_when_a_teacher_account_is_deleted(): void
    {
        $asif  = $this->makeUser(User::ROLE_TEACHER, ['name' => 'Asif']);
        $class = $this->makeClass(teacher: $asif);

        $asif->delete();

        $this->assertNull($class->fresh()->class_teacher_id);
        $this->assertSame('Asif', $class->teacherAssignments()->first()->teacher_name);   // name snapshot kept
    }
}
