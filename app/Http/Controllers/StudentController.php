<?php
namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class StudentController extends Controller
{
    /**
     * Display a listing of students.
     */
    public function index(Request $request)
    {
        $query = Student::query()
            ->with('schoolClass:id,class_name,section')
            ->visibleTo($request->user(), 'students.view');

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('student_name', 'LIKE', "%{$search}%")
                  ->orWhere('student_id', 'LIKE', "%{$search}%");
            });
        }

        // Class filter
        if ($request->filled('class') || $request->filled('section')) {
            $classIds = SchoolClass::query()
                ->when($request->filled('class'), fn ($q) => $q->where('class_name', $request->class))
                ->when($request->filled('section'), fn ($q) => $q->where('section', $request->section))
                ->pluck('id');

            $query->whereIn('class_id', $classIds);
        }


        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Order by latest
        $query->orderByDesc('id');

        // Paginate
        $students = $query->paginate(10)->withQueryString();

        // Get classes for filters
        $currentYear = now()->year;
        $classes = SchoolClass::query()
            ->visibleTo($request->user(), 'students.view')
            ->where('academic_year', $currentYear)
            ->where('status', 'active')
            ->select(['id', 'class_name', 'section', 'study_group_id', 'shift_id', 'medium_id', 'academic_year'])
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        return Inertia::render('Students', [
            'students' => $students,
            'filters' => $request->only(['search', 'class', 'section', 'status']),
            'classes' => $classes,
            'statuses' => \App\Models\Student::STATUSES,
            'flash' => session()->only(['success', 'error']),
        ]);
    }

    /**
     * Show create student page.
     */
    public function create()
{
    $currentYear = now()->year;

    $classes = SchoolClass::query()
        ->where('academic_year', $currentYear)
        ->where('status', 'active')
        ->select([
            'id',
            'class_name',
            'section',
            'study_group_id',
            'academic_year',
        ])
        ->orderBy('class_name')
        ->orderBy('section')
        ->get();

    // Database থেকে next available Student ID
    $generatedStudentId = $this->generateStudentId($currentYear);

    return Inertia::render('Students/Create', [
        'classes' => $classes,
        'statuses' => \App\Models\Student::STATUSES,
        'currentAcademicYear' => $currentYear,
        'generatedStudentId' => $generatedStudentId,
    ]);
}
    /**
     * The 8-digit ID the next student will get (shown on the form; not used up until saved).
     */
    private function generateStudentId(?int $year = null): string
    {
        return Student::nextStudentId($year);
    }

    /**
     * Generate next student ID via AJAX (for Next button).
     */
    public function generateId(Request $request)
    {
        $year = $request->input('year', now()->year);
        $nextId = $this->generateStudentId((int) $year);
        
        return response()->json([
            'generatedStudentId' => $nextId
        ]);
    }

    /**
     * Store a newly created student in storage.
     */
    public function store(Request $request)
    {
        // Validate the request
        $validated = $request->validate([
            'studyGroup' => ['nullable', 'integer', 'exists:study_groups,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'mediumId' => ['nullable', 'integer', 'exists:mediums,id'],
            // Student Information
            'studentName' => ['required', 'string', 'max:255'],
            'studentId' => ['required', 'string', 'regex:/^\d{8,10}$/', 'unique:students,student_id'],
            'dateOfBirth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'bloodGroup' => ['nullable', 'string', 'max:20'],
            'studentPhoto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            
            // Academic Information
            'academicYear' => ['required', 'integer', 'min:2000', 'max:2100'],
            'class' => ['required', 'string', 'max:100'],
            'section' => ['nullable', 'string', 'max:100'],
            'rollNumber' => ['nullable', 'string', 'max:50'],
            'admissionDate' => ['required', 'date'],
            'status' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Models\Student::STATUSES))],
            
            // Parent Information
            'fatherName' => ['nullable', 'string', 'max:255'],
            'fatherPhone' => ['nullable', 'string', 'max:50'],
            'motherName' => ['nullable', 'string', 'max:255'],
            'motherPhone' => ['nullable', 'string', 'max:50'],
            'guardianName' => ['nullable', 'string', 'max:255'],
            'guardianPhone' => ['nullable', 'string', 'max:50'],
            'guardianRelation' => ['nullable', 'string', 'max:100'],
            
            // Address
            'presentAddress' => ['nullable', 'string'],
            'permanentAddress' => ['nullable', 'string'],
        ]);

        // Resolve the exact class row (name + section + year)
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

        if (! $request->user()->canDo('students.create', $class)) {
            abort(403, 'You may not add students to this class.');
        }

        // Upload student photo
        $photoPath = null;
        if ($request->hasFile('studentPhoto')) {
            $photoPath = $request->file('studentPhoto')->store('students', 'public');
        }

        // Create Student
        $student = Student::create([
            // Student Information
            'student_name' => $validated['studentName'],
            'student_id' => trim($validated['studentId']),
            'date_of_birth' => $validated['dateOfBirth'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'blood_group' => $validated['bloodGroup'] ?? null,
            'student_photo' => $photoPath,
            
            // Academic Information
            'academic_year' => $validated['academicYear'],
            'class_id' => $class->id,
            'study_group_id' => $class->study_group_id ?? ($validated['studyGroup'] ?? null),
            'roll_number' => $validated['rollNumber'] ?? null,
            'admission_date' => $validated['admissionDate'],
            'status' => $validated['status'],
            
            // Parent Information
            'father_name' => $validated['fatherName'] ?? null,
            'father_phone' => $validated['fatherPhone'] ?? null,
            'mother_name' => $validated['motherName'] ?? null,
            'mother_phone' => $validated['motherPhone'] ?? null,
            'guardian_name' => $validated['guardianName'] ?? null,
            'guardian_phone' => $validated['guardianPhone'] ?? null,
            'guardian_relation' => $validated['guardianRelation'] ?? null,
            
            // Address
            'present_address' => $validated['presentAddress'] ?? null,
            'permanent_address' => $validated['permanentAddress'] ?? null,
        ]);

        \App\Support\PersonId::claim($student->student_id);

        // Generate next ID for next student
        $nextStudentId = $this->generateStudentId($validated['academicYear']);

        // Redirect with success message and next ID
        return redirect()
            ->route('students.index')
            ->with('success', 'Student "' . $student->student_name . '" created successfully!')
            ->with('generatedStudentId', $nextStudentId);
    }

    /**
     * Show a specific student.
     */
    public function show(Student $student)
    {
        abort_unless(request()->user()->canDo('students.view', $student->class_id), 403);

        return Inertia::render('Students/Show', [
            'student' => $student->load('schoolClass:id,class_name,section'),
        ]);
    }

    /**
     * Show edit form for student.
     */
    public function edit(Student $student)
    {
        abort_unless(request()->user()->canDo('students.edit', $student->class_id), 403);

        $currentYear = now()->year;
        $classes = SchoolClass::query()
            ->where('academic_year', $currentYear)
            ->where('status', 'active')
            ->select(['id', 'class_name', 'section', 'study_group_id', 'shift_id', 'medium_id', 'academic_year'])
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        return Inertia::render('Students/Edit', [
            'student' => $student->load('schoolClass:id,class_name,section'),
            'classes' => $classes,
            'statuses' => \App\Models\Student::STATUSES,
            'currentAcademicYear' => $currentYear,
        ]);
    }

    /**
     * Update student.
     */
    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
            'studyGroup' => ['nullable', 'integer', 'exists:study_groups,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'mediumId' => ['nullable', 'integer', 'exists:mediums,id'],
            'studentName' => ['required', 'string', 'max:255'],
            'studentId' => ['required', 'string', 'max:100', 'unique:students,student_id,' . $student->id],
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

        if (\App\Support\LegacyStudentGuard::blocks($request->user(), $student, $validated)) {
            return back()->withErrors(['studentName' => \App\Support\LegacyStudentGuard::MESSAGE])->withInput();
        }

        if (($validated['status'] ?? null) === 'transferred' && $student->status !== 'transferred') {
            return back()->withErrors(['status' => 'Use Student Module → Transfers to transfer a student out: the dues are checked and a Transfer Certificate is issued.'])->withInput();
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

        // Upload new photo if provided
        if ($request->hasFile('studentPhoto')) {
            // Delete old photo
            if ($student->student_photo) {
                Storage::disk('public')->delete($student->student_photo);
            }
            $photoPath = $request->file('studentPhoto')->store('students', 'public');
            $student->student_photo = $photoPath;
        }

        // Update student
        $student->update([
            'student_name' => $validated['studentName'],
            'student_id' => trim($validated['studentId']),
            'date_of_birth' => $validated['dateOfBirth'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'blood_group' => $validated['bloodGroup'] ?? null,
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
            ->route('students.index')
            ->with('success', 'Student "' . $student->student_name . '" updated successfully!');
    }

    /**
     * Delete student.
     */
    public function destroy(Student $student)
    {
        abort_unless(request()->user()->canDo('students.delete', $student->class_id), 403);

        if ($blockers = $student->deletionBlockers()) {
            return back()->with('error', $this->cannotDeleteMessage($student, $blockers));
        }

        // Delete photo if exists
        if ($student->student_photo) {
            Storage::disk('public')->delete($student->student_photo);
        }

        $studentName = $student->student_name;
        $student->delete();

        return redirect()
            ->route('students.index')
            ->with('success', 'Student "' . $studentName . '" deleted successfully.');
    }

    /** Why a student cannot be deleted, and what to do instead. */
    protected function cannotDeleteMessage(Student $student, array $blockers): string
    {
        $what = collect($blockers)->map(fn ($n, $label) => "{$n} {$label}")->implode(', ');

        return "\"{$student->student_name}\" cannot be deleted: there are {$what} on record. "
            . 'Change the status instead (Transferred out, Dropped out, Passed out or Suspended); the history is kept.';
    }

    /**
     * Public student verification page (QR scan এর জন্য).
     */
    public function verify(string $token)
    {
        $student = Student::query()
            ->with('schoolClass:id,class_name,section')
            ->where('public_token', $token)
            ->firstOrFail();

        // Public page: expose only what is needed to confirm identity.
        return Inertia::render('Verify', [
            'student' => [
                'student_id'     => $student->student_id,
                'student_name'   => $student->student_name,
                'student_photo'  => $student->student_photo,
                'class'          => $student->schoolClass?->class_name,
                'section'        => $student->schoolClass?->section,
                'academic_year'  => $student->academic_year,
                'admission_date' => $student->admission_date,
                'gender'         => $student->gender,
                'status'         => $student->status,
            ],
        ]);
    }
}
