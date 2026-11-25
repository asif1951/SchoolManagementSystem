<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Daily attendance for every class over the last 40 school days (Friday and Saturday are the
 * weekend). Each student has their own attendance habit, so some are nearly perfect and a few
 * are often absent. A class that already has attendance records is left alone.
 *
 * Needs the classes and students to exist: run it through DemoDataSeeder (or after StudentSeeder).
 */
class AttendanceSeeder extends Seeder
{
    private const SCHOOL_DAYS = 40;

    public function run(): void
    {
        mt_srand(20260404);

        $year = (string) (config('demo.year') ?: now()->year);

        // the last school days, oldest first
        $days = [];
        $day  = Carbon::yesterday();
        while (count($days) < self::SCHOOL_DAYS) {
            if (! in_array($day->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY], true)) {
                array_unshift($days, $day->toDateString());
            }
            $day = $day->copy()->subDay();
        }

        $now   = now();
        $total = 0;

        foreach (SchoolClass::query()->where('academic_year', $year)->orderBy('id')->get() as $class) {
            if (Attendance::where('class_id', $class->id)->exists()) {
                continue;
            }

            $batch = [];

            foreach (Student::query()->where('class_id', $class->id)->where('status', 'active')->pluck('id') as $studentId) {
                $habit = mt_rand(78, 100) / 100;            // chance this student is present on a given day

                foreach ($days as $date) {
                    $roll = mt_rand(1, 1000) / 1000;

                    $status = match (true) {
                        $roll <= $habit             => 'present',
                        $roll <= $habit + 0.04      => 'late',
                        $roll <= $habit + 0.06      => 'leave',
                        default                     => 'absent',
                    };

                    $batch[] = [
                        'student_id'      => $studentId,
                        'class_id'        => $class->id,
                        'attendance_date' => $date,
                        'status'          => $status,
                        'academic_year'   => $year,
                        'marked_by'       => $class->class_teacher_id,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ];
                }
            }

            foreach (array_chunk($batch, 1000) as $chunk) {
                Attendance::upsert($chunk, ['student_id', 'attendance_date'], ['class_id', 'status', 'academic_year', 'marked_by', 'updated_at']);
            }

            $total += count($batch);
        }

        $this->command?->info("Attendance: {$total} records for the last " . self::SCHOOL_DAYS . ' school days.');
    }
}
