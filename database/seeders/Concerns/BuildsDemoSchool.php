<?php

namespace Database\Seeders\Concerns;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Demo classes, teachers and subject teachers for the demo seeders, used only when the school has no subject teachers of
 * its own. Everything is named "Demo …", and whatever already exists is reused.
 */
trait BuildsDemoSchool
{
    /**
     * @param  array<string,string>  $staff  subject name => name of the demo teacher who takes it (in this order)
     * @return Collection<int,SchoolClass>
     */
    protected function demoClasses(string $year, array $staff): Collection
    {
        $password = Str::password(12, symbols: false);
        $teachers = [];
        $created = false;

        foreach (array_values($staff) as $i => $name) {
            $email = 'demo.teacher' . ($i + 1) . '@school.test';
            $user = User::where('email', $email)->first();

            if (! $user) {
                $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => User::ROLE_TEACHER, 'status' => User::STATUS_ACTIVE]);
                $created = true;
            }

            $teachers[array_keys($staff)[$i]] = $user;
        }

        $created && $this->command?->warn('New demo teachers: demo.teacher1@school.test … demo.teacher' . count($staff) . "@school.test / {$password}");

        return collect([['Demo Class 6', 'A'], ['Demo Class 6', 'B'], ['Demo Class 7', 'A']])->map(function ($row) use ($year, $teachers) {
            [$name, $section] = $row;
            $class = SchoolClass::where(['class_name' => $name, 'section' => $section, 'academic_year' => $year])->first();

            if (! $class) {
                $class = SchoolClass::create(['class_name' => $name, 'section' => $section, 'academic_year' => $year, 'status' => 'active']);
                $class->syncSubjects(array_keys($teachers));
                $class = $class->fresh();

                foreach ($teachers as $subject => $teacher) {
                    $class->assignSubjectTeacher($subject, $teacher);
                }
            }

            return $class;
        });
    }
}
