<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudyGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Everything a demonstration school needs, in the right order:
 *
 *   subjects → study groups → curriculum → teachers → classes + class teachers
 *            → subject teachers → students (with optional-subject choices)
 *            → marks (two examinations) → attendance (40 school days)
 *
 * Run:      php artisan db:seed --class=DemoDataSeeder
 * Sizes:    config/demo.php  (DEMO_TEACHERS, DEMO_STUDENTS_PER_SECTION, DEMO_YEAR in .env)
 *
 * It only ADDS what is missing and never changes an existing account, class or student,
 * so it is safe to run on a database that already has data. Each step can also be run alone:
 *   --class=SubjectSeeder | StudyGroupSeeder | CurriculumSeeder | TeacherSeeder
 *   --class=ClassSeeder | SubjectTeacherSeeder | StudentSeeder | MarksSeeder | AttendanceSeeder
 * (each one first runs the steps it depends on).
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // MarksSeeder runs the whole chain before it: subjects, groups, curriculum, teachers, classes,
        // subject teachers, students. Attendance then needs those students.
        $this->call(MarksSeeder::class);
        $this->call(AttendanceSeeder::class);

        $year = (string) (config('demo.year') ?: now()->year);

        $this->command?->newLine();
        $this->command?->table(['Demo data', 'Count'], [
            ['Subjects', Subject::count()],
            ['Study groups', StudyGroup::count()],
            ['Teacher accounts', User::where('role', User::ROLE_TEACHER)->count()],
            ['Staff profiles (website)', Teacher::count()],
            ["Classes in {$year}", SchoolClass::where('academic_year', $year)->count()],
            ["Students in {$year}", Student::where('academic_year', (int) $year)->count()],
            ['Subject marks entered', \App\Models\ResultInput::where('academic_year', $year)->count()],
            ['Attendance records', \App\Models\Attendance::where('academic_year', $year)->count()],
        ]);
    }
}
