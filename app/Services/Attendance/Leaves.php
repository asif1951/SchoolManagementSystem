<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Leave;
use App\Models\Attendance\Record;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Student leave. An approved leave marks the student "Leave" when attendance of a day inside it is taken; where it was
 * already taken, an "Absent" becomes "Leave" (and goes back if the approval is cancelled). Nothing is written for days
 * that have no attendance.
 */
class Leaves
{
    public function apply(User $user, Student $student, array $d, ?UploadedFile $file = null): Leave
    {
        abort_unless(Access::can($user, 'attendance.leave', (int) $student->class_id) || Access::can($user, 'attendance.leave_approve', (int) $student->class_id), 403);

        if (! in_array($d['type'], Settings::get('leave_types'), true)) {
            $this->fail('leave_type');
        }

        return DB::transaction(function () use ($user, $student, $d, $file) {
            Student::whereKey($student->id)->lockForUpdate()->first();

            if (Leave::where('student_id', $student->id)->whereIn('status', ['pending', 'approved'])->whereDate('starts_on', '<=', $d['ends_on'])->whereDate('ends_on', '>=', $d['starts_on'])->exists()) {
                $this->fail('leave_overlap');
            }

            $leave = Leave::create([
                'student_id' => $student->id, 'class_id' => $student->class_id, 'type' => $d['type'], 'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'],
                'reason' => trim($d['reason']), 'applied_by' => $user->id,
                // the private disk: a medical paper is not a public file
                'attachment' => $file?->store('leave-attachments', 'local'), 'attachment_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 200) : null,
            ]);
            AuditLog::record($leave, 'leave.applied', ['student' => $student->id, 'from' => $d['starts_on'], 'to' => $d['ends_on']], $user->id);

            return $leave;
        });
    }

    public function decide(User $user, Leave $leave, string $status, ?string $remark): Leave
    {
        abort_unless(Access::can($user, 'attendance.leave_approve', (int) $leave->class_id), 403);

        return DB::transaction(function () use ($user, $leave, $status, $remark) {
            $leave = Leave::whereKey($leave->id)->lockForUpdate()->firstOrFail();

            $ok = match ($status) {
                'approved', 'rejected' => $leave->status === 'pending',
                'cancelled' => in_array($leave->status, ['pending', 'approved'], true),
                default => false,
            };

            if (! $ok) {
                $this->fail('leave_decided');
            }

            if ($status === 'rejected' && trim((string) $remark) === '') {
                $this->fail('leave_remark');
            }

            $was = $leave->status;
            $leave->update(['status' => $status, 'remark' => $remark ? trim($remark) : $leave->remark, 'decided_by' => $user->id, 'decided_at' => now()]);
            $changed = 0;

            if ($status === 'approved') {
                $changed = Record::where('student_id', $leave->student_id)->whereDate('date', '>=', $leave->starts_on->toDateString())->whereDate('date', '<=', $leave->ends_on->toDateString())
                    ->where('status', 'absent')->update(['status' => 'leave', 'leave_id' => $leave->id, 'updated_at' => now()]);
            } elseif ($was === 'approved') {
                $changed = Record::where('leave_id', $leave->id)->where('status', 'leave')->update(['status' => 'absent', 'leave_id' => null, 'updated_at' => now()]);
            }

            AuditLog::record($leave, "leave.{$status}", ['remark' => $remark, 'records_changed' => $changed], $user->id);

            return $leave;
        });
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['attendance' => __("attendance.err.{$key}")]);
    }
}
