<?php

namespace Database\Seeders;

use App\Models\Attendance\Record;
use App\Models\Attendance\Session;
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
            if (Session::where('class_id', $class->id)->exists()) {
                continue;
            }

            $habits = [];

            foreach (Student::query()->where('class_id', $class->id)->where('status', 'active')->pluck('id') as $studentId) {
                $habits[$studentId] = mt_rand(78, 100) / 100;          // chance this student is present on a given day
            }

            if (! $habits) {
                continue;
            }

            foreach ($days as $date) {
                $session = Session::create([
                    'class_id' => $class->id, 'academic_year' => $year, 'date' => $date, 'mode' => 'daily', 'slot_key' => 0,
                    'taken_by' => $class->class_teacher_id, 'submitted_at' => $date . ' 10:00:00',
                ]);
                $batch = [];

                foreach ($habits as $studentId => $habit) {
                    $roll = mt_rand(1, 1000) / 1000;

                    $batch[] = [
                        'session_id' => $session->id, 'student_id' => $studentId, 'class_id' => $class->id, 'date' => $date, 'slot_key' => 0,
                        'status' => match (true) {
                            $roll <= $habit        => 'present',
                            $roll <= $habit + 0.04 => 'late',
                            $roll <= $habit + 0.06 => 'leave',
                            default                => 'absent',
                        },
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($batch, 1000) as $chunk) {
                    Record::insert($chunk);
                }

                $total += count($batch);
            }
        }

        $this->command?->info("Attendance: {$total} records for the last " . self::SCHOOL_DAYS . ' school days.');
    }
}
