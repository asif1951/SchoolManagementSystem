<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\OnlineAdmissionService;
use App\Support\Normalize;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The public online admission form (no login). Protected by a rate limit, a hidden honeypot field and strict
 * validation; the applicant is told only that the application was received — never whether the child is already
 * registered.
 */
class PublicApplyController extends Controller
{
    public function show()
    {
        $session = (string) (SchoolClass::max('academic_year') ?: now()->year);

        return Inertia::render('PublicApply', [
            'session'    => $session,
            'classNames' => SchoolClass::where('academic_year', $session)->where('status', 'active')->distinct()->pluck('class_name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'done'       => session('applied'),
        ]);
    }

    public function store(Request $request, OnlineAdmissionService $applications)
    {
        // a hidden field only a program fills in: pretend it worked, store nothing
        if (filled($request->input('website'))) {
            return redirect()->route('apply.show')->with('applied', ['no' => 'OA-RECEIVED']);
        }

        $session = (string) (SchoolClass::max('academic_year') ?: now()->year);

        $data = $request->validate([
            'class_name'        => ['required', 'string', 'max:100', function ($attribute, $value, $fail) use ($session) {
                if (! SchoolClass::where('academic_year', $session)->where('class_name', $value)->where('status', 'active')->exists()) {
                    $fail('Choose a class from the list.');
                }
            }],
            'name_en'           => ['required', 'string', 'max:255'],
            'name_bn'           => ['nullable', 'string', 'max:255'],
            'brn'               => ['nullable', 'string', 'max:40', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && ! Normalize::isValidBrn(Normalize::brn($value))) {
                    $fail('The Birth Registration Number must be 17 digits.');
                }
            }],
            'dob'               => ['required', 'date', 'before:today'],
            'gender'            => ['required', 'in:Male,Female,Other'],
            'religion'          => ['nullable', 'string', 'max:40'],
            'father_name'       => ['required', 'string', 'max:255'],
            'mother_name'       => ['nullable', 'string', 'max:255'],
            'guardian_name'     => ['nullable', 'string', 'max:255'],
            'guardian_mobile'   => ['required', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (! preg_match('/^1[3-9]\d{8}$/', (string) Normalize::mobile($value))) {
                    $fail('Enter a valid mobile number (01XXXXXXXXX).');
                }
            }],
            'guardian_relation' => ['nullable', 'string', 'max:50'],
            'present_address'   => ['nullable', 'string', 'max:1000'],
            'previous_school'   => ['nullable', 'string', 'max:255'],
        ]);

        $application = $applications->submit($data + ['academic_year' => $session], $request->ip());

        return redirect()->route('apply.show')->with('applied', ['no' => $application->application_no]);
    }
}
