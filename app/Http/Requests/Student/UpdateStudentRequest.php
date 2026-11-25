<?php

namespace App\Http\Requests\Student;

/** Editing a student's personal data. Protected fields are handled by the registry (direct edit, or a change request). */
class UpdateStudentRequest extends StudentFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('students.edit') ?? false;
    }

    public function rules(): array
    {
        return $this->personalRules() + $this->duplicateRules() + [
            // a person who may only request changes to protected fields must say why
            'change_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
