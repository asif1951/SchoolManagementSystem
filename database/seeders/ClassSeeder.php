<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The classes of one academic year, with a class teacher each:
 *   Class 1–8  → sections A and B
 *   Class 9–10 → sections A and B inside each study group (Science, Commerce, Arts)
 * Each new class receives its curriculum template. Existing classes are kept as they are.
 * The year is config('demo.year'), by default the current year.
 */
class ClassSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([CurriculumSeeder::class, TeacherSeeder::class]);

        $year     = (string) (config('demo.year') ?: now()->year);
        $groups   = StudyGroup::pluck('id', 'name');
        $teachers = User::query()->where('role', User::ROLE_TEACHER)->where('status', User::STATUS_ACTIVE)->orderBy('id')->get();

        $plan = [];
        for ($n = 1; $n <= 8; $n++) {
            foreach (['A', 'B'] as $section) {
                $plan[] = ["Class {$n}", $section, null];
            }
        }
        foreach ([9, 10] as $n) {
            foreach (['Science', 'Commerce', 'Arts'] as $group) {
                foreach (['A', 'B'] as $section) {          // every group has its own sections
                    $plan[] = ["Class {$n}", $section, $groups[$group]];
                }
            }
        }

        // prefer teachers who are not yet a class teacher anywhere
        $busy  = SchoolClass::query()->whereNotNull('class_teacher_id')->pluck('class_teacher_id')->flip();
        $free  = $teachers->reject(fn ($t) => $busy->has($t->id))->values();
        $pool  = $free->isNotEmpty() ? $free : $teachers;
        $taken = 0;
        $made  = 0;

        foreach ($plan as [$name, $section, $groupId]) {
            $class = SchoolClass::firstOrCreate(
                ['class_name' => $name, 'section' => $section, 'academic_year' => $year, 'study_group_id' => $groupId],
                ['status' => 'active', 'max_students' => 40]
            );

            if ($class->wasRecentlyCreated) {
                $made++;
            }

            if ($class->subjectModels->isEmpty()) {
                $class->applyCurriculum();
            }

            if (! $class->class_teacher_id && $pool->isNotEmpty()) {
                $class->assignTeacher($pool[$taken % $pool->count()]);
                $taken++;
            }
        }

        $this->command?->info("Classes: {$made} created for {$year} (" . SchoolClass::where('academic_year', $year)->count() . ' in the year).');
    }
}
