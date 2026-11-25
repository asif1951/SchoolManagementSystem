<?php

namespace App\Http\Controllers;

use App\Models\CurriculumSubject;
use App\Models\StudyGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Admin screen for study groups (Science, Commerce, Arts, … — whatever the school defines).
 */
class StudyGroupController extends Controller
{
    public function index()
    {
        $groups = StudyGroup::query()
            ->withCount(['classes', 'students'])
            ->ordered()
            ->get()
            ->map(fn (StudyGroup $g) => [
                'id'             => $g->id,
                'name'           => $g->name,
                'code'           => $g->code,
                'description'    => $g->description,
                'sort_order'     => $g->sort_order,
                'is_active'      => $g->is_active,
                'classes_count'  => $g->classes_count,
                'students_count' => $g->students_count,
            ]);

        return Inertia::render('StudyGroups', [
            'groups' => $groups,
            'flash'  => session()->only(['success', 'error']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['sort_order'] = $data['sort_order'] ?? ((int) StudyGroup::max('sort_order') + 1);

        $group = StudyGroup::create($data);

        return back()->with('success', "Group \"{$group->name}\" added.");
    }

    public function update(Request $request, StudyGroup $group)
    {
        $group->update($this->validated($request, $group));

        return back()->with('success', "Group \"{$group->name}\" updated.");
    }

    public function destroy(StudyGroup $group)
    {
        if ($group->classes()->exists() || $group->students()->exists()) {
            return back()->with('error', "\"{$group->name}\" is used by classes or students. Deactivate it instead.");
        }

        // templates for this group go with it
        CurriculumSubject::where('study_group_id', $group->id)->delete();

        $name = $group->name;
        $group->delete();

        return back()->with('success', "Group \"{$name}\" deleted.");
    }

    private function validated(Request $request, ?StudyGroup $group = null): array
    {
        $request->merge(['name' => trim((string) preg_replace('/\s+/u', ' ', (string) $request->input('name')))]);

        return $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('study_groups', 'name')->ignore($group?->id)],
            'code'        => ['nullable', 'string', 'max:30', Rule::unique('study_groups', 'code')->ignore($group?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:65000'],
            'is_active'   => ['boolean'],
        ]);
    }
}
