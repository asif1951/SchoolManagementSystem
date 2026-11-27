<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private function teacherWithClass(): array
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $mine    = $this->makeClass('Class 1', 'A', teacher: $teacher);
        $other   = $this->makeClass('Class 2', 'A');

        return [$teacher, $mine, $other];
    }

    // ------------------------------------------------------------------
    // class teacher scope
    // ------------------------------------------------------------------

    public function test_class_teacher_sees_only_their_own_students(): void
    {
        [$teacher, $mine, $other] = $this->teacherWithClass();
        $this->makeStudent($mine, 'My Kid');
        $this->makeStudent($other, 'Their Kid');

        $this->actingAs($teacher)->get('/student-module/students')
            ->assertOk()
            ->assertInertia(function ($page) {
                $names = collect($page->toArray()['props']['students']['data'])->pluck('name');
                $this->assertTrue($names->contains('My Kid'));
                $this->assertFalse($names->contains('Their Kid'));
            });
    }

    public function test_class_teacher_cannot_touch_students_of_other_classes(): void
    {
        [$teacher, $mine, $other] = $this->teacherWithClass();
        $stranger = $this->makeStudent($other, 'Stranger');
        $own      = $this->makeStudent($mine, 'Own');

        // teacher role has view/edit only for own classes by default; give edit/create to test scope itself
        Role::where('slug', 'teacher')->update(['abilities' => ['students.view:own', 'students.edit:own', 'students.create:own', 'students.delete:own']]);
        \Illuminate\Support\Facades\Cache::forget(Role::CACHE_KEY);

        // the old show / edit URLs now lead into the Student Module, which enforces the same class scope
        $this->actingAs($teacher)->followingRedirects()->get("/students/{$stranger->id}")->assertForbidden();
        $this->actingAs($teacher)->delete("/students/{$stranger->id}")->assertForbidden();
        $this->actingAs($teacher)->followingRedirects()->get("/students/{$own->id}/edit")->assertOk();

        // cannot add a student to someone else's class
        $this->actingAs($teacher)->post('/students', [
            'studentName' => 'Sneaky', 'studentId' => '91000001', 'academicYear' => (int) $other->academic_year,
            'class' => 'Class 2', 'section' => 'A', 'admissionDate' => now()->toDateString(), 'status' => 'active',
        ])->assertForbidden();
        $this->assertDatabaseMissing('students', ['student_name' => 'Sneaky']);

        // cannot move their own student into someone else's class
        $this->actingAs($teacher)->put("/students/{$own->id}", [
            'studentName' => 'Own', 'studentId' => $own->student_id, 'academicYear' => (int) $other->academic_year,
            'class' => 'Class 2', 'section' => 'A', 'admissionDate' => now()->toDateString(), 'status' => 'active',
        ])->assertForbidden();
        $this->assertSame($mine->id, $own->fresh()->class_id);
    }

    public function test_class_teacher_cannot_see_another_classes_attendance_or_results(): void
    {
        [$teacher, $mine, $other] = $this->teacherWithClass();

        $this->actingAs($teacher)->get("/attendance?class={$mine->id}")->assertOk();
        $this->actingAs($teacher)->get("/attendance?class={$other->id}")->assertForbidden();
        $this->actingAs($teacher)->get("/results/class/{$other->id}")->assertForbidden();
        $this->actingAs($teacher)->get("/student-results/class/{$other->id}")->assertForbidden();
    }

    public function test_teacher_can_lock_but_only_admin_can_unlock_results(): void
    {
        [$teacher, $mine] = $this->teacherWithClass();
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($teacher)->post("/results/class/{$mine->id}/unlock", ['exam_type' => 'final', 'subject' => 'Math'])
            ->assertForbidden();
        $this->actingAs($admin)->post("/results/class/{$mine->id}/unlock", ['exam_type' => 'final', 'subject' => 'Math'])
            ->assertSessionMissing('errors');
    }

    // ------------------------------------------------------------------
    // finance roles
    // ------------------------------------------------------------------

    public function test_cashier_collects_but_cannot_discount_or_change_structures(): void
    {
        $cashier = $this->makeUser(User::ROLE_CASHIER);
        $student = $this->makeStudent($this->makeClass());
        $year    = (string) $student->academic_year;

        $this->actingAs($cashier)->get('/fees')->assertOk();
        $this->actingAs($cashier)->post('/fees/collect-payment', ['student_id' => $student->id, 'academic_year' => $year, 'amount' => 100])
            ->assertSessionHas('success');

        $this->actingAs($cashier)->post('/fees/policy/save', ['student_id' => $student->id, 'academic_year' => $year, 'policies' => []])
            ->assertForbidden();
        $this->actingAs($cashier)->get('/fees/create')->assertForbidden();
        $this->actingAs($cashier)->delete('/fees/destroy-group', ['class_id' => $student->class_id, 'academic_year' => $year])
            ->assertForbidden();

        // and nothing else in the school
        $this->actingAs($cashier)->get('/attendance')->assertForbidden();
        $this->actingAs($cashier)->get('/results')->assertForbidden();
        $this->actingAs($cashier)->get('/users')->assertForbidden();
    }

    public function test_finance_head_can_do_every_fee_action(): void
    {
        $head = $this->makeUser(User::ROLE_FINANCE_HEAD);

        foreach (['/fees', '/fees/create'] as $url) {
            $this->actingAs($head)->get($url)->assertOk();
        }

        $this->actingAs($head)->get('/dashboard')->assertForbidden();
        $this->actingAs($head)->get('/roles')->assertForbidden();
    }

    // ------------------------------------------------------------------
    // roles screen
    // ------------------------------------------------------------------

    public function test_only_admin_reaches_the_roles_screen(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_TEACHER))->get('/roles')->assertForbidden();
        $this->actingAs($this->makeUser(User::ROLE_FINANCE_HEAD))->get('/roles')->assertForbidden();
        $this->actingAs($this->makeUser(User::ROLE_ADMIN))->get('/roles')->assertOk();
    }

    public function test_admin_can_create_a_custom_role_and_it_takes_effect_immediately(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->post('/roles', [
            'name' => 'Library Assistant', 'abilities' => ['students.view', 'students.edit:own', 'bogus.ability', 'users.manage:own'],
        ])->assertSessionHas('success');

        $role = Role::where('slug', 'library_assistant')->firstOrFail();

        // unknown abilities and ":own" on a non-scoped ability are discarded
        $this->assertEqualsCanonicalizing(['students.view', 'students.edit:own'], $role->abilities);

        $assistant = $this->makeUser('library_assistant');
        $this->actingAs($assistant)->get('/student-module/students')->assertOk();
        $this->actingAs($assistant)->get('/fees')->assertForbidden();

        // change the role -> effect is immediate (no stale cache)
        $this->actingAs($admin)->put("/roles/{$role->id}", ['name' => 'Library Assistant', 'abilities' => ['fees.view']])->assertSessionHas('success');
        $this->actingAs($assistant->fresh())->get('/student-module/students')->assertForbidden();
        $this->actingAs($assistant->fresh())->get('/fees')->assertOk();
    }

    public function test_admin_role_cannot_be_weakened_and_system_roles_cannot_be_deleted(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $role  = Role::where('slug', 'admin')->first();

        $this->actingAs($admin)->put("/roles/{$role->id}", ['name' => 'Administrator', 'abilities' => []]);
        $this->assertSame(['*'], $role->fresh()->abilities);

        $this->actingAs($admin)->delete('/roles/' . Role::where('slug', 'teacher')->value('id'))->assertSessionHas('error');
        $this->assertDatabaseHas('roles', ['slug' => 'teacher']);
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $role  = Role::create(['slug' => 'temp_role', 'name' => 'Temp', 'abilities' => [], 'is_system' => false]);
        $this->makeUser('temp_role');

        $this->actingAs($admin)->delete("/roles/{$role->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('roles', ['slug' => 'temp_role']);

        User::where('role', 'temp_role')->delete();
        $this->actingAs($admin)->delete("/roles/{$role->id}")->assertSessionHas('success');
        $this->assertDatabaseMissing('roles', ['slug' => 'temp_role']);
    }

    public function test_users_screen_accepts_only_existing_roles(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->post('/users', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'password123', 'role' => 'cashier', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'n@example.com', 'role' => 'cashier']);

        $this->actingAs($admin)->post('/users', ['name' => 'M', 'email' => 'm@example.com', 'password' => 'password123', 'role' => 'superhero', 'status' => 'active'])
            ->assertSessionHasErrors('role');
    }

    // ------------------------------------------------------------------
    // what the browser is told, and where people land
    // ------------------------------------------------------------------

    public function test_the_browser_receives_the_users_abilities_for_menus(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_CASHIER))->get('/fees')
            ->assertInertia(fn ($page) => $page->where('auth.abilities', ['students.view', 'classes.view', 'fees.view', 'fees.collect', 'calendar.view']));
    }

    public function test_each_role_lands_on_the_first_page_it_can_open(): void
    {
        foreach ([
            User::ROLE_ADMIN        => '/dashboard',
            User::ROLE_TEACHER      => '/students',
            User::ROLE_CASHIER      => '/fees',
            User::ROLE_FINANCE_HEAD => '/fees',
        ] as $role => $path) {
            $user = $this->makeUser($role);
            $this->assertSame($path, $user->homePath(), "role {$role}");
        }

        $this->assertSame('/', $this->makeUser(User::ROLE_USER)->homePath());
    }
}
