<?php

namespace Tests\Feature;

use App\Models\AdmissionFee;
use App\Models\AdmissionPayment;
use App\Models\OnlineApplication;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\StudentModuleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentModuleSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['demo.teachers' => 30, 'demo.students_per_section' => 3, 'demo.year' => null]);

        // the admin account normally comes from DatabaseSeeder
        User::create(['name' => 'Admin', 'email' => 'admin@school.test', 'password' => 'secret-pass-123', 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);

        $this->seed(StudentModuleDemoSeeder::class);
    }

    public function test_roles_and_the_accountant_exist(): void
    {
        foreach (['admin', 'teacher', 'accountant'] as $slug) {
            $this->assertTrue(Role::where('slug', $slug)->exists(), "role {$slug} is missing");
        }

        $this->assertSame('accountant', User::where('email', 'accountant@school.test')->value('role'));
    }

    public function test_some_students_have_a_brn_and_some_do_not(): void
    {
        $with = StudentProfile::whereNotNull('brn')->count();
        $without = StudentProfile::whereNull('brn')->count();

        $this->assertGreaterThan(0, $with);
        $this->assertGreaterThan(0, $without);

        StudentProfile::whereNotNull('brn')->pluck('brn')->each(fn ($b) => $this->assertMatchesRegularExpression('/^\d{17}$/', $b));
        $this->assertSame($with, StudentProfile::whereNotNull('brn')->distinct()->count('brn'));
    }

    public function test_fees_payments_and_applications_are_seeded(): void
    {
        $this->assertGreaterThan(0, AdmissionFee::count());
        $this->assertGreaterThan(0, AdmissionPayment::count());
        $this->assertSame(2, OnlineApplication::where('status', 'pending')->count());
    }

    public function test_running_it_again_changes_nothing_and_keeps_existing_values(): void
    {
        $profile = StudentProfile::whereNotNull('brn')->first();
        $brn = $profile->brn;
        AdmissionFee::query()->first()->update(['amount' => 7777]);

        $before = [StudentProfile::whereNotNull('brn')->count(), AdmissionPayment::count(), OnlineApplication::count(), AdmissionFee::count(), User::count()];

        $this->seed(StudentModuleDemoSeeder::class);

        $this->assertSame($before, [StudentProfile::whereNotNull('brn')->count(), AdmissionPayment::count(), OnlineApplication::count(), AdmissionFee::count(), User::count()]);
        $this->assertSame($brn, $profile->fresh()->brn);
        $this->assertSame('7777.00', AdmissionFee::query()->first()->fresh()->amount);
    }
}
