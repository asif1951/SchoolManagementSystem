<?php

namespace App\Http\Controllers;

use App\Models\ClassSubjectTeacher;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Admin screen: who teaches which subject in which class, for a whole year at once.
 */
class SubjectTeacherController extends Controller
{
    public function index(Request $request)
    {
        $year = (string) $request->input('year', now()->year);

        $years = SchoolClass::query()->select('academic_year')->distinct()->orderByDesc('academic_year')
            ->pluck('academic_year')->map(fn ($y) => (string) $y)->values()->all();

        if (! in_array($year, $years, true)) {
            array_unshift($years, $year);
        }

        $classes = SchoolClass::query()
            ->where('academic_year', $year)
            ->orderBy('class_name')
            ->orderBy('section')
            ->get();

        $assignments = ClassSubjectTeacher::query()
            ->whereIn('class_id', $classes->pluck('id'))
            ->whereNull('ends_on')
            ->get()
            ->groupBy('class_id');

        $payload = $classes->map(function (SchoolClass $c) use ($assignments) {
            $bySubject = ($assignments->get($c->id) ?? collect())->sortBy('id')->groupBy('subject');

            return [
                'id'            => $c->id,
                'class_name'    => $c->class_name,
                'class_label' => $c->class_label,
                'section_label' => $c->section_label,
                'section'       => $c->section,
                'class_teacher' => $c->class_teacher,
                'subjects'      => collect($c->subjects ?? [])->map(fn ($subject) => [
                    'name'        => $subject,
                    'teacher_ids' => ($bySubject->get($subject) ?? collect())->pluck('user_id')->filter()->map(fn ($i) => (int) $i)->values(),
                    'teacher_id'  => $bySubject->get($subject)?->first()?->user_id,
                ])->values(),
            ];
        })->values();

        $teachers = User::query()
            ->where('role', User::ROLE_TEACHER)
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name']);

        // workload: how many class-subjects each teacher has this year
        $load = ClassSubjectTeacher::query()
            ->whereIn('class_id', $classes->pluck('id'))
            ->whereNull('ends_on')
            ->selectRaw('user_id, COUNT(*) AS total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        return Inertia::render('SubjectTeachers', [
            'year'     => $year,
            'years'    => $years,
            'classes'  => $payload,
            'teachers' => $teachers->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'load' => (int) ($load[$t->id] ?? 0)]),
            'flash'    => session()->only(['success', 'error']),
        ]);
    }

    /**
     * Set the teachers of one subject of one class: `user_ids` is the whole list (empty = nobody). The older single
     * `user_id` still works and means "this one teacher only".
     */
    public function assign(Request $request)
    {
        $active = Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', User::ROLE_TEACHER)->where('status', User::STATUS_ACTIVE));

        $validated = $request->validate([
            'class_id'   => ['required', 'integer', 'exists:classes,id'],
            'subject'    => ['required', 'string', 'max:100'],
            'user_id'    => ['nullable', 'integer', $active],
            'user_ids'   => ['nullable', 'array', 'max:10'],
            'user_ids.*' => ['integer', $active],
        ]);

        $class = SchoolClass::findOrFail($validated['class_id']);
        $ids = array_key_exists('user_ids', $validated) ? ($validated['user_ids'] ?? []) : (($validated['user_id'] ?? null) ? [$validated['user_id']] : []);
        $teachers = User::whereIn('id', $ids)->get()->sortBy(fn ($u) => array_search($u->id, $ids))->values();

        try {
            $moved = $class->setSubjectTeachers($validated['subject'], $teachers, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $where = "{$validated['subject']} in {$class->class_label}" . ($class->section ? " ({$class->section_label})" : '');

        $routine = $this->routineNote($moved);

        return back()->with('success', match (true) {
            $teachers->isEmpty() => "{$validated['subject']} now has no assigned teacher.",
            $teachers->count() === 1 => "{$teachers[0]->name} now teaches {$where}.",
            default => $teachers->pluck('name')->implode(' and ') . " now teach {$where}.",
        } . $routine);
    }

    /** what happened to the routine, in plain words: who took the periods, and which are now without a teacher */
    private function routineNote(array $moved): string
    {
        if (! $moved['changed']) {
            return '';
        }

        $parts = [];

        foreach ($moved['moved'] as $name => $n) {
            $parts[] = "{$n} " . ($n === 1 ? 'period is' : 'periods are') . " now taken by {$name}";
        }

        if ($moved['without']) {
            $n = count($moved['without']);
            $parts[] = "{$n} " . ($n === 1 ? 'period has' : 'periods have') . ' no teacher (' . implode(', ', array_slice($moved['without'], 0, 6)) . ') because the other teachers are already teaching then: open the routine to choose someone';
        }

        return $parts ? ' In the routine, ' . implode('; ', $parts) . '.' : '';
    }

    /** Full history of subject teachers for one class (JSON). */
    public function history(SchoolClass $class)
    {
        return response()->json(
            $class->subjectAssignments()->get(['subject', 'teacher_name', 'starts_on', 'ends_on'])
                ->map(fn ($a) => [
                    'subject'      => $a->subject,
                    'teacher_name' => $a->teacher_name,
                    'starts_on'    => $a->starts_on->toDateString(),
                    'ends_on'      => $a->ends_on?->toDateString(),
                ])
        );
    }
}
