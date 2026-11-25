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
        $query = SchoolClass::query()->visibleTo($request->user(), 'classes.view');

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
            $query->where(fn ($w) => $w->where('class_name', 'like', "%{$search}%")->orWhere('class_title', 'like', "%{$search}%")->orWhere('section_title', 'like', "%{$search}%"));
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Sorting
        $sortField = $request->input('sortField', 'created_at');
        $sortDirection = strtolower((string) $request->input('sortDirection', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortFields = ['class_name', 'section', 'academic_year', 'class_teacher', 'status', 'created_at'];

        if ($sortField === 'class_teacher') {
            $query->leftJoin('users as ct', 'ct.id', '=', 'classes.class_teacher_id')
                ->select('classes.*')
                ->orderBy('ct.name', $sortDirection);
        } elseif (in_array($sortField, $allowedSortFields, true)) {
            $query->orderBy('classes.' . $sortField, $sortDirection);
        } else {
            $query->orderBy('classes.created_at', 'desc');
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
            'subjectOptions' => \App\Models\Subject::active()->ordered()->get(['id', 'name']),
            'studyGroups' => \App\Models\StudyGroup::active()->ordered()->get(['id', 'name']),
            'shifts' => \App\Models\Shift::active()->ordered()->get(['id', 'name']),
            'mediums' => \App\Models\Medium::active()->ordered()->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'studyGroup' => ['nullable', 'integer', 'exists:study_groups,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'mediumId' => ['nullable', 'integer', 'exists:mediums,id'],
            'className' => 'required|string|max:255',
            'classTitle' => 'nullable|string|max:100',
            'section' => 'nullable|string|max:200',
            'sectionTitle' => 'nullable|string|max:400',
            'academicYear' => 'required|string|max:10',
            'classTeacher' => ['required', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'teacher')->where('status', 'active'))],
            'status' => 'required|in:active,inactive',
            'subjects' => 'nullable|array',
            'subjects.*' => 'nullable|string|max:100',
            'maxStudents' => 'nullable|integer|min:1|max:200',
            'description' => 'nullable|string|max:500',
        ]);

        // "A, B, C" creates one class per section; existing ones are skipped.
        $result = SchoolClass::createForSections([
            'class_name'     => $validated['className'],
            'class_title'    => filled($validated['classTitle'] ?? null) ? trim($validated['classTitle']) : null,
            'academic_year'  => $validated['academicYear'],
            'study_group_id' => $validated['studyGroup'] ?? null,
            'shift_id' => $validated['shiftId'] ?? null,
            'medium_id' => $validated['mediumId'] ?? null,
            'status'         => $validated['status'],
            'max_students'   => $validated['maxStudents'] ?? null,
            'description'    => $validated['description'] ?? null,
        ], $validated['section'] ?? null, $validated['subjects'] ?? [], User::find($validated['classTeacher']), $request->user(), $validated['sectionTitle'] ?? null);

        if (! $result['created']) {
            return redirect()
                ->back()
                ->withErrors(['className' => 'That class and section already exist for this academic year' . ($validated['studyGroup'] ?? null ? ' and study group' : '') . '.'])
                ->withInput();
        }

        return redirect()
            ->route('classesmanagement.index', ['academic_year' => $validated['academicYear']])
            ->with('success', SchoolClass::describeCreation($result));
    }

    public function update(Request $request, $id)
    {
        $class = SchoolClass::findOrFail($id);

        $validated = $request->validate([
            'studyGroup' => ['nullable', 'integer', 'exists:study_groups,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'mediumId' => ['nullable', 'integer', 'exists:mediums,id'],
            'className' => 'required|string|max:255',
            'classTitle' => 'nullable|string|max:100',
            'section' => 'nullable|string|max:50',
            'sectionTitle' => 'nullable|string|max:100',
            'academicYear' => 'required|string|max:10',
            'classTeacher' => ['required', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'teacher')->where('status', 'active'))],
            'status' => 'required|in:active,inactive',
            'subjects' => 'nullable|array',
            'subjects.*' => 'nullable|string|max:100',
            'maxStudents' => 'nullable|integer|min:1|max:200',
            'description' => 'nullable|string|max:500',
        ]);

        $exists = SchoolClass::where('class_name', $validated['className'])
            ->where('section', $validated['section'] ?? null)
            ->where('academic_year', $validated['academicYear'])
            ->where('study_group_id', $request->has('studyGroup') ? ($validated['studyGroup'] ?? null) : $class->study_group_id)
            ->where('shift_id', $validated['shiftId'] ?? $class->shift_id)
            ->where('medium_id', $validated['mediumId'] ?? $class->medium_id)
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

        $class->update([
            'class_name' => $validated['className'],
            'class_title' => filled($validated['classTitle'] ?? null) ? trim($validated['classTitle']) : null,
            'section' => $validated['section'] ?? null,
            'section_title' => filled($validated['sectionTitle'] ?? null) ? trim($validated['sectionTitle']) : null,
            'academic_year' => $validated['academicYear'],
            'study_group_id' => $request->has('studyGroup') ? ($validated['studyGroup'] ?? null) : $class->study_group_id,
            'shift_id' => $validated['shiftId'] ?? $class->shift_id,
            'medium_id' => $validated['mediumId'] ?? $class->medium_id,
            'status' => $validated['status'],
            'max_students' => $validated['maxStudents'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        $class->syncSubjects($validated['subjects'] ?? []);

        // No-op when the teacher did not change; otherwise closes the old stint and opens a new one.
        $class->assignTeacher(User::find($validated['classTeacher']), $request->user());

        return redirect()
            ->route('classesmanagement.index', ['academic_year' => $validated['academicYear']])
            ->with('success', 'Class updated successfully! 🎉');
    }

    public function destroy($id)
    {
        $class = SchoolClass::findOrFail($id);
        $classYear = $class->academic_year;

        if ($class->students()->exists()) {
            return redirect()
                ->route('classesmanagement.index', ['academic_year' => $classYear])
                ->with('error', 'Cannot delete this class: it still has students. Move or remove them first.');
        }

        try {
            $class->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            // e.g. attendance records still reference this class
            return redirect()
                ->route('classesmanagement.index', ['academic_year' => $classYear])
                ->with('error', 'Cannot delete this class: it has related records (e.g. attendance).');
        }

        return redirect()
            ->route('classesmanagement.index', ['academic_year' => $classYear])
            ->with('success', 'Class deleted successfully! 🎉');
    }
}