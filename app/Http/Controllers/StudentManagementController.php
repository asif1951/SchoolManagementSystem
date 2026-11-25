<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class StudentManagementController extends Controller
{
    /**
     * Display a listing of students.
     */
    public function index(Request $request)
    {
        $query = Student::query()
            ->with('schoolClass:id,class_name,section')
            ->visibleTo($request->user(), 'students.view');

        // ✅ STRING year — page load → current year
        $selectedYear = (string) $request->input('academic_year', now()->year);

        // ✅ Available years — string[]
        $availableYears = Student::query()
            ->select('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->map(fn ($y) => (string) $y)
            ->toArray();

        $currentYear = (string) now()->year;
        if (!in_array($currentYear, $availableYears)) {
            array_unshift($availableYears, $currentYear);
        }

        // ✅ Year filter
        $query->where('academic_year', $selectedYear);

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('student_name', 'LIKE', "%{$search}%")
                  ->orWhere('student_id', 'LIKE', "%{$search}%");
            });
        }

        // Class / Section
        if ($request->filled('class') || $request->filled('section')) {
            $classIds = SchoolClass::query()
                ->when($request->filled('class'), fn ($q) => $q->where('class_name', $request->class))
                ->when($request->filled('section'), fn ($q) => $q->where('section', $request->section))
                ->pluck('id');

            $query->whereIn('class_id', $classIds);
        }

        // Status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $query->orderByDesc('id');

        // ✅ PAGINATION — 15 per page + preserve query string
        $students = $query->paginate(15)->withQueryString();

        // Classes for dropdown (selected year only)
        $classes = SchoolClass::query()
            ->visibleTo($request->user(), 'students.view')
            ->select(['id', 'class_name', 'section', 'study_group_id', 'shift_id', 'medium_id', 'academic_year'])
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        // Stats — one grouped query instead of three counts
        $byStatus = Student::visibleTo($request->user(), 'students.view')
            ->where('academic_year', $selectedYear)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $stats = [
            'total'     => (int) $byStatus->sum(),
            'active'    => (int) ($byStatus['active'] ?? 0),
            'inactive'  => (int) $byStatus->sum() - (int) ($byStatus['active'] ?? 0),   // everyone not currently enrolled
            'by_status' => $byStatus,
        ];

        return Inertia::render('studentmanagement', [
            'students' => $students,
            'classes' => $classes,
            'stats' => $stats,
            'statuses' => Student::STATUSES,
            'availableYears' => $availableYears,
            'selectedYear' => $selectedYear,
            'filters' => array_merge(
                $request->only(['search', 'class', 'section', 'status']),
                ['academic_year' => $selectedYear]
            ),
            'flash' => session()->only(['success', 'error']),
        ]);
    }

    /**
     * Store a newly created student.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'studyGroup' => ['nullable', 'integer', 'exists:study_groups,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'mediumId' => ['nullable', 'integer', 'exists:mediums,id'],
            'studentName' => ['required', 'string', 'max:255'],
            'dateOfBirth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'bloodGroup' => ['nullable', 'string', 'max:20'],
            'studentPhoto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'academicYear' => ['required', 'integer', 'min:2000', 'max:2100'],
            'class' => ['required', 'string', 'max:100'],
            'section' => ['nullable', 'string', 'max:100'],
            'rollNumber' => ['nullable', 'string', 'max:50'],
            'admissionDate' => ['required', 'date'],
            'status' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Models\Student::STATUSES))],
            'fatherName' => ['nullable', 'string', 'max:255'],
            'fatherPhone' => ['nullable', 'string', 'max:50'],
            'motherName' => ['nullable', 'string', 'max:255'],
            'motherPhone' => ['nullable', 'string', 'max:50'],
            'guardianName' => ['nullable', 'string', 'max:255'],
            'guardianPhone' => ['nullable', 'string', 'max:50'],
            'guardianRelation' => ['nullable', 'string', 'max:100'],
            'presentAddress' => ['nullable', 'string'],
            'permanentAddress' => ['nullable', 'string'],
        ]);

        // Warn about a likely duplicate before admitting; "Add anyway" resubmits with confirmDuplicate.
        if (! $request->boolean('confirmDuplicate')) {
            $duplicates = \App\Models\Student::possibleDuplicates(
                $validated['studentName'], $validated['dateOfBirth'] ?? null,
                [$validated['fatherPhone'] ?? null, $validated['motherPhone'] ?? null, $validated['guardianPhone'] ?? null]
            );

            if ($duplicates->isNotEmpty()) {
                $list = $duplicates->map(fn ($d) => "{$d->student_name} (ID {$d->student_id}, {$d->academic_year}"
                    . ($d->schoolClass ? ', ' . $d->schoolClass->class_name . ($d->schoolClass->section ? ' ' . $d->schoolClass->section : '') : '') . ')')->implode('; ');

                return back()
                    ->withErrors(['duplicate' => "This student may already be in the system: {$list}. If this is a different child, choose \"Add anyway\"."])
                    ->withInput();
            }
        }

        $class = $this->resolveClass($request, $student ?? null, $validated['class'], $validated['section'] ?? null, $validated['academicYear']);

        if (! $class) {
            return back()
                ->withErrors(['class' => $this->resolveClassError($request, $student ?? null, $validated['class'], $validated['section'] ?? null, $validated['academicYear'])])
                ->withInput();
        }

        $photoPath = null;
        if ($request->hasFile('studentPhoto')) {
            $photoPath = $request->file('studentPhoto')->store('students', 'public');
        }

        $attributes = [
            'student_name' => $validated['studentName'],
            'date_of_birth' => $validated['dateOfBirth'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'blood_group' => $validated['bloodGroup'] ?? null,
            'student_photo' => $photoPath,
            'academic_year' => $validated['academicYear'],
            'class_id' => $class->id,
            'study_group_id' => $class->study_group_id ?? ($validated['studyGroup'] ?? null),
            'roll_number' => $validated['rollNumber'] ?? null,
            'admission_date' => $validated['admissionDate'],
            'status' => $validated['status'],
            'father_name' => $validated['fatherName'] ?? null,
            'father_phone' => $validated['fatherPhone'] ?? null,
            'mother_name' => $validated['motherName'] ?? null,
            'mother_phone' => $validated['motherPhone'] ?? null,
            'guardian_name' => $validated['guardianName'] ?? null,
            'guardian_phone' => $validated['guardianPhone'] ?? null,
            'guardian_relation' => $validated['guardianRelation'] ?? null,
            'present_address' => $validated['presentAddress'] ?? null,
            'permanent_address' => $validated['permanentAddress'] ?? null,
        ];

        // Retry on the (rare) race where two requests pick the same next id.
        for ($attempt = 1; ; $attempt++) {
            try {
                Student::create($attributes + [
                    'student_id' => \App\Support\PersonId::next(\App\Support\PersonId::STUDENT),
                ]);
                break;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }

        return redirect()
            ->route('studentmanagement', ['academic_year' => $validated['academicYear']])
            ->with('success', 'Student added successfully!');
    }

    /**
     * Update the specified student.
     */
    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
            'studyGroup' => ['nullable', 'integer', 'exists:study_groups,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'mediumId' => ['nullable', 'integer', 'exists:mediums,id'],
            'studentName' => ['required', 'string', 'max:255'],
            'dateOfBirth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'bloodGroup' => ['nullable', 'string', 'max:20'],
            'studentPhoto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'academicYear' => ['required', 'integer', 'min:2000', 'max:2100'],
            'class' => ['required', 'string', 'max:100'],
            'section' => ['nullable', 'string', 'max:100'],
            'rollNumber' => ['nullable', 'string', 'max:50'],
            'admissionDate' => ['required', 'date'],
            'status' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Models\Student::STATUSES))],
            'fatherName' => ['nullable', 'string', 'max:255'],
            'fatherPhone' => ['nullable', 'string', 'max:50'],
            'motherName' => ['nullable', 'string', 'max:255'],
            'motherPhone' => ['nullable', 'string', 'max:50'],
            'guardianName' => ['nullable', 'string', 'max:255'],
            'guardianPhone' => ['nullable', 'string', 'max:50'],
            'guardianRelation' => ['nullable', 'string', 'max:100'],
            'presentAddress' => ['nullable', 'string'],
            'permanentAddress' => ['nullable', 'string'],
        ]);

        if (($validated['status'] ?? null) === 'transferred' && $student->status !== 'transferred') {
            return back()->withErrors(['status' => 'Use Student Module → Transfers to transfer a student out: the dues are checked and a Transfer Certificate is issued.'])->withInput();
        }

        if (\App\Support\LegacyStudentGuard::blocks($request->user(), $student, $validated)) {
            return back()->withErrors(['studentName' => \App\Support\LegacyStudentGuard::MESSAGE])->withInput();
        }

        $class = $this->resolveClass($request, $student ?? null, $validated['class'], $validated['section'] ?? null, $validated['academicYear']);

        if (! $class) {
            return back()
                ->withErrors(['class' => $this->resolveClassError($request, $student ?? null, $validated['class'], $validated['section'] ?? null, $validated['academicYear'])])
                ->withInput();
        }

        if (! $request->user()->canDo('students.edit', $student->class_id) || ! $request->user()->canDo('students.edit', $class)) {
            abort(403, 'You may not edit students of this class.');
        }

        $photoPath = $student->student_photo;

        if ($request->hasFile('studentPhoto')) {
            if ($student->student_photo && Storage::disk('public')->exists($student->student_photo)) {
                Storage::disk('public')->delete($student->student_photo);
            }
            $photoPath = $request->file('studentPhoto')->store('students', 'public');
        }

        $student->update([
            'student_name' => $validated['studentName'],
            'date_of_birth' => $validated['dateOfBirth'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'blood_group' => $validated['bloodGroup'] ?? null,
            'student_photo' => $photoPath,
            'academic_year' => $validated['academicYear'],
            'class_id' => $class->id,
            'study_group_id' => $class->study_group_id ?? ($validated['studyGroup'] ?? null),
            'roll_number' => $validated['rollNumber'] ?? null,
            'admission_date' => $validated['admissionDate'],
            'status' => $validated['status'],
            'father_name' => $validated['fatherName'] ?? null,
            'father_phone' => $validated['fatherPhone'] ?? null,
            'mother_name' => $validated['motherName'] ?? null,
            'mother_phone' => $validated['motherPhone'] ?? null,
            'guardian_name' => $validated['guardianName'] ?? null,
            'guardian_phone' => $validated['guardianPhone'] ?? null,
            'guardian_relation' => $validated['guardianRelation'] ?? null,
            'present_address' => $validated['presentAddress'] ?? null,
            'permanent_address' => $validated['permanentAddress'] ?? null,
        ]);

        return redirect()
            ->route('studentmanagement', ['academic_year' => $validated['academicYear']])
            ->with('success', 'Student "' . $student->student_name . '" updated successfully!');
    }

    /**
     * Delete a student.
     */
    /** Why a student cannot be deleted, and what to do instead. */
    protected function cannotDeleteMessage(Student $student, array $blockers): string
    {
        $what = collect($blockers)->map(fn ($n, $label) => "{$n} {$label}")->implode(', ');

        return "\"{$student->student_name}\" cannot be deleted: there are {$what} on record. "
            . 'Change the status instead (Transferred out, Dropped out, Passed out or Suspended); the history is kept.';
    }

    public function destroy(Student $student)
    {
        abort_unless(request()->user()->canDo('students.delete', $student->class_id), 403);

        if ($blockers = $student->deletionBlockers()) {
            return back()->with('error', $this->cannotDeleteMessage($student, $blockers));
        }

        $studentName = $student->student_name;
        $studentYear = $student->academic_year;

        if ($student->student_photo && Storage::disk('public')->exists($student->student_photo)) {
            Storage::disk('public')->delete($student->student_photo);
        }

        $student->delete();

        return redirect()
            ->route('studentmanagement', ['academic_year' => $studentYear])
            ->with('success', 'Student "' . $studentName . '" deleted successfully.');
    }
}