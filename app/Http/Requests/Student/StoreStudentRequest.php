<?php

namespace App\Http\Requests\Student;

/** The full Student Entry form. */
class StoreStudentRequest extends StudentFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('students.create') ?? false;
    }

    public function rules(): array
    {
        return $this->personalRules() + $this->duplicateRules() + [
            'class_id'       => ['required', 'integer', 'exists:classes,id'],
            'roll_number'    => ['nullable', 'string', 'max:50'],
            'study_group_id' => ['nullable', 'integer', 'exists:study_groups,id'],
        ];
    }

    public function messages(): array
    {
        return ['name_en.required' => __('student.name_required'), 'class_id.required' => __('student.class_required')];
    }
}
