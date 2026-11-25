<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Student;
use Database\Seeders\Concerns\GeneratesBangladeshiData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk students with Bangladeshi names, parents, phones and addresses. For every class that
 * has NO students yet it admits config('demo.students_per_section') students, in roll order.
 * Students of group classes get the class's study group, and each student picks their
 * optional subject where the class has one. Classes that already have students are skipped.
 */
class StudentSeeder extends Seeder
{
    use GeneratesBangladeshiData;

    public function run(): void
    {
        $this->call(SubjectTeacherSeeder::class);

        mt_srand(20260202);

        $year    = (int) (config('demo.year') ?: now()->year);
        $perClass = max(1, (int) config('demo.students_per_section', 25));

        // each student gets the next permanent 8-digit ID (see App\Support\PersonId)
        $nextId = fn () => \App\Support\PersonId::next(\App\Support\PersonId::STUDENT, $year);

        $total = 0;

        foreach (SchoolClass::query()->where('academic_year', (string) $year)->orderBy('id')->get() as $class) {
            if (Student::where('class_id', $class->id)->exists()) {
                continue;
            }

            $level = (int) preg_replace('/\D+/', '', $class->class_name);

            // optional subjects this class offers (limited to its group, if it has one)
            $optional = collect($class->subjectPlan())
                ->filter(fn ($p) => $p['is_optional'] && ($p['group_ids'] === [] || in_array($class->study_group_id, $p['group_ids'], true)))
                ->pluck('subject_id')
                ->all();

            $choices = [];

            for ($roll = 1; $roll <= $perClass; $roll++) {
                $gender  = $this->chance(50) ? 'male' : 'female';
                $family  = $this->pick($this->familyNames);
                $child   = $this->personName($gender, $family);
                $father  = 'Md. ' . $this->pick($this->maleNames) . ' ' . $family;
                $mother  = $this->pick($this->femaleNames) . ' ' . $this->pick($this->femaleLast);
                $address = $this->address();

                $age = $level + 5 + mt_rand(0, 1);
                $dob = Carbon::create($year - $age, mt_rand(1, 12), mt_rand(1, 28))->toDateString();

                $student = Student::create([
                    'student_name'      => $child['full'],
                    'student_id'        => $nextId(),
                    'date_of_birth'     => $dob,
                    'gender'            => $gender,
                    'blood_group'       => $this->pick($this->bloodGroups),
                    'academic_year'     => $year,
                    'class_id'          => $class->id,
                    'study_group_id'    => $class->study_group_id,
                    'roll_number'       => (string) $roll,
                    'admission_date'    => Carbon::create($year, 1, mt_rand(2, 20))->toDateString(),
                    'status'            => 'active',
                    'father_name'       => $father,
                    'father_phone'      => $this->phone(),
                    'mother_name'       => $mother,
                    'mother_phone'      => $this->chance(60) ? $this->phone() : null,
                    'guardian_name'     => $father,
                    'guardian_phone'    => $this->phone(),
                    'guardian_relation' => 'Father',
                    'present_address'   => $address,
                    'permanent_address' => $this->chance(70) ? $address : $this->address(),
                ]);

                // the student's own optional subject: one per student in Class 6–8, most students in Class 9–10
                if ($optional && ($level <= 8 || $this->chance(85))) {
                    $choices[] = [
                        'student_id' => $student->id,
                        'subject_id' => $this->pick($optional),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }

                $total++;
            }

            if ($choices) {
                DB::table('student_optional_subjects')->insert($choices);
            }
        }

        $this->command?->info("Students: {$total} admitted for {$year} (" . Student::where('academic_year', $year)->count() . ' in the year).');
    }
}
