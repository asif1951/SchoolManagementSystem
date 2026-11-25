<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Decides who teaches each subject of each class. A teacher is matched to subjects by
 * their specialty (the "department" of their staff profile), so one Math teacher
 * teaches Mathematics across several classes. Work is spread evenly inside each specialty.
 * A subject that already has a teacher is left alone.
 */
class SubjectTeacherSeeder extends Seeder
{
    /** subjects taught by the teachers of another specialty */
    private const SAME_SPECIALTY = ['General Mathematics' => 'Mathematics'];

    public function run(): void
    {
        $this->call(ClassSeeder::class);

        $year = (string) (config('demo.year') ?: now()->year);

        $users = User::query()->where('role', User::ROLE_TEACHER)->where('status', User::STATUS_ACTIVE)->get()->keyBy('email');

        $bySpecialty = [];
        foreach (Teacher::query()->whereNotNull('email')->orderBy('order')->get(['email', 'department']) as $profile) {
            if ($user = $users->get($profile->email)) {
                $bySpecialty[$profile->department][] = $user;
            }
        }

        $everyone = $users->values();
        $turn     = [];
        $assigned = 0;

        foreach (SchoolClass::query()->where('academic_year', $year)->orderBy('id')->get() as $class) {
            $current = $class->currentSubjectTeachers();

            foreach ($class->subjectPlan() as $plan) {
                $subject = $plan['name'];

                if ($current->has($subject)) {
                    continue;
                }

                $key  = self::SAME_SPECIALTY[$subject] ?? $subject;
                $pool = collect($bySpecialty[$key] ?? $everyone->all());

                if ($pool->isEmpty()) {
                    continue;
                }

                $teacher = $pool[($turn[$key] = ($turn[$key] ?? -1) + 1) % $pool->count()];

                $class->assignSubjectTeacher($subject, $teacher);
                $assigned++;
            }
        }

        $this->command?->info("Subject teachers: {$assigned} subject assignments made for {$year}.");
    }
}
