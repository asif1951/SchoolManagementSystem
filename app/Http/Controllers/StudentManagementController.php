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
        $query = Student::query();

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

        // Class
        if ($request->filled('class')) {
            $query->where('class', $request->class);
        }

        // Section
        if ($request->filled('section')) {
            $query->where('section', $request->section);
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
            ->select(['id', 'class_name', 'section', 'academic_year'])
            ->where('status', 'active')
            ->where('academic_year', $selectedYear)
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        // Stats — for selected year
        $stats = [
            'total' => Student::where('academic_year', $selectedYear)->count(),
            'active' => Student::where('academic_year', $selectedYear)->where('status', 'active')->count(),
            'inactive' => Student::where('academic_year', $selectedYear)->where('status', 'inactive')->count(),
        ];

        return Inertia::render('studentmanagement', [
            'students' => $students,
            'classes' => $classes,
            'stats' => $stats,
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

        $lastStudent = Student::orderByDesc('id')->first();
        $nextId = $lastStudent ? ($lastStudent->id + 1) : 1;
        $studentId = 'STD-' . str_pad($nextId, 5, '0', STR_PAD_LEFT);

        $photoPath = null;
        if ($request->hasFile('studentPhoto')) {
            $photoPath = $request->file('studentPhoto')->store('students', 'public');
        }

        Student::create([
            'student_id' => $studentId,
            'student_name' => $validated['studentName'],
            'date_of_birth' => $validated['dateOfBirth'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'blood_group' => $validated['bloodGroup'] ?? null,
            'student_photo' => $photoPath,
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
            ->route('studentmanagement', ['academic_year' => $validated['academicYear']])
            ->with('success', 'Student added successfully!');
    }

    /**
     * Update the specified student.
     */
    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
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
            ->route('studentmanagement', ['academic_year' => $validated['academicYear']])
            ->with('success', 'Student "' . $student->student_name . '" updated successfully!');
    }

    /**
     * Delete a student.
     */
    public function destroy(Student $student)
    {
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