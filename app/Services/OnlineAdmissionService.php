<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\OnlineApplication;
use App\Models\Student;
use App\Models\User;
use App\Support\Normalize;
use App\Support\Serial;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Online admission: the public form stores an application; a user with `students.online_admission` approves
 * it (which creates the student and the enrollment through StudentRegistry, so every duplicate rule applies)
 * or rejects it with a remark. The applicant is never told whether a child is already registered.
 */
class OnlineAdmissionService
{
    public function __construct(private StudentRegistry $registry)
    {
    }

    public function submit(array $data, ?string $ip = null): OnlineApplication
    {
        $year = (int) ($data['academic_year'] ?? now()->year);
        $brn = Normalize::brn($data['brn'] ?? null);

        // the same application sent twice (a double click, a retry) is kept once
        $existing = OnlineApplication::where('status', 'pending')->where('academic_year', (string) $year)
            ->whereRaw('LOWER(name_en) = ?', [mb_strtolower(trim($data['name_en']))])
            ->where('guardian_mobile', $data['guardian_mobile'])
            ->when(($data['dob'] ?? null), fn ($q, $d) => $q->whereDate('dob', $d))
            ->first();

        if ($existing) {
            return $existing;
        }

        return OnlineApplication::create([
            'application_no' => Serial::format('OA', $year, Serial::next("application:{$year}"), 5),
            'academic_year'  => (string) $year,
            'class_name'     => $data['class_name'],
            'name_en'        => trim($data['name_en']),
            'name_bn'        => $data['name_bn'] ?? null,
            'brn'            => $brn,
            'dob'            => $data['dob'] ?? null,
            'gender'         => $data['gender'] ?? null,
            'religion'       => $data['religion'] ?? null,
            'father_name'    => $data['father_name'] ?? null,
            'mother_name'    => $data['mother_name'] ?? null,
            'guardian_name'  => $data['guardian_name'] ?? null,
            'guardian_mobile' => $data['guardian_mobile'],
            'guardian_relation' => $data['guardian_relation'] ?? null,
            'present_address' => $data['present_address'] ?? null,
            'previous_school' => $data['previous_school'] ?? null,
            'ip'             => $ip,
            'status'         => 'pending',
            'submitted_at'   => now(),
        ]);
    }

    /**
     * Approve: the student and the enrollment are created and linked to the application.
     *
     * @throws \App\Exceptions\DuplicateStudentException  the child is already registered (or looks like it)
     */
    public function approve(OnlineApplication $application, User $by, int $classId, ?string $roll = null, ?string $overrideReason = null): Student
    {
        $this->can($by);

        return DB::transaction(function () use ($application, $by, $classId, $roll, $overrideReason) {
            $application = OnlineApplication::lockForUpdate()->findOrFail($application->id);

            if ($application->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This application has already been ' . $application->status . '.']);
            }

            $student = $this->registry->admit([
                'name_en' => $application->name_en, 'name_bn' => $application->name_bn, 'brn' => $application->brn, 'dob' => $application->dob?->toDateString(),
                'gender' => $application->gender, 'religion' => $application->religion, 'father_name' => $application->father_name, 'mother_name' => $application->mother_name,
                'guardian_name' => $application->guardian_name, 'guardian_mobile' => $application->guardian_mobile, 'guardian_relation' => $application->guardian_relation,
                'present_address' => $application->present_address, 'previous_school' => $application->previous_school,
                'class_id' => $classId, 'roll_number' => $roll,
            ], $by, 'online', $overrideReason);

            $application->update([
                'status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'profile_id' => $student->profile_id, 'enrollment_id' => $student->id,
            ]);

            AuditLog::record($application, 'approved', ['application' => $application->application_no, 'student' => $student->student_id], $by->id);

            return $student;
        });
    }

    public function reject(OnlineApplication $application, User $by, string $remark): OnlineApplication
    {
        $this->can($by);

        if (trim($remark) === '') {
            throw ValidationException::withMessages(['remark' => 'A remark is required to reject an application.']);
        }

        return DB::transaction(function () use ($application, $by, $remark) {
            $application = OnlineApplication::lockForUpdate()->findOrFail($application->id);

            if ($application->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This application has already been ' . $application->status . '.']);
            }

            $application->update(['status' => 'rejected', 'decision_remark' => $remark, 'decided_by' => $by->id, 'decided_at' => now()]);
            AuditLog::record($application, 'rejected', ['application' => $application->application_no, 'remark' => $remark], $by->id);

            return $application;
        });
    }

    private function can(User $by): void
    {
        if (! $by->canDo('students.online_admission')) {
            throw new AuthorizationException('You may not decide online applications.');
        }
    }
}
