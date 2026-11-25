<?php

namespace Database\Seeders;

use App\Models\ResultInput;
use App\Models\ResultPolicy;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Realistic marks for every class: a first terminal and a final examination, each subject
 * out of 100, entered only for the subjects a student actually takes. Each student has their
 * own ability and each subject its own difficulty, and a few students fail a subject.
 * A class that already has marks for an examination is left alone.
 */
class MarksSeeder extends Seeder
{
    private const EXAMS = ['first_terminal' => 61, 'final' => 66];   // exam => average mark

    public function run(): void
    {
        $this->call(StudentSeeder::class);

        mt_srand(20260303);

        $year  = (string) (config('demo.year') ?: now()->year);
        $scale = ResultPolicy::forYear($year)->scale()->load('rows');
        $now   = now();
        $rows  = 0;

        foreach (SchoolClass::query()->where('academic_year', $year)->orderBy('id')->get() as $class) {
            foreach (self::EXAMS as $exam => $average) {
                if (ResultInput::where('class_id', $class->id)->where('exam_type', $exam)->exists()) {
                    continue;
                }

                $batch = [];

                // subject difficulty, the same for the whole class
                $difficulty = [];
                foreach ($class->subjects as $subject) {
                    $difficulty[$subject] = $this->normal(0, 6);
                }

                $ability = [];

                foreach ($class->subjects as $subject) {
                    foreach ($class->studentsTaking($subject)->where('students.status', 'active')->pluck('students.id') as $studentId) {
                        $ability[$studentId] ??= $this->normal($average, 11);

                        $mark = $ability[$studentId] + $difficulty[$subject] + $this->normal(0, 7);

                        if (mt_rand(1, 100) <= 3) {                      // an occasional failure
                            $mark = mt_rand(8, 32);
                        }

                        $mark = round(max(3, min(99, $mark)), 1);
                        $g    = $scale->gradeFor($mark);

                        $batch[] = [
                            'student_id'    => $studentId,
                            'class_id'      => $class->id,
                            'subject'       => $subject,
                            'exam_type'     => $exam,
                            'academic_year' => $year,
                            'marks'         => $mark,
                            'total_marks'   => 0,
                            'grade'         => $g['grade'],
                            'gpa'           => $g['point'],
                            'created_at'    => $now,
                            'updated_at'    => $now,
                        ];
                    }
                }

                DB::transaction(function () use ($batch, $class, $exam, $year) {
                    foreach (array_chunk($batch, 1000) as $chunk) {
                        ResultInput::upsert($chunk, ['student_id', 'class_id', 'subject', 'exam_type', 'academic_year'], ['marks', 'grade', 'gpa', 'updated_at']);
                    }

                    DB::update(
                        'UPDATE results r
                         JOIN (SELECT student_id, SUM(marks) AS t FROM results WHERE class_id = ? AND exam_type = ? AND academic_year = ? GROUP BY student_id) x
                           ON x.student_id = r.student_id
                            SET r.total_marks = x.t
                          WHERE r.class_id = ? AND r.exam_type = ? AND r.academic_year = ?',
                        [$class->id, $exam, $year, $class->id, $exam, $year]
                    );
                });

                $rows += count($batch);
            }
        }

        $this->command?->info("Marks: {$rows} subject marks entered for " . implode(' and ', array_keys(self::EXAMS)) . ' examinations.');
    }

    /** a normally distributed random number (Box–Muller), repeatable through mt_srand */
    private function normal(float $mean, float $sd): float
    {
        $u = max(1e-9, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();

        return $mean + $sd * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }
}
