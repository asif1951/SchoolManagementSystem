<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Validation\Rule;

class SchoolClassController extends Controller
{
    /**
     * Display a listing of classes.
     */
    public function index()
    {
        $classes = SchoolClass::latest()->paginate(10);

        // Get only active users whose role is teacher
        $teachers = User::query()
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('classes', [
            'classes' => $classes,
            'teachers' => $teachers,
        ]);
    }

    /**
     * Show the form for creating a new class.
     */
    public function create()
    {
        return redirect()->route('classes.index');
    }

    /**
     * Store a newly created class.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'className' => [
                'required',
                'string',
                'max:255',
            ],

            'section' => [
                'nullable',
                'string',
                'max:50',
            ],

            'academicYear' => [
                'required',
                'string',
                'max:10',
            ],

            'classTeacher' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'status' => [
                'required',
                'in:active,inactive',
            ],

            'subjects' => [
                'required',
                'array',
                'min:1',
            ],

            'subjects.*' => [
                'required',
                'string',
                'max:100',
            ],

            'maxStudents' => [
                'nullable',
                'integer',
                'min:1',
                'max:200',
            ],

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        // Check if class with same name and section already exists
        $exists = SchoolClass::where('class_name', $validated['className'])
            ->where('section', $validated['section'] ?? null)
            ->exists();

        if ($exists) {
            return redirect()
                ->back()
                ->withErrors([
                    'className' => 'A class with this name and section already exists.',
                ])
                ->withInput();
        }

        try {
            $teacher = User::query()
                ->where('id', $validated['classTeacher'])
                ->where('role', 'teacher')
                ->where('status', 'active')
                ->first();

            if (!$teacher) {
                return redirect()
                    ->back()
                    ->withErrors([
                        'classTeacher' => 'Please select a valid active teacher.',
                    ])
                    ->withInput();
            }

            SchoolClass::create([
                'class_name' => $validated['className'],
                'section' => $validated['section'] ?? null,
                'academic_year' => $validated['academicYear'],
                'class_teacher' => $teacher->name,
                'status' => $validated['status'],
                'subjects' => $validated['subjects'],
                'max_students' => $validated['maxStudents'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            return redirect()
                ->route('classes.index')
                ->with('success', 'Class created successfully! 🎉');

        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->with('error', 'Failed to create class. Please try again.')
                ->withInput();
        }
    }

    /**
     * Display the specified class.
     */
    public function show(SchoolClass $class)
    {
        return Inertia::render('show', [
            'class' => $class,
        ]);
    }

    /**
     * Show the form for editing a class.
     */
    public function edit(SchoolClass $class)
    {
        $teachers = User::query()
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('edit', [
            'class' => $class,
            'teachers' => $teachers,
        ]);
    }

    /**
     * Update the specified class.
     */
    public function update(Request $request, SchoolClass $class)
    {
        $validated = $request->validate([
            'className' => [
                'required',
                'string',
                'max:255',
            ],

            'section' => [
                'nullable',
                'string',
                'max:50',
            ],

            'academicYear' => [
                'required',
                'string',
                'max:10',
            ],

            'classTeacher' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'status' => [
                'required',
                'in:active,inactive',
            ],

            'subjects' => [
                'required',
                'array',
                'min:1',
            ],

            'subjects.*' => [
                'required',
                'string',
                'max:100',
            ],

            'maxStudents' => [
                'nullable',
                'integer',
                'min:1',
                'max:200',
            ],

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        // Check if class with same name and section already exists (excluding current)
        $exists = SchoolClass::where('class_name', $validated['className'])
            ->where('section', $validated['section'] ?? null)
            ->where('id', '!=', $class->id)
            ->exists();

        if ($exists) {
            return redirect()
                ->back()
                ->withErrors([
                    'className' => 'A class with this name and section already exists.',
                ])
                ->withInput();
        }

        try {
            $teacher = User::query()
                ->where('id', $validated['classTeacher'])
                ->where('role', 'teacher')
                ->where('status', 'active')
                ->first();

            if (!$teacher) {
                return redirect()
                    ->back()
                    ->withErrors([
                        'classTeacher' => 'Please select a valid active teacher.',
                    ])
                    ->withInput();
            }

            $class->update([
                'class_name' => $validated['className'],
                'section' => $validated['section'] ?? null,
                'academic_year' => $validated['academicYear'],
                'class_teacher' => $teacher->name,
                'status' => $validated['status'],
                'subjects' => $validated['subjects'],
                'max_students' => $validated['maxStudents'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            return redirect()
                ->route('classes.index')
                ->with('success', 'Class updated successfully! 🎉');

        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->with('error', 'Failed to update class. Please try again.')
                ->withInput();
        }
    }

    /**
     * Remove the specified class.
     */
    public function destroy(SchoolClass $class)
    {
        try {
            $class->delete();

            return redirect()
                ->route('classes.index')
                ->with('success', 'Class deleted successfully! 🎉');

        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->with('error', 'Failed to delete class. Please try again.');
        }
    }

    /**
     * Get active teachers as JSON.
     */
    public function getTeachers()
    {
        $teachers = User::query()
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return response()->json($teachers);
    }
}