<?php

namespace App\Support;

use App\Models\Student;
use App\Models\User;

/**
 * The older Students / Student Management edit forms write straight to the student row. The Student Module keeps a
 * few fields (name, date of birth, gender, father, mother) behind a Change Request unless the user may approve changes
 * themselves. This guard applies the same rule to the old forms, so the rule cannot be bypassed by using them.
 */
class LegacyStudentGuard
{
    /** @param array<string,mixed> $validated the old form's validated input (studentName, dateOfBirth, gender, fatherName, motherName) */
    public static function blocks(User $user, Student $student, array $validated): bool
    {
        if ($user->canDo('students.approve_change')) {
            return false;
        }

        $text = fn ($v) => mb_strtolower(trim((string) $v));
        $date = fn ($v) => $v ? substr(Normalize::parseDate((string) $v) ?? (string) $v, 0, 10) : '';

        $pairs = [
            [$student->student_name, $validated['studentName'] ?? $student->student_name, $text],
            [$student->gender, $validated['gender'] ?? $student->gender, $text],
            [$student->father_name, $validated['fatherName'] ?? $student->father_name, $text],
            [$student->mother_name, $validated['motherName'] ?? $student->mother_name, $text],
            [$student->date_of_birth, array_key_exists('dateOfBirth', $validated) ? $validated['dateOfBirth'] : $student->date_of_birth, $date],
        ];

        foreach ($pairs as [$old, $new, $norm]) {
            if ($norm($old) !== $norm($new)) {
                return true;
            }
        }

        return false;
    }

    public const MESSAGE = 'The name, date of birth, gender and parents\' names are protected. Use Student Module → Edit: the change is sent for approval.';
}
