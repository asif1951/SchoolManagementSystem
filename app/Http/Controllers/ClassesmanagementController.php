<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClassesmanagementController extends Controller
{
    public function index(Request $request)
    {
        $query = SchoolClass::query();

        // ✅ Year filter — always current year on page load
        $selectedYear = (string) $request->input('academic_year', now()->year);

        // ✅ Available years from classes
        $availableYears = SchoolClass::query()
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

        // ✅ Apply year filter
        $query->where('academic_year', $selectedYear);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('class_name', 'like', "%{$search}%");
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Sorting
        $sortField = $request->input('sortField', 'created_at');
        $sortDirection = $request->input('sortDirection', 'desc');
        $allowedSortFields = ['class_name', 'section', 'academic_year', 'class_teacher', 'status', 'created_at'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // ✅ Pagination — 10 per page + preserve query string
        $classes = $query->paginate(10)->withQueryString();

        $teachers = User::where('role', 'teacher')
            ->where('status', 'active')
            ->select('id', 'name', 'email')
            ->get();

        return Inertia::render('classesmanagement', [
            'classes' => $classes,
            'filters' => array_merge(
                $request->only(['search', 'status', 'sortField', 'sortDirection']),
                ['academic_year' => $selectedYear]
            ),
            'availableYears' => $availableYears,
            'selectedYear' => $selectedYear,
            'flash' => session()->only(['success', 'error', 'warning']),
            'teachers' => $teachers,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'className' => 'required|string|max:255',
            'section' => 'nullable|string|max:50',
            'academicYear' => 'required|string|max:10',
            'classTeacher' => 'required|integer|exists:users,id',
            'status' => 'required|in:active,inactive',
            'subjects' => 'nullable|array',
            'subjects.*' => 'nullable|string|max:100',
            'maxStudents' => 'nullable|integer|min:1|max:200',
            'description' => 'nullable|string|max:500',
        ]);

        $exists = SchoolClass::where('class_name', $validated['className'])
            ->where('section', $validated['section'] ?? null)
            ->where('academic_year', $validated['academicYear'])
            ->exists();

        if ($exists) {
            return redirect()
                ->back()
                ->withErrors([
                    'className' => 'A class with this name and section already exists for this academic year.',
                ])
                ->withInput();
        }

        $teacher = User::find($validated['classTeacher']);

        SchoolClass::create([
            'class_name' => $validated['className'],
            'section' => $validated['section'],
            'academic_year' => $validated['academicYear'],
            'class_teacher' => $teacher ? $teacher->name : '',
            'status' => $validated['status'],
            'subjects' => $validated['subjects'] ?? [],
            'max_students' => $validated['maxStudents'],
            'description' => $validated['description'],
        ]);

        return redirect()
            ->route('classesmanagement.index', ['academic_year' => $validated['academicYear']])
            ->with('success', 'Class created successfully! 🎉');
    }

    public function update(Request $request, $id)
    {
        $class = SchoolClass::findOrFail($id);

        $validated = $request->validate([
            'className' => 'required|string|max:255',
            'section' => 'nullable|string|max:50',
            'academicYear' => 'required|string|max:10',
            'classTeacher' => 'required|integer|exists:users,id',
            'status' => 'required|in:active,inactive',
            'subjects' => 'nullable|array',
            'subjects.*' => 'nullable|string|max:100',
            'maxStudents' => 'nullable|integer|min:1|max:200',
            'description' => 'nullable|string|max:500',
        ]);

        $exists = SchoolClass::where('class_name', $validated['className'])
            ->where('section', $validated['section'] ?? null)
            ->where('academic_year', $validated['academicYear'])
            ->where('id', '!=', $id)
            ->exists();

        if ($exists) {
            return redirect()
                ->back()
                ->withErrors([
                    'className' => 'A class with this name and section already exists for this academic year.',
                ])
                ->withInput();
        }

        $teacher = User::find($validated['classTeacher']);

        $class->update([
            'class_name' => $validated['className'],
            'section' => $validated['section'],
            'academic_year' => $validated['academicYear'],
            'class_teacher' => $teacher ? $teacher->name : '',
            'status' => $validated['status'],
            'subjects' => $validated['subjects'] ?? [],
            'max_students' => $validated['maxStudents'],
            'description' => $validated['description'],
        ]);

        return redirect()
            ->route('classesmanagement.index', ['academic_year' => $validated['academicYear']])
            ->with('success', 'Class updated successfully! 🎉');
    }

    public function destroy($id)
    {
        $class = SchoolClass::findOrFail($id);
        $classYear = $class->academic_year;
        $class->delete();

        return redirect()
            ->route('classesmanagement.index', ['academic_year' => $classYear])
            ->with('success', 'Class deleted successfully! 🎉');
    }
}