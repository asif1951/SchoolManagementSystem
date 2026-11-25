<?php

namespace App\Http\Controllers\StudentModule;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\StudentSubjects;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Student Subjects: the study group and the optional / 4th subject of each student of a class. */
class StudentSubjectController extends Controller
{
    public function __construct(private StudentSubjects $subjects)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        // no permission for any class at all: the page is not for this user
        abort_if($user->classScope('students.manage_subjects') === [], 403);

        $years = SchoolClass::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->all() ?: [(string) now()->year];
        $session = (string) $request->input('session', $years[0]);

        $classes = SchoolClass::where('academic_year', $session)->orderBy('class_name')->orderBy('section')->get()
            ->filter(fn ($c) => $user->canDo('students.manage_subjects', $c))->values();

        $class = $classes->firstWhere('id', (int) $request->input('class_id'));

        return Inertia::render('StudentModule/StudentSubjects', [
            'years'   => $years,
            'session' => $session,
            'classes' => $classes->map(fn ($c) => ['id' => $c->id, 'label' => trim($c->short_label . ($c->studyGroup ? ' (' . $c->studyGroup->name . ')' : ''))]),
            'classId' => $class?->id,
            'matrix'  => $class ? $this->subjects->matrix($class) : null,
            'flash'   => session()->only(['success', 'error']),
        ]);
    }

    public function update(Request $request, Student $student)
    {
        $d = $request->validate([
            'group_id'   => ['nullable', 'integer'],
            'optional'   => ['nullable', 'array', 'max:10'],
            'optional.*' => ['integer'],
        ]);

        try {
            $this->subjects->assign($student, $d['group_id'] ?? null, $d['optional'] ?? [], $request->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', "{$student->student_name}: subjects saved.");
    }

    public function bulk(Request $request, SchoolClass $class)
    {
        $d = $request->validate([
            'students'    => ['required', 'array', 'min:1', 'max:500'],
            'students.*'  => ['integer'],
            'group_id'    => ['nullable', 'integer'],
            'optional_id' => ['nullable', 'integer'],
        ]);

        abort_if(($d['group_id'] ?? null) === null && ($d['optional_id'] ?? null) === null, 422, 'Choose a group or a subject to apply.');

        try {
            $n = $this->subjects->bulk($class, $d['students'], $d['group_id'] ?? null, $d['optional_id'] ?? null, $request->user());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', "{$n} student(s) updated.");
    }
}
