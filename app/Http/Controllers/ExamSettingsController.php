<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamComponentType;
use App\Models\GradingScale;
use App\Models\ResultPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The editable lists behind the exam rules: grading scales and paper-part types.
 */
class ExamSettingsController extends Controller
{
    // ------------------------------------------------------------------
    // grading scales
    // ------------------------------------------------------------------

    public function storeScale(Request $request)
    {
        $data = $this->validatedScale($request);

        DB::transaction(function () use ($data) {
            $scale = GradingScale::create(['name' => $data['name'], 'is_default' => false]);
            $scale->rows()->createMany($data['rows']);
        });

        return back()->with('success', "Grading scale \"{$data['name']}\" created.");
    }

    public function updateScale(Request $request, GradingScale $scale)
    {
        if ($this->inUseByPublishedYear($scale)) {
            return back()->with('error', 'This scale is used by a year whose results are published. Unpublish first.');
        }

        $data = $this->validatedScale($request, $scale);

        DB::transaction(function () use ($scale, $data) {
            $scale->update(['name' => $data['name']]);
            $scale->rows()->delete();
            $scale->rows()->createMany($data['rows']);
        });

        return back()->with('success', "Grading scale \"{$scale->name}\" saved.");
    }

    public function makeDefaultScale(GradingScale $scale)
    {
        DB::transaction(function () use ($scale) {
            GradingScale::query()->update(['is_default' => false]);
            $scale->update(['is_default' => true]);
        });

        return back()->with('success', "\"{$scale->name}\" is now the default scale for new years.");
    }

    public function destroyScale(GradingScale $scale)
    {
        if ($scale->is_default) {
            return back()->with('error', 'The default scale cannot be deleted. Make another scale the default first.');
        }

        if (ResultPolicy::where('grading_scale_id', $scale->id)->exists()) {
            return back()->with('error', 'This scale is used by a year\'s result rules. Choose another scale there first.');
        }

        $scale->delete();

        return back()->with('success', 'Grading scale deleted.');
    }

    // ------------------------------------------------------------------
    // paper-part types (Written / CQ / MCQ / Practical / Viva …)
    // ------------------------------------------------------------------

    public function storeType(Request $request)
    {
        $data = $this->validatedType($request);
        $data['sort_order'] = $data['sort_order'] ?? ((int) ExamComponentType::max('sort_order') + 1);

        ExamComponentType::create($data);

        return back()->with('success', "\"{$data['name']}\" added.");
    }

    public function updateType(Request $request, ExamComponentType $type)
    {
        $type->update($this->validatedType($request, $type));

        return back()->with('success', "\"{$type->name}\" updated.");
    }

    public function destroyType(ExamComponentType $type)
    {
        if (DB::table('exam_papers')->where('component_type_id', $type->id)->exists()) {
            return back()->with('error', "\"{$type->name}\" is used by exam papers. Deactivate it instead.");
        }

        $type->delete();

        return back()->with('success', 'Deleted.');
    }

    // ------------------------------------------------------------------

    private function inUseByPublishedYear(GradingScale $scale): bool
    {
        $years = ResultPolicy::where('grading_scale_id', $scale->id)->pluck('academic_year');

        return $years->isNotEmpty() && Exam::whereIn('academic_year', $years)->where('status', 'published')->exists();
    }

    private function validatedScale(Request $request, ?GradingScale $scale = null): array
    {
        $data = $request->validate([
            'name'                => ['required', 'string', 'max:100', Rule::unique('grading_scales', 'name')->ignore($scale?->id)],
            'rows'                => ['required', 'array', 'min:2', 'max:30'],
            'rows.*.min_percent'  => ['required', 'numeric', 'min:0', 'max:100'],
            'rows.*.grade'        => ['required', 'string', 'max:10'],
            'rows.*.grade_point'  => ['required', 'numeric', 'min:0', 'max:10'],
            'rows.*.is_fail'      => ['boolean'],
        ]);

        $mins = collect($data['rows'])->pluck('min_percent')->map(fn ($m) => (float) $m);

        if ($mins->unique()->count() !== $mins->count()) {
            abort(422, 'Two grades start at the same percentage.');
        }

        if (! $mins->contains(0.0)) {
            abort(422, 'The lowest grade must start at 0%, so every mark gets a grade.');
        }

        if (! collect($data['rows'])->contains(fn ($r) => ! empty($r['is_fail']))) {
            abort(422, 'Mark at least one grade as a failing grade.');
        }

        $data['rows'] = collect($data['rows'])->map(fn ($r) => [
            'min_percent' => $r['min_percent'], 'grade' => $r['grade'],
            'grade_point' => $r['grade_point'], 'is_fail' => (bool) ($r['is_fail'] ?? false),
        ])->sortByDesc('min_percent')->values()->all();

        return $data;
    }

    private function validatedType(Request $request, ?ExamComponentType $type = null): array
    {
        $request->merge(['name' => trim((string) preg_replace('/\s+/u', ' ', (string) $request->input('name')))]);

        return $request->validate([
            'name'       => ['required', 'string', 'max:60', Rule::unique('exam_component_types', 'name')->ignore($type?->id)],
            'code'       => ['nullable', 'string', 'max:20', Rule::unique('exam_component_types', 'code')->ignore($type?->id)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'is_active'  => ['boolean'],
        ]);
    }
}
