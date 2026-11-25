<?php

namespace App\Exceptions;

use App\Models\StudentProfile;
use RuntimeException;

/**
 * Thrown when saving a student would create a second record of a child who is already registered.
 *
 *  kind "brn"    the same Birth Registration Number — never overridable;
 *  kind "likely" a similar name with the same birth date or guardian mobile — a user holding
 *                `students.override_duplicate` may continue by giving a reason.
 */
class DuplicateStudentException extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        public readonly StudentProfile $match,
        public readonly float $score,
        public readonly bool $overridable,
    ) {
        parent::__construct(
            $kind === 'brn'
                ? "This Birth Registration Number already belongs to {$match->name_en} (student {$match->code}). The student already exists."
                : "{$match->name_en} (student {$match->code}) looks like the same child ({$score}% name match with the same birth date or guardian mobile)."
        );
    }

    /** What the screen shows next to the warning: enough to recognise the existing student, no sensitive data. */
    public function summary(): array
    {
        $latest = $this->match->latestEnrollment;

        return [
            'kind'        => $this->kind,
            'overridable' => $this->overridable,
            'score'       => $this->score,
            'student'     => [
                'profile_id' => $this->match->id,
                'code'       => $this->match->code,
                'name'       => $this->match->name_en,
                'dob'        => $this->match->dob?->toDateString(),
                'class'      => $latest?->schoolClass ? $latest->schoolClass->short_label : null,
                'session'    => $latest?->academic_year,
                'trashed'    => $this->match->trashed(),
            ],
        ];
    }
}
