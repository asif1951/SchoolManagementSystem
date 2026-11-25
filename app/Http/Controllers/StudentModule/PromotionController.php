<?php

namespace App\Http\Controllers\StudentModule;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPromotion;
use App\Models\BackgroundTask;
use App\Models\PromotionRun;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\StudentPromotion;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Student Promotion — automatic (a class or every class, with a preview first) and manual (student by student).
 * Both need `students.promote_students`; the rules and the all-or-nothing run are in StudentPromotion.
 */
class PromotionController extends Controller
{
    public function __construct(private StudentPromotion $promotion)
    {
    }

    // ------------------------------------------------------------------
    // automatic
    // ------------------------------------------------------------------

    public function auto(Request $request)
    {
        $years = $this->years();
        $from = (string) $request->input('from', $years[0] ?? now()->year);
        $to = (string) $request->input('to', (string) ((int) $from + 1));
        $class = $request->input('class_name') ?: null;

        $opts = [
            'exclude_failed' => $request->boolean('exclude_failed'),
            'roll_mode'      => $request->input('roll_mode', 'sequential'),
            'graduate_last'  => $request->boolean('graduate_last'),
        ];

        $targetHasClasses = SchoolClass::where('academic_year', $to)->exists();
        $plan = ($request->boolean('preview') && $targetHasClasses && (int) $to > (int) $from)
            ? $this->promotion->plan($from, $to, $class, $opts)
            : null;

        return Inertia::render('StudentModule/PromotionAuto', [
            'years'      => $years,
            'classNames' => SchoolClass::where('academic_year', $from)->orderBy('class_name')->distinct()->pluck('class_name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'filters'    => ['from' => $from, 'to' => $to, 'class_name' => $class ?? ''] + $opts,
            'targetHasClasses' => $targetHasClasses,
            'previewRequested' => $request->boolean('preview'),
            'plan'       => $plan,
            'task'       => session('task'),
            'recent'     => $this->recentRuns(),
            'flash'      => session()->only(['success', 'error']),
        ]);
    }

    /** create the classes of the new session as a copy of the old session's (what the legacy promotion did) */
    public function prepare(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'digits:4'], 'to' => ['required', 'digits:4', 'gt:from']]);

        SchoolClass::ensureForYear((int) $d['to']);

        return redirect()->route('sm.promotion.auto', ['from' => $d['from'], 'to' => $d['to'], 'preview' => 1] + $request->only(['class_name', 'roll_mode', 'exclude_failed', 'graduate_last']));
    }

    public function run(Request $request)
    {
        $d = $request->validate([
            'from'           => ['required', 'digits:4'],
            'to'             => ['required', 'digits:4', 'gt:from'],
            'class_name'     => ['nullable', 'string', 'max:100'],
            'roll_mode'      => ['required', 'in:' . implode(',', StudentPromotion::ROLL_MODES)],
            'exclude_failed' => ['boolean'],
            'graduate_last'  => ['boolean'],
        ]);

        $opts = ['exclude_failed' => (bool) ($d['exclude_failed'] ?? false), 'roll_mode' => $d['roll_mode'], 'graduate_last' => (bool) ($d['graduate_last'] ?? false)];

        $plan = $this->promotion->plan($d['from'], $d['to'], $d['class_name'] ?? null, $opts);

        if ($plan['totals']['promote'] + $plan['totals']['graduate'] === 0) {
            return back()->with('error', 'There is nobody to promote with these choices.');
        }

        $task = BackgroundTask::queue('promotion', $request->user(),
            ['from' => $d['from'], 'to' => $d['to'], 'class_name' => $d['class_name'] ?? null, 'options' => $opts],
            $plan['totals']['promote'] + $plan['totals']['graduate']);

        ProcessPromotion::dispatch($task->id);

        return redirect()->route('sm.promotion.auto', ['from' => $d['from'], 'to' => $d['to']] + ($d['class_name'] ? ['class_name' => $d['class_name']] : []))->with('task', $task->id);
    }

    // ------------------------------------------------------------------
    // manual
    // ------------------------------------------------------------------

    public function manual(Request $request)
    {
        $years = $this->years();
        $from = (string) $request->input('from', $years[0] ?? now()->year);
        $to = (string) $request->input('to', (string) ((int) $from + 1));
        $classId = $request->integer('class_id') ?: null;

        $sources = SchoolClass::where('academic_year', $from)->where('status', 'active')->orderBy('class_name')->orderBy('section')->get();
        $source = $classId ? $sources->firstWhere('id', $classId) : null;

        return Inertia::render('StudentModule/PromotionManual', [
            'years'    => $years,
            'filters'  => ['from' => $from, 'to' => $to, 'class_id' => $source?->id ? (string) $source->id : ''],
            'classes'  => $sources->sort(fn ($a, $b) => strnatcasecmp($a->class_name, $b->class_name) ?: strnatcasecmp((string) $a->section, (string) $b->section))->values()
                ->map(fn ($c) => ['id' => $c->id, 'label' => $this->label($c)]),
            'targets'  => SchoolClass::where('academic_year', $to)->where('status', 'active')->get()
                ->sort(fn ($a, $b) => strnatcasecmp($a->class_name, $b->class_name) ?: strnatcasecmp((string) $a->section, (string) $b->section))->values()
                ->map(fn ($c) => ['id' => $c->id, 'label' => $this->label($c)]),
            'students' => $source ? Student::where('class_id', $source->id)->orderByRaw('CAST(roll_number AS UNSIGNED)')->orderBy('student_name')->get(['id', 'student_name', 'student_id', 'roll_number', 'status'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->student_name, 'code' => $s->student_id, 'roll' => $s->roll_number, 'status' => $s->status, 'status_label' => Student::STATUSES[$s->status] ?? $s->status]) : [],
            'recent'   => $this->recentRuns(),
            'flash'    => session()->only(['success', 'error']),
        ]);
    }

    public function applyManual(Request $request)
    {
        $d = $request->validate([
            'from'                      => ['required', 'digits:4'],
            'to'                        => ['required', 'digits:4', 'gt:from'],
            'decisions'                 => ['required', 'array', 'min:1', 'max:1000'],
            'decisions.*.enrollment_id' => ['required', 'integer'],
            'decisions.*.action'        => ['required', 'in:promote,repeat,drop,transfer'],
            'decisions.*.class_id'      => ['nullable', 'integer'],
            'decisions.*.roll_number'   => ['nullable', 'string', 'max:50'],
        ]);

        $run = $this->promotion->applyManual($d['from'], $d['to'], $d['decisions'], $request->user());

        $parts = collect(['promoted' => 'promoted', 'repeated' => 'repeating', 'dropped' => 'dropped'])
            ->map(fn ($label, $k) => $run->{$k} ? "{$run->{$k}} {$label}" : null)->filter()->implode(', ');

        return back()->with('success', "Done: {$parts}.");
    }

    // ------------------------------------------------------------------

    private function years(): array
    {
        $years = SchoolClass::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->all();

        return $years ?: [(string) now()->year];
    }

    private function recentRuns()
    {
        return PromotionRun::with('user:id,name')->latest('id')->limit(8)->get()->map(fn ($r) => [
            'id' => $r->id, 'mode' => $r->mode, 'from' => $r->from_session, 'to' => $r->to_session, 'scope' => $r->scope,
            'promoted' => $r->promoted, 'repeated' => $r->repeated, 'dropped' => $r->dropped, 'graduated' => $r->graduated, 'skipped' => $r->skipped,
            'by' => $r->user?->name, 'at' => $r->created_at?->format('d M Y, h:i A'),
        ]);
    }

    private function label(SchoolClass $c): string
    {
        return trim($c->short_label . ($c->group_name ? " ({$c->group_name})" : '') . ($c->shift_name ? " · {$c->shift_name}" : '') . ($c->medium_name ? " · {$c->medium_name}" : ''));
    }
}
