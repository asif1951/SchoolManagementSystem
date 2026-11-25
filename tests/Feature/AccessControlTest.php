<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    public function test_guests_are_sent_to_login(): void
    {
        $class = $this->makeClass();

        foreach (['/dashboard', '/students', '/users', "/attendance/{$class->id}/records"] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_plain_users_cannot_reach_staff_or_admin_pages(): void
    {
        $user  = $this->makeUser(User::ROLE_USER);
        $class = $this->makeClass();

        foreach (['/dashboard', '/users', '/students', '/fees', '/results', "/attendance/{$class->id}/records"] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    /** Regression: this route used to have no role check at all. */
    public function test_attendance_records_are_staff_only(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);
        $class   = $this->makeClass(teacher: $teacher);

        $this->actingAs($this->makeUser(User::ROLE_USER))
            ->get("/attendance/{$class->id}/records")
            ->assertForbidden();

        // A teacher sees the records of their own class...
        $this->actingAs($teacher)->get("/attendance/{$class->id}/records")->assertOk();

        // ...but not of another teacher's class.
        $other = $this->makeClass('Class 2', 'B');
        $this->actingAs($teacher)->get("/attendance/{$other->id}/records")->assertForbidden();
    }

    public function test_teachers_get_staff_pages_but_not_admin_pages(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);

        $this->actingAs($teacher)->get('/student-module/students')->assertOk();
        $this->actingAs($teacher)->get('/fees')->assertForbidden();          // money is the finance department's
        $this->actingAs($teacher)->get('/dashboard')->assertForbidden();
        $this->actingAs($teacher)->get('/users')->assertForbidden();
        $this->actingAs($teacher)->get('/website-management/settings')->assertForbidden();
    }

    public function test_admin_can_reach_everything(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        foreach (['/dashboard', '/users', '/student-module/students', '/fees', '/website-management/settings', '/gallery'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        $user = $this->makeUser(User::ROLE_ADMIN, ['status' => User::STATUS_SUSPENDED]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_account_suspended_mid_session_is_logged_out(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $admin->update(['status' => User::STATUS_SUSPENDED]);

        $this->actingAs($admin->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_every_protected_route_is_behind_auth(): void
    {
        $open = ['home', 'teachers', 'result', 'result.fetch', 'contact', 'contact.store', 'about', 'students.verify',
            'login', 'register', 'password.request', 'password.email', 'password.reset', 'password.store',
            'verification.notice', 'verification.verify', 'verification.send', 'storage.local',
            'apply.show', 'apply.store', 'locale'];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || in_array($name, $open, true) || str_starts_with($name, 'sanctum.') || str_starts_with($name, 'storage.') || str_starts_with($name, 'ignition.')) {
                continue;
            }

            $this->assertTrue(
                in_array('auth', $route->gatherMiddleware(), true)
                    || in_array('guest', $route->gatherMiddleware(), true)
                    || in_array('signed', $route->gatherMiddleware(), true),
                "Route [{$name}] is not behind auth/guest middleware."
            );
        }
    }
}
