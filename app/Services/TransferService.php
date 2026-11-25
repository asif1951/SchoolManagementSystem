<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Student;
use App\Models\StudentTransfer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transfer-out with a dues check.
 *
 *   request    (request_transfer)   starts it; the dues are checked at once and the transfer is "cleared" when there are none
 *   recheck                         looks at the dues again (after a payment)
 *   approve    (approve_transfer)   issues the Transfer Certificate and marks the student transferred — blocked while dues
 *                                   exist, unless the approver also holds override_dues and gives a reason
 *   reinstate  (reinstate_student)  the only way back to "active", with a reason
 *
 * Each step is stamped with who and when. Everything the student has (marks, attendance, fees) is kept.
 */
class TransferService
{
    public function __construct(private DuesService $dues, private CertificateService $certificates)
    {
    }

    public function request(Student $enrollment, User $by, ?string $reason = null, ?string $destination = null): StudentTransfer
    {
        $this->can($by, 'students.request_transfer', 'request a transfer');

        if ($enrollment->status !== 'active') {
            throw ValidationException::withMessages(['student' => 'Only a student who is currently active can be transferred out.']);
        }

        return DB::transaction(function () use ($enrollment, $by, $reason, $destination) {
            Student::whereKey($enrollment->id)->lockForUpdate()->first();

            if (StudentTransfer::where('enrollment_id', $enrollment->id)->open()->exists()) {
                throw ValidationException::withMessages(['student' => 'A transfer is already in progress for this student.']);
            }

            $dues = $this->dues->for($enrollment);

            $transfer = StudentTransfer::create([
                'profile_id' => $enrollment->profile_id, 'enrollment_id' => $enrollment->id, 'reason' => $reason, 'destination' => $destination,
                'requested_by' => $by->id, 'dues_total' => $dues['total'], 'dues_detail' => $dues['items'],
                'status' => $dues['total'] <= 0 ? 'cleared' : 'requested', 'cleared_at' => $dues['total'] <= 0 ? now() : null,
            ]);

            AuditLog::record($transfer, 'requested', ['student' => $enrollment->student_id, 'dues' => $dues['total']], $by->id);

            return $transfer;
        });
    }

    /** look at the dues again; a transfer whose dues have been paid becomes "cleared" */
    public function recheck(StudentTransfer $transfer, User $by): StudentTransfer
    {
        $this->open($transfer);

        $dues = $this->dues->for($transfer->enrollment);

        $transfer->update([
            'dues_total' => $dues['total'], 'dues_detail' => $dues['items'],
            'status' => $dues['total'] <= 0 ? 'cleared' : 'requested', 'cleared_at' => $dues['total'] <= 0 ? ($transfer->cleared_at ?? now()) : null,
        ]);

        return $transfer->fresh();
    }

    /**
     * Approve: issue the Transfer Certificate and move the student out.
     *
     * @throws ValidationException  dues exist and there is no valid override
     */
    public function approve(StudentTransfer $transfer, User $by, ?string $overrideReason = null): StudentTransfer
    {
        $this->can($by, 'students.approve_transfer', 'approve transfers');

        return DB::transaction(function () use ($transfer, $by, $overrideReason) {
            $transfer = StudentTransfer::lockForUpdate()->findOrFail($transfer->id);
            $this->open($transfer);

            $enrollment = Student::whereKey($transfer->enrollment_id)->lockForUpdate()->firstOrFail();
            $dues = $this->dues->for($enrollment);                       // checked again at the moment of approval

            $override = null;

            if ($dues['total'] > 0) {
                if (! $by->canDo('students.override_dues')) {
                    throw ValidationException::withMessages(['dues' => 'Dues of ' . number_format($dues['total'], 2) . ' are unpaid. The Transfer Certificate cannot be issued until they are cleared.']);
                }

                if (trim((string) $overrideReason) === '') {
                    throw ValidationException::withMessages(['override_reason' => 'Dues of ' . number_format($dues['total'], 2) . ' are unpaid. To issue the certificate anyway, give the reason.']);
                }

                $override = $overrideReason;
            }

            $certificate = $this->certificates->issue('transfer', $enrollment->profile, $enrollment, $by, $transfer->destination ? "Transferred to {$transfer->destination}" : null, null, viaTransfer: true);

            $enrollment->update(['status' => 'transferred', 'status_changed_on' => now()->toDateString(), 'status_reason' => 'Transferred out']);

            $transfer->update([
                'status' => 'issued', 'dues_total' => $dues['total'], 'dues_detail' => $dues['items'], 'cleared_at' => $transfer->cleared_at ?? now(),
                'override_reason' => $override, 'override_by' => $override ? $by->id : null,
                'approved_by' => $by->id, 'approved_at' => now(), 'certificate_id' => $certificate->id, 'issued_at' => now(),
            ]);

            AuditLog::record($transfer, 'issued', ['student' => $enrollment->student_id, 'certificate' => $certificate->serial, 'dues_overridden' => $override !== null, 'override_reason' => $override], $by->id);

            return $transfer->fresh(['certificate']);
        });
    }

    public function reject(StudentTransfer $transfer, User $by, string $remark): StudentTransfer
    {
        $this->can($by, 'students.approve_transfer', 'reject transfers');

        return $this->close($transfer, $by, $remark, 'rejected');
    }

    /** withdrawn by the person who asked, or by an approver */
    public function cancel(StudentTransfer $transfer, User $by, string $remark): StudentTransfer
    {
        if ($transfer->requested_by !== $by->id && ! $by->canDo('students.approve_transfer')) {
            throw new AuthorizationException('Only the person who requested this transfer, or an approver, can cancel it.');
        }

        return $this->close($transfer, $by, $remark, 'cancelled');
    }

    /** A transferred student comes back only through here: the permission and a reason, both recorded. */
    public function reinstate(Student $enrollment, User $by, string $reason): Student
    {
        $this->can($by, 'students.reinstate_student', 'reinstate students');

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason for reinstating this student.']);
        }

        if ($enrollment->status !== 'transferred') {
            throw ValidationException::withMessages(['student' => 'Only a transferred student can be reinstated.']);
        }

        return DB::transaction(function () use ($enrollment, $by, $reason) {
            Student::reinstating(fn () => $enrollment->update(['status' => 'active', 'status_changed_on' => now()->toDateString(), 'status_reason' => null]));

            StudentTransfer::where('enrollment_id', $enrollment->id)->where('status', 'issued')->whereNull('reinstated_at')->latest('id')->first()
                ?->update(['reinstated_at' => now(), 'reinstated_by' => $by->id, 'reinstate_reason' => $reason]);

            AuditLog::record($enrollment, 'reinstated', ['reason' => $reason, 'student' => $enrollment->student_id], $by->id);

            return $enrollment->fresh();
        });
    }

    // ------------------------------------------------------------------

    private function close(StudentTransfer $transfer, User $by, string $remark, string $status): StudentTransfer
    {
        if (trim($remark) === '') {
            throw ValidationException::withMessages(['remark' => 'A remark is required.']);
        }

        return DB::transaction(function () use ($transfer, $by, $remark, $status) {
            $transfer = StudentTransfer::lockForUpdate()->findOrFail($transfer->id);
            $this->open($transfer);

            $transfer->update(['status' => $status, 'decision_remark' => $remark, 'decided_by' => $by->id, 'decided_at' => now()]);
            AuditLog::record($transfer, $status, ['remark' => $remark], $by->id);

            return $transfer->fresh();
        });
    }

    private function open(StudentTransfer $transfer): void
    {
        if (! in_array($transfer->status, StudentTransfer::OPEN, true)) {
            throw ValidationException::withMessages(['status' => 'This transfer is already ' . $transfer->status . '.']);
        }
    }

    private function can(User $by, string $ability, string $what): void
    {
        if (! $by->canDo($ability)) {
            throw new AuthorizationException("You may not {$what}.");
        }
    }
}
