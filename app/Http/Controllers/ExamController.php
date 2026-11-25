<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamComponentType;
use App\Models\GradingScale;
use App\Models\ResultPolicy;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The exam controller's home: the exams of a year with their weights, and the year's result rules.
 */
class ExamController extends Controller
{
    public function index(Request $request)
    {
        $year = (string) $request->input('year', now()->year);

        $years = Exam::query()->select('academic_year')->distinct()->pluck('academic_year')
            ->merge(SchoolClass::query()->select('academic_year')->distinct()->pluck('academic_year'))
            ->push((string) now()->year)
            ->map(fn ($y) => (string) $y)->unique()->sortDesc()->values();

        $exams = Exam::query()->forYear($year)->withCount('papers')->ordered()->get()
            ->map(fn (Exam $e) => [
                'id'           => $e->id,
                'name'         => $e->name,
                'weight'       => (float) $e->weight,
                'sort_order'   => $e->sort_order,
                'status'       => $e->status,
                'starts_on'    => $e->starts_on?->toDateString(),
                'ends_on'      => $e->ends_on?->toDateString(),
                'papers_count' => $e->papers_count,
            ]);

        return Inertia::render('Exams', [
            'year'        => $year,
            'years'       => $years,
            'exams'       => $exams,
            'weightTotal' => Exam::weightTotal($year),
            'locked'      => Exam::query()->forYear($year)->where('status', 'published')->exists(),
            'policy'      => ResultPolicy::forYear($year)->only([
                'academic_year', 'calculation_mode', 'pass_rule_scope', 'require_component_pass',
                'grading_scale_id', 'optional_rule', 'optional_threshold', 'attendance_marks',
            ]),
            'scales'      => GradingScale::with('rows')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'is_default' => $s->is_default,
                    'rows' => $s->rows->map(fn ($r) => [
                        'min_percent' => (float) $r->min_percent, 'grade' => $r->grade,
                        'grade_point' => (float) $r->grade_point, 'is_fail' => $r->is_fail,
                    ])->values()]),
            'types'       => ExamComponentType::ordered()->get(['id', 'name', 'code', 'sort_order', 'is_active']),
            'flash'       => session()->only(['success', 'error']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        if (Exam::weightTotal($data['academic_year']) + $data['weight'] > 100.0001) {
            return back()->withErrors(['weight' => 'The weights of a year\'s exams cannot add up to more than 100%.']);
        }

        $data['sort_order'] = $data['sort_order'] ?? ((int) Exam::forYear($data['academic_year'])->max('sort_order') + 1);

        $exam = Exam::create($data + ['status' => 'draft']);

        return back()->with('success', "Exam \"{$exam->name}\" created.");
    }

    public function update(Request $request, Exam $exam)
    {
        if ($exam->status === 'published') {
            return back()->with('error', 'A published exam cannot be edited. Unpublish it first.');
        }

        $data = $this->validated($request, $exam);

        $others = Exam::weightTotal($data['academic_year']) - ($exam->academic_year === $data['academic_year'] ? (float) $exam->weight : 0);

        if ($others + $data['weight'] > 100.0001) {
            return back()->withErrors(['weight' => 'The weights of a year\'s exams cannot add up to more than 100%.']);
        }

        $exam->update($data);

        return back()->with('success', "Exam \"{$exam->name}\" updated.");
    }

    public function destroy(Exam $exam)
    {
        if ($exam->status !== 'draft') {
            return back()->with('error', 'Only a draft exam can be deleted.');
        }

        $name = $exam->name;
        $exam->delete();   // its papers go with it

        return back()->with('success', "Exam \"{$name}\" deleted.");
    }

    /**
     * draft → open → closed → published (and back one step).
     * Publishing needs exams.publish, a year whose weights total 100%, and at least one paper.
     */
    public function setStatus(Request $request, Exam $exam)
    {
        $to = $request->validate(['status' => ['required', Rule::in(Exam::STATUSES)]])['status'];

        $allowed = [
            'draft'     => ['open'],
            'open'      => ['closed', 'draft'],
            'closed'    => ['open', 'published'],
            'published' => ['closed'],
        ];

        if (! in_array($to, $allowed[$exam->status], true)) {
            return back()->with('error', "An exam cannot go from \"{$exam->status}\" to \"{$to}\".");
        }

        if (($to === 'published' || $exam->status === 'published') && ! $request->user()->hasAbility('exams.publish')) {
            abort(403, 'You may not publish or unpublish exams.');
        }

        if ($to === 'published') {
            if (abs(Exam::weightTotal($exam->academic_year) - 100) > 0.001) {
                return back()->with('error', 'Before publishing, the exam weights of ' . $exam->academic_year . ' must add up to exactly 100%.');
            }
            if (! $exam->papers()->exists()) {
                return back()->with('error', 'This exam has no papers set up yet.');
            }
        }

        $exam->update(['status' => $to]);

        return back()->with('success', "\"{$exam->name}\" is now {$to}.");
    }

    /** The year's result rules. Frozen once any exam of the year is published. */
    public function savePolicy(Request $request)
    {
        $data = $request->validate([
            'academic_year'          => ['required', 'string', 'max:10'],
            'calculation_mode'       => ['required', Rule::in([ResultPolicy::MODE_PER_SUBJECT, ResultPolicy::MODE_OVERALL_PERCENTAGE])],
            'pass_rule_scope'        => ['required', Rule::in([ResultPolicy::SCOPE_EACH_EXAM, ResultPolicy::SCOPE_FINAL_ONLY])],
            'require_component_pass' => ['required', 'boolean'],
            'grading_scale_id'       => ['required', 'integer', 'exists:grading_scales,id'],
            'optional_rule'          => ['required', Rule::in([ResultPolicy::OPTIONAL_NONE, ResultPolicy::OPTIONAL_ABOVE])],
            'optional_threshold'     => ['required', 'numeric', 'min:0', 'max:5'],
            'attendance_marks'       => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $data['attendance_marks'] = $data['attendance_marks'] ?? 0;

        if (Exam::forYear($data['academic_year'])->where('status', 'published')->exists()) {
            return back()->with('error', 'Results of this year are published, so the result rules are locked. Unpublish first.');
        }

        ResultPolicy::forYear($data['academic_year'])->update($data);

        return back()->with('success', 'Result rules saved for ' . $data['academic_year'] . '.');
    }

    private function validated(Request $request, ?Exam $exam = null): array
    {
        $request->merge(['name' => trim((string) preg_replace('/\s+/u', ' ', (string) $request->input('name')))]);

        return $request->validate([
            'academic_year' => ['required', 'string', 'max:10'],
            'name'          => ['required', 'string', 'max:100', Rule::unique('exams', 'name')
                ->where('academic_year', $request->input('academic_year'))->ignore($exam?->id)],
            'weight'        => ['required', 'numeric', 'min:0', 'max:100'],
            'sort_order'    => ['nullable', 'integer', 'min:0', 'max:65000'],
            'starts_on'     => ['nullable', 'date'],
            'ends_on'       => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
    }
}
