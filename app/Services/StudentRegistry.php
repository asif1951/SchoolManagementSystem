<?php

namespace App\Services;

use App\Exceptions\DuplicateStudentException;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentChangeRequest;
use App\Models\StudentDuplicateOverride;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Normalize;
use App\Support\PersonId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single door every student passes through — full entry, quick entry, import, online admission,
 * edits and change requests. Putting the rules here (and nowhere else) is what makes them hold:
 *
 *   - a BRN must be 17 digits and can never belong to two students;
 *   - without a BRN, a likely duplicate (similar name + same birth date or guardian mobile) is blocked
 *     unless the user holds `students.override_duplicate` and gives a reason, which is logged;
 *   - protected fields (name, birth date, parents, gender, BRN) change only through approval;
 *   - a roll is unique per class among live students;
 *   - each step is one transaction.
 */
class StudentRegistry
{
    /** personal data that lives on the profile */
    public const PERSONAL = [
        'name_en', 'name_bn', 'brn', 'dob', 'gender', 'religion', 'blood_group', 'father_name', 'mother_name',
        'guardian_name', 'guardian_mobile', 'guardian_relation', 'present_address', 'permanent_address',
        'previous_school', 'admission_date', 'photo',
    ];

    /** a change to one of these can turn a record into a duplicate of another */
    private const IDENTITY = ['name_en', 'dob', 'brn'];

    // ------------------------------------------------------------------
    // admission
    // ------------------------------------------------------------------

    /**
     * Register a new student and enroll them in a class. $data holds the personal fields plus
     * class_id (required) and optionally roll_number, study_group_id, status, admission_date.
     *
     * @param  string  $context  entry | quick | import | online   (recorded as the source of the history)
     *
     * @throws DuplicateStudentException  a BRN match, or a likely duplicate without a valid override
     * @throws ValidationException
     */
    public function admit(array $data, User $by, string $context = 'entry', ?string $overrideReason = null): Student
    {
        $personal = $this->clean($data);
        $match = $this->guardDuplicates($personal, null, $by, $overrideReason);

        return DB::transaction(function () use ($personal, $data, $by, $context, $match, $overrideReason) {
            $profile = StudentProfile::inSource($context, fn () => StudentProfile::create($personal + [
                'code'       => PersonId::next(PersonId::STUDENT),
                'created_by' => $by->id,
            ] + ['admission_date' => now()->toDateString()]), $by);

            if ($match) {
                $this->recordOverride($profile, $match, $overrideReason, $by, $context);
            }

            return $this->enroll($profile, $data);
        });
    }

    /**
     * Add a session's enrollment to an existing child (a new row; earlier ones are never touched).
     *
     * @throws ValidationException
     */
    public function enroll(StudentProfile $profile, array $data): Student
    {
        $class = SchoolClass::find($data['class_id'] ?? null);

        if (! $class) {
            throw ValidationException::withMessages(['class_id' => 'Choose a class and section.']);
        }

        $roll = isset($data['roll_number']) && trim((string) $data['roll_number']) !== '' ? trim((string) $data['roll_number']) : null;

        $this->assertRollFree($class->id, $roll, null);

        if (Student::where('profile_id', $profile->id)->where('academic_year', (int) $class->academic_year)->exists()) {
            throw ValidationException::withMessages(['class_id' => "{$profile->name_en} is already enrolled for {$class->academic_year}."]);
        }

        $row = [
            'profile_id'       => $profile->id,
            'student_id'       => $profile->code,
            'academic_year'    => (int) $class->academic_year,
            'class_id'         => $class->id,
            'study_group_id'   => $data['study_group_id'] ?? $class->study_group_id,
            'roll_number'      => $roll,
            'status'           => $data['status'] ?? 'active',
            'promoted_from_id' => $data['promoted_from_id'] ?? null,
        ];

        // the synchronized copy of the personal data on the enrollment row
        foreach (StudentProfile::ENROLLMENT_COLUMN as $field => $column) {
            $value = $profile->getAttributes()[$field] ?? null;
            $row[$column] = in_array($field, ['dob', 'admission_date'], true) ? Normalize::date($value) : $value;
        }
        $row['admission_date'] = $data['admission_date'] ?? $row['admission_date'] ?? now()->toDateString();

        try {
            return Student::create($row);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'uniq_class_live_roll')) {          // lost a race with another entry
                throw ValidationException::withMessages(['roll_number' => "Roll {$roll} was just taken in this class."]);
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // editing
    // ------------------------------------------------------------------

    /**
     * Edit a child's personal data.
     *
     *   - ordinary fields are saved at once;
     *   - protected fields are saved at once for a holder of `approve_change`; for a holder of
     *     `request_change` they become Change Requests (a reason is required); anyone else is refused.
     *
     * @return array{applied: array<int,string>, requested: array<int,StudentChangeRequest>}
     *
     * @throws AuthorizationException|DuplicateStudentException|ValidationException
     */
    public function update(StudentProfile $profile, array $data, User $by, ?string $changeReason = null, ?string $overrideReason = null, string $source = 'screen'): array
    {
        $incoming = $this->clean($data, partial: true);

        $changes = [];
        foreach ($incoming as $field => $value) {
            if ($this->differs($profile, $field, $value)) {
                $changes[$field] = $value;
            }
        }

        $protected = array_intersect_key($changes, array_flip(StudentProfile::PROTECTED));
        $ordinary  = array_diff_key($changes, $protected);
        $requested = [];                                       // protected changes to turn into requests

        if ($protected && ! $by->canDo('students.approve_change')) {
            if (! $by->canDo('students.request_change')) {
                throw new AuthorizationException('Changing ' . $this->labels(array_keys($protected)) . ' needs approval, and your role may not request changes.');
            }

            if (blank($changeReason)) {
                throw ValidationException::withMessages(['change_reason' => 'Give a reason for changing ' . $this->labels(array_keys($protected)) . '.']);
            }

            $protected = [];                                   // not applied: requested below
            $requested = array_intersect_key($changes, array_flip(StudentProfile::PROTECTED));
        }

        return DB::transaction(function () use ($profile, $ordinary, $protected, $requested, $changeReason, $overrideReason, $by, $source) {
            $apply = $ordinary + $protected;

            if (array_intersect(array_keys($apply), self::IDENTITY)) {
                $merged = $this->merged($profile, $apply);
                $match = $this->guardDuplicates($merged, $profile->id, $by, $overrideReason);

                if ($match) {
                    $this->recordOverride($profile, $match, $overrideReason, $by, 'change');
                }
            }

            if ($apply) {
                StudentProfile::inSource($source, fn () => $profile->update($apply), $by);
            }

            $requests = [];
            foreach ($requested as $field => $value) {
                $requests[] = StudentChangeRequest::create([
                    'profile_id'   => $profile->id,
                    'field'        => $field,
                    'old_value'    => $this->plain($profile->getAttributes()[$field] ?? null),
                    'new_value'    => $this->plain($value),
                    'reason'       => $changeReason,
                    'requested_by' => $by->id,
                ]);
            }

            return ['applied' => array_keys($apply), 'requested' => $requests];
        });
    }

    /**
     * Approve a change request: the new value is applied (after the duplicate check is repeated)
     * and the decision is recorded. Needs `approve_change`.
     */
    public function approve(StudentChangeRequest $request, User $by, ?string $remark = null, ?string $overrideReason = null): StudentChangeRequest
    {
        $this->authorize($by, 'students.approve_change', 'approve change requests');

        return DB::transaction(function () use ($request, $by, $remark, $overrideReason) {
            $request = StudentChangeRequest::lockForUpdate()->findOrFail($request->id);

            if ($request->status !== StudentChangeRequest::PENDING) {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }

            $profile = StudentProfile::findOrFail($request->profile_id);
            $value = $this->clean([$request->field => $request->new_value], partial: true)[$request->field] ?? null;

            if (in_array($request->field, self::IDENTITY, true)) {
                $match = $this->guardDuplicates($this->merged($profile, [$request->field => $value]), $profile->id, $by, $overrideReason);

                if ($match) {
                    $this->recordOverride($profile, $match, $overrideReason, $by, 'change');
                }
            }

            StudentProfile::inSource('approval', fn () => $profile->update([$request->field => $value]), $by);

            $request->update(['status' => StudentChangeRequest::APPROVED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_remark' => $remark]);

            return $request->fresh();
        });
    }

    /** Reject a change request. A remark is required. Needs `approve_change`. */
    public function reject(StudentChangeRequest $request, User $by, string $remark): StudentChangeRequest
    {
        $this->authorize($by, 'students.approve_change', 'reject change requests');

        if (trim($remark) === '') {
            throw ValidationException::withMessages(['remark' => 'Give the reason for rejecting this request.']);
        }

        return DB::transaction(function () use ($request, $by, $remark) {
            $request = StudentChangeRequest::lockForUpdate()->findOrFail($request->id);

            if ($request->status !== StudentChangeRequest::PENDING) {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }

            $request->update(['status' => StudentChangeRequest::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_remark' => $remark]);

            return $request->fresh();
        });
    }

    /**
     * Everything admit() would check, without saving anything: the fields, the class, the roll, and the
     * duplicate rules (with no override reason, so a likely duplicate always stops here). Used by the import
     * preview. Returns the cleaned personal data.
     *
     * @throws ValidationException|DuplicateStudentException
     */
    public function precheck(array $data, User $by): array
    {
        $personal = $this->clean($data);

        $class = SchoolClass::find($data['class_id'] ?? null);

        if (! $class) {
            throw ValidationException::withMessages(['class_id' => 'Choose a class and section.']);
        }

        $roll = isset($data['roll_number']) && trim((string) $data['roll_number']) !== '' ? trim((string) $data['roll_number']) : null;
        $this->assertRollFree($class->id, $roll, null);
        $this->guardDuplicates($personal, null, $by, null);

        return $personal;
    }

    // ------------------------------------------------------------------
    // removal
    // ------------------------------------------------------------------

    /**
     * Soft-delete a student who was entered by mistake. Anyone with marks, attendance, fee payments or a
     * later-session record is part of the school's history: use the status (transferred, dropped …) instead.
     *
     * @return array<string,int>  what blocked the removal (empty when it went through)
     */
    public function remove(StudentProfile $profile, User $by): array
    {
        $blockers = [];

        foreach ($profile->enrollments()->get() as $enrollment) {
            foreach ($enrollment->deletionBlockers() as $what => $n) {
                $blockers[$what] = ($blockers[$what] ?? 0) + $n;
            }
        }

        if ($blockers) {
            return $blockers;
        }

        DB::transaction(function () use ($profile) {
            foreach ($profile->enrollments()->get() as $enrollment) {
                $enrollment->delete();                                // the last one takes the profile with it
            }

            if (! $profile->trashed()) {
                $profile->delete();
            }
        });

        return [];
    }

    // ------------------------------------------------------------------
    // duplicate prevention
    // ------------------------------------------------------------------

    /**
     * The existing student this data would duplicate, if any.
     *
     * With a BRN the BRN alone decides (it is unique at the database level, and soft-deleted students
     * still hold theirs). Without one: a similar name plus the same birth date or guardian mobile.
     *
     * @return array{kind:string, profile:StudentProfile, score:float}|null
     */
    public function findDuplicate(array $personal, ?int $ignoreProfileId = null): ?array
    {
        $brn = Normalize::brn($personal['brn'] ?? null);

        if ($brn !== null) {
            $hit = StudentProfile::withTrashed()->where('brn', $brn)
                ->when($ignoreProfileId, fn ($q) => $q->where('id', '!=', $ignoreProfileId))
                ->first();

            return $hit ? ['kind' => 'brn', 'profile' => $hit, 'score' => 100.0] : null;
        }

        $name   = Normalize::name($personal['name_en'] ?? null);
        $dob    = $personal['dob'] ?? null;
        $mobile = Normalize::mobile($personal['guardian_mobile'] ?? null);

        if ($name === '' || ($dob === null && $mobile === null)) {
            return null;                                      // nothing to compare the name against
        }

        // indexed narrowing first (dob, guardian_mobile_norm), the similarity score second
        $candidates = StudentProfile::query()
            ->when($ignoreProfileId, fn ($q) => $q->where('id', '!=', $ignoreProfileId))
            ->where(function ($q) use ($dob, $mobile) {
                if ($dob !== null) {
                    $q->orWhere('dob', $dob);
                }
                if ($mobile !== null) {
                    $q->orWhere('guardian_mobile_norm', $mobile);
                }
            })
            ->limit(300)
            ->get();

        $best = null;
        $threshold = (float) config('student.duplicate_threshold', 85);

        foreach ($candidates as $c) {
            $score = Normalize::similarity($name, (string) $c->name_normalized);

            if ($score >= $threshold && ($best === null || $score > $best['score'])) {
                $best = ['kind' => 'likely', 'profile' => $c, 'score' => $score];
            }
        }

        return $best;
    }

    /**
     * Throw if the data duplicates someone — unless it is only a "likely" match and the user may
     * override it and gave a reason; in that case the match is returned so the override can be logged.
     *
     * @return array{kind:string, profile:StudentProfile, score:float}|null
     */
    private function guardDuplicates(array $personal, ?int $ignoreProfileId, User $by, ?string $overrideReason): ?array
    {
        $match = $this->findDuplicate($personal, $ignoreProfileId);

        if (! $match) {
            return null;
        }

        $canOverride = $match['kind'] === 'likely' && $by->canDo('students.override_duplicate');

        if (! $canOverride || trim((string) $overrideReason) === '') {
            throw new DuplicateStudentException($match['kind'], $match['profile'], $match['score'], $canOverride);
        }

        return $match;
    }

    private function recordOverride(StudentProfile $profile, array $match, string $reason, User $by, string $context): void
    {
        StudentDuplicateOverride::create([
            'profile_id'         => $profile->id,
            'matched_profile_id' => $match['profile']->id,
            'context'            => $context,
            'reason'             => $reason,
            'score'              => (int) round($match['score']),
            'user_id'            => $by->id,
        ]);

        AuditLog::record($profile, 'duplicate_override', [
            'reason'  => $reason,
            'matched' => $match['profile']->code,       // the code only: never the BRN
            'score'   => $match['score'],
            'context' => $context,
        ], $by->id);
    }

    // ------------------------------------------------------------------
    // input
    // ------------------------------------------------------------------

    /**
     * Keep only known personal fields, trim them, turn blanks into null and dates into Y-m-d.
     *
     * @throws ValidationException
     */
    /** the personal data of a row, cleaned and checked exactly as Student Entry does (no database); bulk paths use it too */
    public function normalizePersonal(array $data): array
    {
        return $this->clean($data);
    }

    private function clean(array $data, bool $partial = false): array
    {
        $out = [];
        $errors = [];

        foreach (self::PERSONAL as $field) {
            if ($partial && ! array_key_exists($field, $data)) {
                continue;
            }

            $v = $data[$field] ?? null;
            $v = is_string($v) ? trim($v) : $v;
            $out[$field] = $v === '' ? null : $v;
        }

        if (! $partial && blank($out['name_en'] ?? null)) {
            $errors['name_en'] = 'The student\'s name is required.';
        }

        if (array_key_exists('name_en', $out) && $partial && blank($out['name_en'])) {
            $errors['name_en'] = 'The student\'s name cannot be empty.';
        }

        foreach (['dob', 'admission_date'] as $date) {
            if (($out[$date] ?? null) !== null) {
                // a real date in an unambiguous format; "next tuesday" and "tomorrow" are not dates of birth
                $parsed = Normalize::parseDate((string) $out[$date]);

                if ($parsed === null) {
                    $errors[$date] = 'This is not a valid date (use day/month/year or year-month-day).';
                    continue;
                }

                $out[$date] = $parsed;

                if ($date === 'dob' && $out[$date] > now()->toDateString()) {
                    $errors['dob'] = 'The date of birth cannot be in the future.';
                }
            }
        }

        if (array_key_exists('brn', $out)) {
            $out['brn'] = Normalize::brn($out['brn']);

            if ($out['brn'] !== null && ! Normalize::isValidBrn($out['brn'])) {
                $errors['brn'] = 'The Birth Registration Number must be exactly 17 digits.';
            }
        }

        if (($out['guardian_mobile'] ?? null) !== null && ! preg_match('/^1[3-9]\d{8}$/', (string) Normalize::mobile($out['guardian_mobile']))) {
            $errors['guardian_mobile'] = 'Enter a valid mobile number (01XXXXXXXXX).';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    private function assertRollFree(int $classId, ?string $roll, ?int $ignoreEnrollmentId): void
    {
        if ($roll === null) {
            return;
        }

        $holder = Student::where('class_id', $classId)->where('roll_number', $roll)
            ->when($ignoreEnrollmentId, fn ($q) => $q->where('id', '!=', $ignoreEnrollmentId))
            ->first(['id', 'student_name']);

        if ($holder) {
            throw ValidationException::withMessages(['roll_number' => "Roll {$roll} is already given to {$holder->student_name} in this class."]);
        }
    }

    // ------------------------------------------------------------------

    private function differs(StudentProfile $profile, string $field, $new): bool
    {
        return $this->plain($profile->getAttributes()[$field] ?? null) !== $this->plain($new);
    }

    private function plain($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // dates compare as plain Y-m-d whatever form they are held in
        return Normalize::date($value);
    }

    /** the profile's current personal data with $changes laid over it */
    private function merged(StudentProfile $profile, array $changes): array
    {
        return array_merge(
            array_intersect_key($profile->getAttributes(), array_flip(self::PERSONAL)),
            $changes
        );
    }

    private function labels(array $fields): string
    {
        $names = [
            'name_en' => 'the name', 'name_bn' => 'the Bangla name', 'dob' => 'the date of birth', 'father_name' => "the father's name",
            'mother_name' => "the mother's name", 'gender' => 'the gender', 'brn' => 'the Birth Registration Number',
        ];

        return implode(', ', array_map(fn ($f) => $names[$f] ?? $f, $fields));
    }

    private function authorize(User $by, string $ability, string $what): void
    {
        if (! $by->canDo($ability)) {
            throw new AuthorizationException("You may not {$what}.");
        }
    }
}
