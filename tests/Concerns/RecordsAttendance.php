<?php

namespace Tests\Concerns;

use App\Models\Attendance\Record;
use App\Models\Attendance\Session;
use App\Models\Student;

/** Put attendance straight into the tables, for tests about what is done with it. */
trait RecordsAttendance
{
    protected function recordAttendance(Student $student, string $date, string $status, array $session = []): Record
    {
        $slot = $session['slot_key'] ?? 0;
        $s = Session::firstOrCreate(['class_id' => $student->class_id, 'date' => $date, 'slot_key' => $slot],
            $session + ['academic_year' => (string) $student->schoolClass->academic_year, 'mode' => $slot ? 'period' : 'daily', 'day_type' => 'working', 'submitted_at' => now()]);

        return Record::create(['session_id' => $s->id, 'student_id' => $student->id, 'class_id' => $student->class_id, 'date' => $date, 'slot_key' => $slot, 'status' => $status]);
    }
}
