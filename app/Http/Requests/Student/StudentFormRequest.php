<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape and size of what the student forms send. The rules that depend on other students
 * (duplicates, BRN uniqueness, rolls, protected fields) belong to StudentRegistry, which every
 * entry point shares, so they cannot be skipped by a different screen.
 */
abstract class StudentFormRequest extends FormRequest
{
    protected function personalRules(): array
    {
        return [
            'name_en'           => ['required', 'string', 'max:255'],
            'name_bn'           => ['nullable', 'string', 'max:255'],
            'brn'               => ['nullable', 'string', 'max:40'],
            'dob'               => ['nullable', 'date'],
            'gender'            => ['nullable', 'in:Male,Female,Other'],
            'religion'          => ['nullable', 'string', 'max:40'],
            'blood_group'       => ['nullable', 'string', 'max:10'],
            'father_name'       => ['nullable', 'string', 'max:255'],
            'mother_name'       => ['nullable', 'string', 'max:255'],
            'guardian_name'     => ['nullable', 'string', 'max:255'],
            'guardian_mobile'   => ['nullable', 'string', 'max:30'],
            'guardian_relation' => ['nullable', 'string', 'max:50'],
            'present_address'   => ['nullable', 'string', 'max:1000'],
            'permanent_address' => ['nullable', 'string', 'max:1000'],
            'previous_school'   => ['nullable', 'string', 'max:255'],
            'admission_date'    => ['nullable', 'date'],
        ];
    }

    protected function duplicateRules(): array
    {
        return [
            // "Add anyway" for a likely duplicate: only honoured for a user holding students.override_duplicate
            'override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
