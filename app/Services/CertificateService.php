<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Serial;
use Illuminate\Validation\ValidationException;

/**
 * Issuing certificates. Serial numbers (TC-2026-0001 …) come from a counter and are never reused or edited;
 * a certificate that was wrong is voided and a new one issued. Printing counts every print.
 */
class CertificateService
{
    /**
     * @param  bool  $viaTransfer  set by the transfer workflow, which is the only way a Transfer Certificate is issued
     */
    public function issue(string $type, StudentProfile $profile, ?Student $enrollment, User $by, ?string $remarks = null, ?string $date = null, bool $viaTransfer = false): Certificate
    {
        if (! isset(Certificate::TYPES[$type])) {
            throw ValidationException::withMessages(['type' => 'Choose a certificate type.']);
        }

        if ($type === 'transfer' && ! $viaTransfer) {
            throw ValidationException::withMessages(['type' => 'A Transfer Certificate is issued through the Transfers workflow, after the dues check.']);
        }

        if (! $viaTransfer && ! $by->canDo('students.issue_certificate')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You may not issue certificates.');
        }

        $issued = $date ? \Illuminate\Support\Carbon::parse($date) : now();
        $year = $issued->year;

        $certificate = Certificate::create([
            'serial'        => Serial::format(Certificate::PREFIX[$type], $year, Serial::next("certificate:{$type}:{$year}"), 4),
            'type'          => $type,
            'profile_id'    => $profile->id,
            'enrollment_id' => $enrollment?->id,
            'issue_date'    => $issued->toDateString(),
            'issued_by'     => $by->id,
            'remarks'       => $remarks ?: null,
        ]);

        AuditLog::record($certificate, 'issued', ['serial' => $certificate->serial, 'type' => $type, 'student' => $profile->code], $by->id);

        return $certificate;
    }

    public function void(Certificate $certificate, User $by, string $reason): Certificate
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason for voiding this certificate.']);
        }

        if ($certificate->voided_at) {
            throw ValidationException::withMessages(['reason' => 'This certificate is already void.']);
        }

        $certificate->update(['voided_at' => now(), 'void_reason' => $reason]);
        AuditLog::record($certificate, 'voided', ['serial' => $certificate->serial, 'reason' => $reason], $by->id);

        return $certificate;
    }

    /** count one print (atomically: two people printing at once are both counted) */
    public function printed(Certificate $certificate, User $by): void
    {
        Certificate::whereKey($certificate->id)->update(['print_count' => \Illuminate\Support\Facades\DB::raw('print_count + 1'), 'last_printed_at' => now()]);
        AuditLog::record($certificate, 'printed', ['serial' => $certificate->serial], $by->id);
    }
}
