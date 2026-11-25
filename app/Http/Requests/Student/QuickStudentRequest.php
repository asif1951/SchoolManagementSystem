<?php

namespace App\Http\Requests\Student;

/**
 * Quick Student Entry: just enough to get the child on the roll — name, gender, guardian mobile,
 * class, section (the class row) and roll. BRN may be given; everything else is completed later.
 * The same duplicate checks apply as in the full form.
 */
class QuickStudentRequest extends StudentFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('students.create') ?? false;
    }

    public function rules(): array
    {
        return $this->duplicateRules() + [
            'name_en'         => ['required', 'string', 'max:255'],
            'gender'          => ['required', 'in:Male,Female,Other'],
            'guardian_mobile' => ['required', 'string', 'max:30'],
            'brn'             => ['nullable', 'string', 'max:40'],
            'dob'             => ['nullable', 'date'],
            'class_id'        => ['required', 'integer', 'exists:classes,id'],
            'roll_number'     => ['nullable', 'string', 'max:50'],
        ];
    }
}
