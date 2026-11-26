<?php

namespace App\Http\Controllers\Routine;

use App\Http\Controllers\Controller;
use App\Models\ClassRoutine;
use App\Models\RoutinePeriod;
use App\Models\SchoolClass;
use App\Models\Shift;
use App\Models\User;
use App\Models\RoutinePlan;
use App\Services\RoutineGenerator;
use App\Services\RoutineService;
use App\Services\TabulationBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The Routine module. Managing (making, editing, publishing, the bell schedule) needs `routine.manage`; seeing the
 * published routines and one's own timetable needs `routine.view`. Teachers only ever see what was published.
 */
class RoutineController extends Controller
{
    public function __construct(private RoutineService $routines, private RoutineGenerator $generator)
    {
    }

    private function canManage(Request $r): bool
    {
        return $r->user()->canDo('routine.manage');
    }

    private function authorizeRead(Request $r): void
    {
        abort_unless($r->user()->canDo('routine.view') || $this->canManage($r), 403);
    }

    private function authorizeManage(Request $r): void
    {
        abort_unless($this->canManage($r), 403);
    }

    private function years(): array
    {
        return SchoolClass::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->all() ?: [(string) now()->year];
    }

    /** routines print landscape; the toolbar can switch to portrait */
    private function paper(Request $r): array
    {
        $o = in_array($r->input('orientation'), ['portrait', 'landscape'], true) ? $r->input('orientation') : 'landscape';

        return ['orientation' => $o, 'asked' => $o];
    }

    // ------------------------------------------------------------------
    // the list of classes
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $this->authorizeRead($request);
        $manage = $this->canManage($request);

        if ($manage) {
            $this->routines->ensureDefaultPeriods();
        }

        $years = $this->years();
        $year = (string) $request->input('session', $years[0]);
        $term = trim((string) $request->input('q'));
        $days = count($this->routines->workingDays());
        $teaching = [];                                    // teaching periods per shift, worked out once each
        $ownTeaching = RoutinePeriod::whereNotNull('class_id')->where('is_break', false)->selectRaw('class_id, COUNT(*) n')->groupBy('class_id')->pluck('n', 'class_id');

        $classes = SchoolClass::query()->where('academic_year', $year)->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('class_name', 'like', "%{$term}%")->orWhere('class_title', 'like', "%{$term}%")->orWhere('section', 'like', "%{$term}%")->orWhere('section_title', 'like', "%{$term}%")))
            ->orderBy('class_name')->orderBy('section')->get()
            ->sort(fn ($a, $b) => strnatcasecmp($a->class_name, $b->class_name) ?: strnatcasecmp((string) $a->section, (string) $b->section))->values();

        $routines = ClassRoutine::whereIn('class_id', $classes->pluck('id'))->withCount('slots')->get()->keyBy('class_id');

        $rows = $classes->map(function ($c) use ($routines, $days, &$teaching) {
            $r = $routines[$c->id] ?? null;
            $teaching[$c->shift_id ?? 0] ??= $this->routines->periodsFor($c->shift_id)->where('is_break', false)->count();
            $perDay = $ownTeaching[$c->id] ?? $teaching[$c->shift_id ?? 0];
            $status = ! $r || $r->slots_count === 0 ? ($r?->is_published ? 'published' : 'empty') : ($r->is_published ? ($r->updated_at > $r->published_at ? 'changed' : 'published') : 'draft');

            return ['id' => $c->id, 'label' => $c->full_label, 'class' => $c->class_label, 'section' => $c->section_label, 'shift' => $c->shift_name, 'medium' => $c->medium_name, 'group' => $c->group_name,
                    'class_teacher' => $c->class_teacher ?: null, 'status' => $status, 'filled' => $r?->slots_count ?? 0, 'total' => $perDay * $days, 'own_periods' => isset($ownTeaching[$c->id]),
                    'published_at' => $r?->published_at?->format('d M Y')];
        });

        // someone who may only read sees just what has been published
        if (! $manage) {
            $rows = $rows->filter(fn ($r) => in_array($r['status'], ['published', 'changed'], true))->values();
        }

        return Inertia::render('Routine/Index', [
            'years' => $years, 'session' => $year, 'q' => $term, 'rows' => $rows,
            'can' => ['manage' => $manage], 'flash' => session()->only(['success', 'error']),
            'periodsReady' => RoutinePeriod::exists(),
            'clashes' => $manage ? $this->routines->teacherClashes() : [],
        ]);
    }

    // ------------------------------------------------------------------
    // the editor
    // ------------------------------------------------------------------

    public function editor(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);
        $this->routines->ensureDefaultPeriods();

        $others = SchoolClass::where('academic_year', $class->academic_year)->where('status', 'active')->where('id', '!=', $class->id)->get()
            ->sort(fn ($a, $b) => strnatcasecmp($a->class_name, $b->class_name) ?: strnatcasecmp((string) $a->section, (string) $b->section))
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->full_label . ($c->shift_name ? " ({$c->shift_name})" : '')])->values();

        $mine = SchoolClass::where('academic_year', $class->academic_year)->where('status', 'active')->get()
            ->sort(fn ($a, $b) => strnatcasecmp($a->class_name, $b->class_name) ?: strnatcasecmp((string) $a->section, (string) $b->section))
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->full_label . ($c->shift_name ? " ({$c->shift_name})" : '')])->values();

        return Inertia::render('Routine/Editor', [
            'grid' => $this->routines->grid($class),
            'plan' => $this->routines->planFor($class),
            'classes' => $mine,
            'others' => $others,
            'teachers' => User::where('role', User::ROLE_TEACHER)->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name']),
            'flash' => session()->only(['success', 'error']),
        ]);
    }

    public function cell(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);

        $d = $request->validate([
            'day' => ['required', 'integer', 'between:0,6'],
            'period_id' => ['required', 'integer', 'exists:routine_periods,id'],
            'subject' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:60'],
            'teacher_id' => ['nullable', 'integer'],
        ]);

        $this->routines->setCell($class, (int) $d['day'], (int) $d['period_id'], $d['subject'] ?? null, $d['label'] ?? null, ($d['teacher_id'] ?? null) ? (int) $d['teacher_id'] : null);
        $this->routines->routineFor($class)->touch();

        return back();
    }

    /** everything arranged on the editor, saved in one go */
    public function cells(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);

        $d = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:200'],
            'changes.*.day' => ['required', 'integer', 'between:0,6'],
            'changes.*.period_id' => ['required', 'integer'],
            'changes.*.subject' => ['nullable', 'string', 'max:100'],
            'changes.*.label' => ['nullable', 'string', 'max:60'],
            'changes.*.teacher_id' => ['nullable', 'integer'],
        ]);

        $this->routines->saveCells($class, $d['changes']);
        $this->routines->routineFor($class)->touch();

        return back();
    }

    public function copyDay(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);

        $d = $request->validate(['from_day' => ['required', 'integer', 'between:0,6'], 'to_days' => ['required', 'array', 'min:1'], 'to_days.*' => ['integer', 'between:0,6']]);
        $r = $this->routines->copyDay($class, (int) $d['from_day'], $d['to_days']);
        $this->routines->routineFor($class)->touch();

        return back()->with($r['skipped'] ? 'error' : 'success', $r['skipped']
            ? "Copied {$r['copied']} periods. Left out: " . implode('; ', array_slice($r['skipped'], 0, 4)) . (count($r['skipped']) > 4 ? '…' : '')
            : "Copied {$r['copied']} periods to the other days.");
    }

    public function copyFrom(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);

        $d = $request->validate(['from_class_id' => ['required', 'integer', 'exists:classes,id']]);
        $r = $this->routines->copyFromClass($class, SchoolClass::findOrFail($d['from_class_id']));
        $this->routines->routineFor($class)->touch();

        return back()->with($r['skipped'] ? 'error' : 'success', $r['skipped']
            ? "Copied {$r['copied']} periods. Left out: " . implode('; ', array_slice($r['skipped'], 0, 4)) . (count($r['skipped']) > 4 ? '…' : '')
            : "Copied {$r['copied']} periods.");
    }

    public function clear(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);
        $this->routines->clearAll($class);
        $this->routines->routineFor($class)->touch();

        return back()->with('success', 'The routine was emptied.');
    }

    public function publish(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);
        $this->routines->publish($class, $request->user());

        return back()->with('success', $class->full_label . ' was published.');
    }

    public function unpublish(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);
        $this->routines->unpublish($class);

        return back()->with('success', $class->full_label . ' was unpublished.');
    }


    // ------------------------------------------------------------------
    // a class's own periods and breaks
    // ------------------------------------------------------------------

    public function saveClassPeriods(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);

        $d = $request->validate([
            'periods' => ['required', 'array', 'min:1', 'max:20'],
            'periods.*.name' => ['required', 'string', 'max:40'],
            'periods.*.starts_at' => ['required', 'string', 'max:8'],
            'periods.*.ends_at' => ['required', 'string', 'max:8'],
            'periods.*.is_break' => ['nullable', 'boolean'],
            'also_for' => ['nullable', 'array', 'max:300'],
            'also_for.*' => ['integer', 'exists:classes,id'],
        ]);

        $others = $this->routines->withoutNewClashes(function () use ($class, $d) {
            $this->routines->saveClassPeriods($class, $d['periods']);

            return $this->routines->copyClassPeriods($class, $d['also_for'] ?? []);
        });
        $this->routines->routineFor($class)->touch();

        return back()->with('success', $others ? "The periods were saved for {$class->full_label} and {$others} other " . ($others === 1 ? 'class' : 'classes') . '.' : 'The periods of this class were saved.');
    }

    public function resetClassPeriods(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);
        $this->routines->resetClassPeriods($class);
        $this->routines->routineFor($class)->touch();

        return back()->with('success', 'This class uses the periods of its shift again.');
    }

    // ------------------------------------------------------------------
    // the weekly plan and the automatic routine
    // ------------------------------------------------------------------

    public function savePlan(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);
        $this->validatePlan($request);

        $this->routines->savePlan($class, $request->input('plan'));
        $n = $request->boolean('copy_to_sections') ? $this->routines->copyPlanToSections($class) : 0;

        return back()->with('success', $n ? "The plan was saved, and copied to {$n} other " . ($n === 1 ? 'section' : 'sections') . '.' : 'The weekly plan was saved.');
    }

    private function validatePlan(Request $request): void
    {
        $request->validate([
            'plan' => ['required', 'array', 'min:1', 'max:60'],
            'plan.*.subject' => ['required', 'string', 'max:100'],
            'plan.*.periods_per_week' => ['required', 'integer', 'between:0,40'],
            'plan.*.max_per_day' => ['nullable', 'integer', 'between:1,8'],
        ]);
    }

    /** try the plan of one class: nothing is saved except the plan itself */
    public function autoPreview(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);
        $this->validatePlan($request);
        $this->routines->savePlan($class, $request->input('plan'));

        $result = $this->generator->run(collect([$class]), $this->generatorOptions($request));

        return response()->json(['seed' => $result['seed'], 'ok' => $result['ok'], 'result' => $result['classes'][$class->id]]);
    }

    public function autoApply(Request $request, SchoolClass $class)
    {
        $this->authorizeManage($request);

        $keep = $request->boolean('keep_existing');
        $result = $this->generator->run(collect([$class]), $this->generatorOptions($request));
        $r = $this->generator->apply($result, collect([$class]), $keep);

        return back()->with($r['skipped'] ? 'error' : 'success', $r['skipped']
            ? "Placed {$r['applied']} periods. Left out: " . implode('; ', array_slice($r['skipped'], 0, 3))
            : "The routine was made: {$r['applied']} periods placed. You can still change any of them.");
    }

    private function generatorOptions(Request $request): array
    {
        return [
            'keep_existing' => $request->boolean('keep_existing'),
            'teacher_max_per_day' => $request->filled('teacher_max_per_day') ? max(1, (int) $request->input('teacher_max_per_day')) : null,
            'seed' => $request->filled('seed') ? (int) $request->input('seed') : null,
        ];
    }

    // ------------------------------------------------------------------
    // printing
    // ------------------------------------------------------------------

    public function print(Request $request, SchoolClass $class, TabulationBuilder $school)
    {
        $this->authorizeRead($request);

        $published = $this->canManage($request) ? $request->input('source') !== 'draft' : true;
        $routine = $this->routines->routineFor($class);

        abort_if($published && ! $routine->is_published && ! $this->canManage($request), 404);

        // "published" with nothing published yet falls back to the draft for the person who manages it
        if ($published && ! $routine->is_published) {
            $published = false;
        }

        $lang = $request->input('lang') === 'bn' ? 'bn' : 'en';
        $grid = $this->routines->grid($class, $published);

        $info = $school->school();

        return view('print.routine-class', [
            'g' => $grid, 'lang' => $lang, 'published' => $published, 'school' => $info, 'frameColor' => $info['color'],
            ...$this->paper($request), 'examTypes' => [], 'examValue' => '',
            'keep' => [], 'classId' => $class->id, 'canManage' => $this->canManage($request), 'showAll' => $request->input('who') !== 'one',
            'sessionLabel' => $class->academic_year,
        ]);
    }

    // ------------------------------------------------------------------
    // periods and days
    // ------------------------------------------------------------------

    public function periods(Request $request)
    {
        $this->authorizeManage($request);
        $this->routines->ensureDefaultPeriods();

        $sets = [];

        foreach ([null, ...Shift::active()->ordered()->pluck('id')->all()] as $shiftId) {
            $own = RoutinePeriod::where('shift_id', $shiftId)->orderBy('sort_order')->orderBy('starts_at')->get();
            $sets[] = ['shift_id' => $shiftId, 'own' => $own->isNotEmpty(),
                       'periods' => $own->map(fn ($p) => ['name' => $p->name, 'starts_at' => $p->start, 'ends_at' => $p->end, 'is_break' => $p->is_break])->values()->all()];
        }

        return Inertia::render('Routine/Periods', [
            'shifts' => Shift::active()->ordered()->get(['id', 'name']),
            'sets' => $sets,
            'days' => collect(RoutineService::WEEK_ORDER)->map(fn ($d) => ['id' => $d, 'name' => RoutineService::DAYS[$d]['en']])->values(),
            'working' => $this->routines->workingDays(),
            // these classes do not follow this page: they have periods of their own
            'ownClasses' => SchoolClass::whereIn('id', RoutinePeriod::whereNotNull('class_id')->select('class_id'))
                ->orderByDesc('academic_year')->orderBy('class_name')->orderBy('section')->get()
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->full_label, 'year' => $c->academic_year])->values(),
            'flash' => session()->only(['success', 'error']),
        ]);
    }

    public function savePeriods(Request $request)
    {
        $this->authorizeManage($request);

        $d = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'periods' => ['required', 'array', 'min:1', 'max:20'],
            'periods.*.name' => ['required', 'string', 'max:40'],
            'periods.*.starts_at' => ['required', 'string', 'max:8'],
            'periods.*.ends_at' => ['required', 'string', 'max:8'],
            'periods.*.is_break' => ['nullable', 'boolean'],
        ]);

        $this->routines->savePeriods($d['shift_id'] ?? null, $d['periods']);

        return back()->with('success', 'The periods were saved.');
    }

    /** a shift goes back to using the common set */
    public function resetPeriods(Request $request, Shift $shift)
    {
        $this->authorizeManage($request);
        $this->routines->resetShiftPeriods($shift->id);

        return back()->with('success', "{$shift->name} now uses the common periods.");
    }

    public function saveDays(Request $request)
    {
        $this->authorizeManage($request);
        $d = $request->validate(['days' => ['required', 'array', 'min:1'], 'days.*' => ['integer', 'between:0,6']]);
        $this->routines->setWorkingDays($d['days']);
        app(\App\Services\AcademicCalendar::class)->changed();

        return back()->with('success', 'The working days were saved.');
    }

    // ------------------------------------------------------------------
    // teachers
    // ------------------------------------------------------------------

    public function teachers(Request $request)
    {
        $this->authorizeRead($request);
        $manage = $this->canManage($request);

        $teacherId = $manage ? (int) $request->input('teacher', 0) : $request->user()->id;
        $published = $manage ? $request->input('source') !== 'draft' : true;

        $years = $this->years();
        $year = (string) $request->input('session', $years[0]);

        return Inertia::render('Routine/Teachers', [
            'years' => $years, 'session' => $year,
            'teachers' => $manage ? User::where('role', User::ROLE_TEACHER)->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name']) : [],
            'teacherId' => $teacherId ?: null,
            'source' => $published ? 'published' : 'draft',
            'grid' => $teacherId ? $this->routines->teacherGrid($teacherId, $published, $year) : null,
            'can' => ['manage' => $manage],
        ]);
    }

    public function printTeacher(Request $request, User $teacher, TabulationBuilder $school)
    {
        $this->authorizeRead($request);
        abort_unless($this->canManage($request) || $teacher->id === $request->user()->id, 403);

        $published = $this->canManage($request) ? $request->input('source') !== 'draft' : true;
        $info = $school->school();

        return view('print.routine-teacher', [
            'g' => $this->routines->teacherGrid($teacher->id, $published, (string) $request->input('session', $this->years()[0])), 'lang' => $request->input('lang') === 'bn' ? 'bn' : 'en', 'published' => $published,
            'school' => $info, 'frameColor' => $info['color'], ...$this->paper($request), 'examTypes' => [], 'examValue' => '',
            'keep' => array_filter(['session' => $request->input('session')]), 'teacherId' => $teacher->id,
        ]);
    }
}
