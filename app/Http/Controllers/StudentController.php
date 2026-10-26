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
        $query = Student::query();

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('student_name', 'LIKE', "%{$search}%")
                  ->orWhere('student_id', 'LIKE', "%{$search}%");
            });
        }

        // Class filter
        if ($request->filled('class')) {
            $query->where('class', $request->class);
        }

        // Section filter
        if ($request->filled('section')) {
            $query->where('section', $request->section);
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
            ->where('academic_year', $currentYear)
            ->where('status', 'active')
            ->select(['id', 'class_name', 'section', 'academic_year'])
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        return Inertia::render('Students', [
            'students' => $students,
            'filters' => $request->only(['search', 'class', 'section', 'status']),
            'classes' => $classes,
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
            'academic_year',
        ])
        ->orderBy('class_name')
        ->orderBy('section')
        ->get();

    // Database থেকে next available Student ID
    $generatedStudentId = $this->generateStudentId($currentYear);

    return Inertia::render('Students/Create', [
        'classes' => $classes,
        'currentAcademicYear' => $currentYear,
        'generatedStudentId' => $generatedStudentId,
    ]);
}
    /**
     * Get the last student ID from database.
     */
    private function getLastStudentId(int $year): string
    {
        $prefix = "STD-{$year}-";

        $lastStudent = Student::query()
            ->where('student_id', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->first();

        if ($lastStudent) {
            return $lastStudent->student_id;
        }

        // যদি কোন student না থাকে তাহলে প্রথম ID
        return $prefix . '00001';
    }

    /**
     * Generate next Student ID with 5 digits.
     * Format: STD-2026-00001, STD-2026-00002, etc.
     */
    private function generateStudentId(int $year): string
    {
        $prefix = "STD-{$year}-";

        $lastStudent = Student::query()
            ->where('student_id', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;

        if ($lastStudent) {
            $lastId = $lastStudent->student_id;
            // Extract number from ID (remove prefix)
            $number = (int) str_replace($prefix, '', $lastId);

            if ($number > 0) {
                $nextNumber = $number + 1;
            }
        }

        // Generate ID with 5 digits padding (00001, 00002, etc.)
        $studentId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        // Extra collision protection
        while (Student::where('student_id', $studentId)->exists()) {
            $nextNumber++;
            $studentId = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        }

        return $studentId;
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
            // Student Information
            'studentName' => ['required', 'string', 'max:255'],
            'studentId' => ['required', 'string', 'max:100', 'unique:students,student_id'],
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
            'status' => ['required', 'string', 'max:50'],
            
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

        // Verify Class exists
        $classExists = SchoolClass::query()
            ->where('class_name', $validated['class'])
            ->where('academic_year', $validated['academicYear'])
            ->where('status', 'active')
            ->exists();

        if (! $classExists) {
            return back()
                ->withErrors(['class' => 'Selected class is not available.'])
                ->withInput();
        }

        // Verify Section exists for this class (if section is provided)
        if (! empty($validated['section'])) {
            $sectionExists = SchoolClass::query()
                ->where('class_name', $validated['class'])
                ->where('section', $validated['section'])
                ->where('academic_year', $validated['academicYear'])
                ->where('status', 'active')
                ->exists();

            if (! $sectionExists) {
                return back()
                    ->withErrors(['section' => 'Selected section is not available for this class.'])
                    ->withInput();
            }
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
            'class' => $validated['class'],
            'section' => $validated['section'] ?? null,
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
        return Inertia::render('Students/Show', [
            'student' => $student,
        ]);
    }

    /**
     * Show edit form for student.
     */
    public function edit(Student $student)
    {
        $currentYear = now()->year;
        $classes = SchoolClass::query()
            ->where('academic_year', $currentYear)
            ->where('status', 'active')
            ->select(['id', 'class_name', 'section', 'academic_year'])
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        return Inertia::render('Students/Edit', [
            'student' => $student,
            'classes' => $classes,
            'currentAcademicYear' => $currentYear,
        ]);
    }

    /**
     * Update student.
     */
    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
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
            'status' => ['required', 'string', 'max:50'],
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
            'class' => $validated['class'],
            'section' => $validated['section'] ?? null,
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

    /**
     * Public student verification page (QR scan এর জন্য).
     */
    public function verify(Student $student)
    {
        return Inertia::render('Verify', [
            'student' => [
                'id'              => $student->id,
                'student_id'      => $student->student_id,
                'student_name'    => $student->student_name,
                'student_photo'   => $student->student_photo,
                'class'           => $student->class,
                'section'         => $student->section,
                'date_of_birth'   => $student->date_of_birth,
                'blood_group'     => $student->blood_group,
                'gender'          => $student->gender,
                'academic_year'   => $student->academic_year,
                'admission_date'  => $student->admission_date,
                'father_name'     => $student->father_name,
                'father_phone'    => $student->father_phone,
                'status'          => $student->status,
            ],
        ]);
    }
}