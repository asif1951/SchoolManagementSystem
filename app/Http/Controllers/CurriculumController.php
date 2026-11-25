<?php

namespace App\Http\Controllers;

use App\Models\CurriculumSubject;
use App\Models\SchoolClass;
use App\Models\StudyGroup;
use App\Models\Subject;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Admin screen for curriculum templates: for a class level (e.g. "Class 9"), which subjects
 * every group takes, which are extra for a group, and which are optional.
 * A template can then be applied to the real classes of any year.
 */
class CurriculumController extends Controller
{
    public function index(Request $request)
    {
        $levels = SchoolClass::query()->select('class_name')->distinct()->orderBy('class_name')->pluck('class_name')
            ->merge(CurriculumSubject::query()->select('class_name')->distinct()->pluck('class_name'))
            ->unique()->sort()->values();

        $level = (string) $request->input('class_name', $levels->first() ?? '');

        $rows = CurriculumSubject::query()
            ->with('subject:id,name')
            ->where('class_name', $level)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $groups = StudyGroup::active()->ordered()->get(['id', 'name']);

        $section = fn (?int $groupId, string $title) => [
            'group_id' => $groupId,
            'title'    => $title,
            'items'    => $rows->where('study_group_id', $groupId)->map(fn ($r) => [
                'id'          => $r->id,
                'subject_id'  => $r->subject_id,
                'name'        => $r->subject?->name,
                'is_optional' => $r->is_optional,
            ])->values(),
        ];

        $sections = collect([$section(null, 'All groups (common subjects)')])
            ->merge($groups->map(fn ($g) => $section($g->id, $g->name . ' — extra subjects')))
            ->values();

        return Inertia::render('Curriculum', [
            'levels'   => $levels,
            'level'    => $level,
            'sections' => $sections,
            'subjects' => Subject::active()->ordered()->get(['id', 'name']),
            'years'    => SchoolClass::query()->select('academic_year')->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->values(),
            'flash'    => session()->only(['success', 'error']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'class_name'     => ['required', 'string', 'max:100'],
            'study_group_id' => ['nullable', 'integer', 'exists:study_groups,id'],
            'subject_id'     => ['required', 'integer', 'exists:subjects,id'],
            'is_optional'    => ['boolean'],
        ]);

        $group = $data['study_group_id'] ?? null;

        $exists = CurriculumSubject::query()
            ->where('class_name', $data['class_name'])
            ->where('subject_id', $data['subject_id'])
            ->where('study_group_id', $group)
            ->exists();

        if ($exists) {
            return back()->with('error', 'That subject is already in this list.');
        }

        CurriculumSubject::create([
            'class_name'     => $data['class_name'],
            'study_group_id' => $group,
            'subject_id'     => $data['subject_id'],
            'is_optional'    => (bool) ($data['is_optional'] ?? false),
            'sort_order'     => (int) CurriculumSubject::where('class_name', $data['class_name'])->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Subject added to the template.');
    }

    /** Add several subjects to one list of a template in one go. Subjects already in the list are skipped. */
    public function storeMany(Request $request)
    {
        $data = $request->validate([
            'class_name'     => ['required', 'string', 'max:100'],
            'study_group_id' => ['nullable', 'integer', 'exists:study_groups,id'],
            'subject_ids'    => ['required', 'array', 'min:1', 'max:60'],
            'subject_ids.*'  => ['integer', 'exists:subjects,id'],
            'is_optional'    => ['boolean'],
        ]);

        $group    = $data['study_group_id'] ?? null;
        $existing = CurriculumSubject::query()
            ->where('class_name', $data['class_name'])
            ->where('study_group_id', $group)
            ->pluck('subject_id')
            ->all();

        $order = (int) CurriculumSubject::where('class_name', $data['class_name'])->max('sort_order');
        $added = 0;

        foreach (array_unique($data['subject_ids']) as $subjectId) {
            if (in_array((int) $subjectId, $existing, true)) {
                continue;
            }

            CurriculumSubject::create([
                'class_name'     => $data['class_name'],
                'study_group_id' => $group,
                'subject_id'     => $subjectId,
                'is_optional'    => (bool) ($data['is_optional'] ?? false),
                'sort_order'     => ++$order,
            ]);
            $added++;
        }

        return back()->with('success', "{$added} subject(s) added to the template.");
    }

    public function update(Request $request, CurriculumSubject $row)
    {
        $data = $request->validate([
            'is_optional' => ['boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:65000'],
        ]);

        $row->update(array_filter($data, fn ($v) => $v !== null));

        return back();
    }

    public function destroy(CurriculumSubject $row)
    {
        $row->delete();

        return back()->with('success', 'Subject removed from the template.');
    }

    /** Apply the template of a class level to that level's classes in a year. */
    public function apply(Request $request)
    {
        $data = $request->validate([
            'class_name' => ['required', 'string', 'max:100'],
            'year'       => ['required', 'string', 'max:10'],
            'replace'    => ['boolean'],
        ]);

        $classes = SchoolClass::query()->where('class_name', $data['class_name'])->where('academic_year', $data['year'])->get();

        if ($classes->isEmpty()) {
            return back()->with('error', "There are no {$data['class_name']} classes in {$data['year']}.");
        }

        $applied = 0;
        foreach ($classes as $class) {
            $applied += $class->applyCurriculum((bool) ($data['replace'] ?? false)) ? 1 : 0;
        }

        $skipped = $classes->count() - $applied;

        return back()->with('success', "Template applied to {$applied} class(es)" . ($skipped ? " — {$skipped} skipped (already have subjects or the template is empty)." : '.'));
    }
}
