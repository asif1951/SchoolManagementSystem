<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamComponentType;
use App\Models\ExamPaper;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Per exam: for each class and each of its subjects, which parts exist (Written, CQ, MCQ,
 * Practical …) and their full / pass marks. A subject with only an MCQ part simply has one part.
 * Bulk tools copy a setup to other classes, fill a whole class, or copy from another exam.
 */
class ExamSetupController extends Controller
{
    public function show(Request $request, Exam $exam)
    {
        $classes = SchoolClass::query()
            ->where('academic_year', $exam->academic_year)
            ->orderBy('class_name')->orderBy('section')
            ->get();

        $papersByClass = ExamPaper::query()->where('exam_id', $exam->id)
            ->selectRaw('class_id, COUNT(DISTINCT subject_id) AS subjects')->groupBy('class_id')->pluck('subjects', 'class_id');

        $classId = (int) $request->input('class_id', 0);
        $class   = $classes->firstWhere('id', $classId);

        $subjects = [];
        if ($class) {
            $papers = ExamPaper::query()->where('exam_id', $exam->id)->where('class_id', $class->id)
                ->orderBy('sort_order')->orderBy('id')->get()->groupBy('subject_id');

            $subjects = collect($class->subjectPlan())->map(fn ($p) => [
                'subject_id'  => $p['subject_id'],
                'name'        => $p['name'],
                'is_optional' => $p['is_optional'],
                'group_ids'   => $p['group_ids'],
                'papers'      => ($papers->get($p['subject_id']) ?? collect())->map(fn ($x) => [
                    'component_type_id' => $x->component_type_id,
                    'full_marks'        => (float) $x->full_marks,
                    'pass_marks'        => (float) $x->pass_marks,
                ])->values(),
            ])->values();
        }

        return Inertia::render('ExamSetup', [
            'exam'     => ['id' => $exam->id, 'name' => $exam->name, 'academic_year' => $exam->academic_year, 'status' => $exam->status],
            'classes'  => $classes->map(fn ($c) => [
                'id'              => $c->id,
                'label'           => $c->full_label . ($c->group_name ? ' (' . $c->group_name . ')' : '')
                    . (($classes->pluck('shift_id')->unique()->count() > 1 || $classes->pluck('medium_id')->unique()->count() > 1) ? ' · ' . $c->shift_medium_label : ''),
                'class_name'      => $c->class_name,
                'subjects_set_up' => (int) ($papersByClass[$c->id] ?? 0),
                'subjects_total'  => count($c->subjects),
            ])->values(),
            'classId'  => $class?->id,
            'subjects' => $subjects,
            'types'    => ExamComponentType::active()->ordered()->get(['id', 'name']),
            'otherExams' => Exam::forYear($exam->academic_year)->where('id', '!=', $exam->id)->ordered()->get(['id', 'name']),
            'flash'    => session()->only(['success', 'error']),
        ]);
    }

    /** Replace the parts of ONE subject in ONE class (no parts = the subject is not examined). */
    public function saveSubject(Request $request, Exam $exam)
    {
        if ($blocked = $this->locked($exam)) {
            return $blocked;
        }

        $data = $request->validate([
            'class_id'                  => ['required', 'integer', 'exists:classes,id'],
            'subject_id'                => ['required', 'integer', 'exists:subjects,id'],
            'papers'                    => ['array', 'max:10'],
            'papers.*.component_type_id' => ['required', 'integer', 'exists:exam_component_types,id'],
            'papers.*.full_marks'       => ['required', 'numeric', 'gt:0', 'max:1000'],
            'papers.*.pass_marks'       => ['nullable', 'numeric', 'min:0'],
        ]);

        $class = SchoolClass::findOrFail($data['class_id']);

        if ($error = $this->checkClassAndSubject($exam, $class, (int) $data['subject_id'])) {
            return back()->with('error', $error);
        }

        $papers = collect($data['papers'] ?? []);

        if ($papers->pluck('component_type_id')->duplicates()->isNotEmpty()) {
            return back()->with('error', 'The same part (e.g. CQ) is listed twice for this subject.');
        }

        foreach ($papers as $p) {
            if (isset($p['pass_marks']) && (float) $p['pass_marks'] > (float) $p['full_marks']) {
                return back()->with('error', 'Pass marks cannot be more than full marks.');
            }
        }

        $this->replacePapers($exam, $class->id, (int) $data['subject_id'], $papers->all());

        return back()->with('success', 'Saved.');
    }

    /** Copy ONE class's setup to other classes (only for subjects they also offer). */
    public function applyToClasses(Request $request, Exam $exam)
    {
        if ($blocked = $this->locked($exam)) {
            return $blocked;
        }

        $data = $request->validate([
            'class_id'    => ['required', 'integer', 'exists:classes,id'],
            'target_ids'  => ['required', 'array', 'min:1', 'max:200'],
            'target_ids.*' => ['integer', 'exists:classes,id'],
        ]);

        $source = ExamPaper::query()->where('exam_id', $exam->id)->where('class_id', $data['class_id'])->get()->groupBy('subject_id');

        if ($source->isEmpty()) {
            return back()->with('error', 'Set up this class first — there is nothing to copy yet.');
        }

        $copied = 0;

        DB::transaction(function () use ($exam, $data, $source, &$copied) {
            foreach (SchoolClass::whereIn('id', $data['target_ids'])->where('academic_year', $exam->academic_year)->where('id', '!=', $data['class_id'])->get() as $target) {
                $offered = collect($target->subjectPlan())->pluck('subject_id')->all();

                foreach ($source as $subjectId => $papers) {
                    if (in_array($subjectId, $offered, true)) {
                        $this->replacePapers($exam, $target->id, (int) $subjectId, $papers->map(fn ($p) => [
                            'component_type_id' => $p->component_type_id, 'full_marks' => $p->full_marks, 'pass_marks' => $p->pass_marks,
                        ])->all());
                        $copied++;
                    }
                }
            }
        });

        return back()->with('success', "Copied {$copied} subject setup(s) to the selected classes.");
    }

    /** Give every subject of a class the same parts (e.g. CQ 70 + MCQ 30). Skips subjects already set up unless replace. */
    public function fillClass(Request $request, Exam $exam)
    {
        if ($blocked = $this->locked($exam)) {
            return $blocked;
        }

        $data = $request->validate([
            'class_id'                   => ['required', 'integer', 'exists:classes,id'],
            'replace'                    => ['boolean'],
            'papers'                     => ['required', 'array', 'min:1', 'max:10'],
            'papers.*.component_type_id' => ['required', 'integer', 'exists:exam_component_types,id'],
            'papers.*.full_marks'        => ['required', 'numeric', 'gt:0', 'max:1000'],
            'papers.*.pass_marks'        => ['nullable', 'numeric', 'min:0'],
        ]);

        $class = SchoolClass::findOrFail($data['class_id']);

        if ($class->academic_year !== $exam->academic_year) {
            return back()->with('error', 'That class belongs to a different academic year.');
        }

        $existing = ExamPaper::query()->where('exam_id', $exam->id)->where('class_id', $class->id)->pluck('subject_id')->unique()->all();
        $filled   = 0;

        DB::transaction(function () use ($exam, $class, $data, $existing, &$filled) {
            foreach ($class->subjectPlan() as $plan) {
                if (in_array($plan['subject_id'], $existing, true) && empty($data['replace'])) {
                    continue;
                }
                $this->replacePapers($exam, $class->id, $plan['subject_id'], $data['papers']);
                $filled++;
            }
        });

        return back()->with('success', "Set up {$filled} subject(s).");
    }

    /** Copy the whole setup of another exam of the same year (all classes). */
    public function copyFromExam(Request $request, Exam $exam)
    {
        if ($blocked = $this->locked($exam)) {
            return $blocked;
        }

        $data = $request->validate(['source_exam_id' => ['required', 'integer', 'exists:exams,id']]);
        $source = Exam::findOrFail($data['source_exam_id']);

        if ($source->academic_year !== $exam->academic_year || $source->id === $exam->id) {
            return back()->with('error', 'Choose a different exam of the same year to copy from.');
        }

        $count = 0;

        DB::transaction(function () use ($exam, $source, &$count) {
            foreach (ExamPaper::query()->where('exam_id', $source->id)->orderBy('sort_order')->get() as $p) {
                ExamPaper::updateOrCreate(
                    ['exam_id' => $exam->id, 'class_id' => $p->class_id, 'subject_id' => $p->subject_id, 'component_type_id' => $p->component_type_id],
                    ['full_marks' => $p->full_marks, 'pass_marks' => $p->pass_marks, 'sort_order' => $p->sort_order]
                );
                $count++;
            }
        });

        return back()->with('success', "Copied {$count} paper(s) from \"{$source->name}\".");
    }

    // ------------------------------------------------------------------

    private function locked(Exam $exam)
    {
        return $exam->status === 'published'
            ? back()->with('error', 'This exam is published, so its setup is locked.')
            : null;
    }

    private function checkClassAndSubject(Exam $exam, SchoolClass $class, int $subjectId): ?string
    {
        if ($class->academic_year !== $exam->academic_year) {
            return 'That class belongs to a different academic year than this exam.';
        }

        if (! collect($class->subjectPlan())->contains('subject_id', $subjectId)) {
            return 'That subject is not offered by this class.';
        }

        return null;
    }

    /** @param array<int,array<string,mixed>> $papers */
    private function replacePapers(Exam $exam, int $classId, int $subjectId, array $papers): void
    {
        DB::transaction(function () use ($exam, $classId, $subjectId, $papers) {
            ExamPaper::query()->where('exam_id', $exam->id)->where('class_id', $classId)->where('subject_id', $subjectId)->delete();

            foreach (array_values($papers) as $i => $p) {
                ExamPaper::create([
                    'exam_id'           => $exam->id,
                    'class_id'          => $classId,
                    'subject_id'        => $subjectId,
                    'component_type_id' => $p['component_type_id'],
                    'full_marks'        => $p['full_marks'],
                    'pass_marks'        => $p['pass_marks'] ?? 0,
                    'sort_order'        => $i,
                ]);
            }
        });
    }
}
