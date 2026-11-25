<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a demonstration school (local/demo use only).
     *
     *  1. an administrator and one demo teacher — random passwords, printed once
     *  2. the bulk demo data: subjects, groups, curriculum, teachers, classes, students
     *     (see DemoDataSeeder; sizes in config/demo.php).
     *
     * Set DEMO_DATA=false in .env to create only the two accounts.
     */
    public function run(): void
    {
        $adminPassword = Str::random(14);

        User::create([
            'name'              => 'Admin',
            'email'             => 'admin@school.test',
            'password'          => $adminPassword,
            'role'              => User::ROLE_ADMIN,
            'status'            => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $teacherPassword = Str::random(14);

        User::create([
            'name'              => 'Demo Teacher',
            'email'             => 'teacher@school.test',
            'password'          => $teacherPassword,
            'role'              => User::ROLE_TEACHER,
            'status'            => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->command?->warn("Admin   : admin@school.test   / {$adminPassword}");
        $this->command?->warn("Teacher : teacher@school.test / {$teacherPassword}");

        if (filter_var(env('DEMO_DATA', true), FILTER_VALIDATE_BOOLEAN)) {
            $this->call(StudentModuleDemoSeeder::class);   // the demo school, then the Student Module's demo data
        }
    }
}
