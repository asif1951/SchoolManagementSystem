<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Change where a student stands: active, suspended, transferred out, dropped out, passed out.
 * The student keeps every record (marks, attendance, fees); only their status, the date and
 * the reason change, and the change is written to the audit log.
 */
class StudentStatusController extends Controller
{
    public function update(Request $request, Student $student)
    {
        abort_unless($request->user()->canDo('students.edit', $student->class_id), 403);

        $data = $request->validate([
            'status'       => ['required', Rule::in(array_keys(Student::STATUSES))],
            'effective_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reason'       => ['nullable', 'string', 'max:255'],
        ]);

        // A transfer is a workflow (dues check, approval, Transfer Certificate): it cannot be set by hand here.
        if ($data['status'] === 'transferred' && $student->status !== 'transferred') {
            return back()->with('error', 'Use Transfers to transfer a student out: the dues are checked and a Transfer Certificate is issued.');
        }

        $becomesActive = $data['status'] === 'active';

        $student->update([
            'status'            => $data['status'],
            'status_changed_on' => $data['effective_on'] ?? now()->toDateString(),
            'status_reason'     => $becomesActive ? null : ($data['reason'] ?? null),
        ]);

        return back()->with('success', "\"{$student->student_name}\" is now " . strtolower($student->status_label) . '.');
    }
}
