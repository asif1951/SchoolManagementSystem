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
        $classes = SchoolClass::visibleTo(request()->user(), 'classes.view')->latest()->paginate(10);

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
            'subjectOptions' => \App\Models\Subject::active()->ordered()->get(['id', 'name']),
            'studyGroups' => \App\Models\StudyGroup::active()->ordered()->get(['id', 'name']),
            'shifts' => \App\Models\Shift::active()->ordered()->get(['id', 'name']),
            'mediums' => \App\Models\Medium::active()->ordered()->get(['id', 'name']),
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
            'studyGroup' => ['nullable', 'integer', 'exists:study_groups,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'mediumId' => ['nullable', 'integer', 'exists:mediums,id'],
            'className' => [
                'required',
                'string',
                'max:255',
            ],
            'classTitle' => ['nullable', 'string', 'max:100'],
            'sectionTitle' => ['nullable', 'string', 'max:400'],

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
                'nullable',
                'array',
            ],

            'subjects.*' => [
                'nullable',
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

            // "A, B, C" creates one class per section; existing ones are skipped.
            $result = SchoolClass::createForSections([
                'class_name'     => $validated['className'],
                'class_title'    => filled($validated['classTitle'] ?? null) ? trim($validated['classTitle']) : null,
                'academic_year'  => $validated['academicYear'],
                'study_group_id' => $validated['studyGroup'] ?? null,
                'shift_id'       => $validated['shiftId'] ?? null,
                'medium_id'      => $validated['mediumId'] ?? null,
                'status'         => $validated['status'],
                'max_students'   => $validated['maxStudents'] ?? null,
                'description'    => $validated['description'] ?? null,
            ], $validated['section'] ?? null, $validated['subjects'] ?? [], $teacher, $request->user(), $validated['sectionTitle'] ?? null);

            if (! $result['created']) {
                return redirect()
                    ->back()
                    ->withErrors(['className' => 'That class and section already exist for this academic year' . ($validated['studyGroup'] ?? null ? ' and study group' : '') . '.'])
                    ->withInput();
            }

            return redirect()
                ->route('classes.index')
                ->with('success', SchoolClass::describeCreation($result));

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
        abort_unless(request()->user()->canDo('classes.view', $class), 403);

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
            'studyGroup' => ['nullable', 'integer', 'exists:study_groups,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'mediumId' => ['nullable', 'integer', 'exists:mediums,id'],
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
            ->where('academic_year', $validated['academicYear'])
            ->where('study_group_id', $request->has('studyGroup') ? ($validated['studyGroup'] ?? null) : $class->study_group_id)
            ->where('shift_id', $validated['shiftId'] ?? $class->shift_id)
            ->where('medium_id', $validated['mediumId'] ?? $class->medium_id)
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
                'study_group_id' => $request->has('studyGroup') ? ($validated['studyGroup'] ?? null) : $class->study_group_id,
                'shift_id'       => $validated['shiftId'] ?? $class->shift_id,
                'medium_id'      => $validated['mediumId'] ?? $class->medium_id,
                'status' => $validated['status'],
                'max_students' => $validated['maxStudents'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            $class->syncSubjects($validated['subjects']);
            $class->assignTeacher($teacher, $request->user());

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
        if ($class->students()->exists()) {
            return redirect()
                ->back()
                ->with('error', 'Cannot delete this class: it still has students. Move or remove them first.');
        }

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