<?php

namespace Tests\Concerns;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;

/**
 * Small builders so each test can set up exactly the school data it needs.
 */
trait MakesSchoolData
{
    protected function makeUser(string $role = User::ROLE_USER, array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role'   => $role,
            'status' => User::STATUS_ACTIVE,
        ], $attrs));
    }

    /** Pass `teacher: $user` (or attrs['teacher']) to make that user the class teacher. */
    protected function makeClass(string $name = 'Class 1', ?string $section = 'A', ?int $year = null, array $attrs = [], ?User $teacher = null): SchoolClass
    {
        $teacher ??= $attrs['teacher'] ?? null;
        $subjects = $attrs['subjects'] ?? ['Math', 'English'];
        unset($attrs['teacher'], $attrs['subjects']);

        $class = SchoolClass::create(array_merge([
            'class_name'    => $name,
            'section'       => $section,
            'academic_year' => (string) ($year ?? now()->year),
            'status'        => 'active',
        ], $attrs));

        $class->syncSubjects($subjects);

        if ($teacher) {
            $class->assignTeacher($teacher);
        }

        return $class->fresh();
    }

    protected function makeStudent(SchoolClass $class, string $name = 'Test Student', array $attrs = []): Student
    {
        static $n = 0;
        $n++;

        return Student::create(array_merge([
            'student_name'   => $name,
            'student_id'     => sprintf('T-%05d', $n),
            'academic_year'  => (int) $class->academic_year,
            'class_id'       => $class->id,
            'roll_number'    => (string) $n,
            'admission_date' => now()->toDateString(),
            'status'         => 'active',
        ], $attrs));
    }
}
