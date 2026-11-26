<?php

namespace App\Http\Controllers\Teaching;

use App\Jobs\ScheduleTeachingPlans;
use App\Models\BackgroundTask;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teaching\TeachingPlan;
use App\Models\Teaching\TeachingPlanItem;
use App\Models\User;
use App\Services\Teaching\Access;
use App\Services\Teaching\Calendar;
use App\Services\Teaching\PeriodsPerWeek;
use App\Services\Teaching\PlanService;
use App\Services\Teaching\Progress;
use App\Services\Teaching\Rows;
use App\Services\Teaching\Scheduler;
use App\Services\Teaching\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PlanController extends TeachingController
{
    public function __construct(private PlanService $plans, private Progress $progress, private Calendar $calendar, private Rows $rows)
    {
    }

    public function index(Request $request)
    {
        $user = $this->need($request);
        $years = $this->years();
        $year = $this->year($request, $years);
        $this->progress->ensureFresh($year);
        $only = Access::limitTo($user);
        $filters = ['teacher' => $only ?? ((int) $request->input('teacher') ?: null), 'class' => (int) $request->input('class') ?: null,
            'subject' => (int) $request->input('subject') ?: null, 'health' => (string) $request->input('health') ?: null, 'status' => (string) $request->input('status') ?: null];

        $page = $this->rows->plans($year, $filters)->orderBy('p.gap')->orderBy('p.id')->simplePaginate(25)->withQueryString();

        return Inertia::render('Teaching/Plans', [
            'years' => $years, 'session' => $year, 'filters' => $filters,
            'plans' => ['data' => $this->rows->present($page->items()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl(), 'page' => $page->currentPage()],
            'seesAll' => Access::seesAll($user),
            'teachers' => Access::seesAll($user) ? $this->teachers() : [],
            'classes' => $this->rows->classes($year),
            'subjects' => Subject::query()->toBase()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // assignments of the class module that have no plan yet: one tap makes it
            'missing' => $this->can($user) ? $this->rows->missing($year, $user->canDo('teaching.manage_all') ? $filters['teacher'] : $user->id) : [],
            'canSchedule' => $this->can($user),
            'canMakeMine' => $user->canDo('teaching.own') && count($this->rows->missing($year, $user->id, 1)) > 0,
            'approval' => (bool) Settings::get('approval_required'),
            'flash' => $this->flash(),
        ]);
    }

    private function can(User $u): bool
    {
        return $u->canDo('teaching.own') || $u->canDo('teaching.manage_all');
    }

    public function store(Request $request)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        $d = $request->validate(['class_id' => ['required', 'integer', 'exists:classes,id'], 'subject_id' => ['required', 'integer', 'exists:subjects,id'], 'teacher_id' => ['nullable', 'integer', 'exists:users,id']]);
        $teacher = User::findOrFail($d['teacher_id'] ?? $user->id);
        abort_unless(Access::writeFor($user, $teacher->id), 403);
        $class = SchoolClass::findOrFail($d['class_id']);

        // a teacher may only plan what the class module assigns to them
        abort_unless($user->canDo('teaching.manage_all') || $this->rows->assigned($teacher->id, $class->id, (int) $d['subject_id']), 403);

        $plan = $this->plans->create($class, Subject::findOrFail($d['subject_id']), $teacher);

        return redirect()->route('teaching.plans.show', $plan)->with('success', __('teaching.msg.plan_made'));
    }

    public function show(Request $request, TeachingPlan $plan, PeriodsPerWeek $perWeek)
    {
        $user = $this->need($request);
        abort_unless(Access::viewPlan($user, $plan), 403);
        $today = Calendar::today();

        if ($plan->summary_on?->toDateString() !== $today) {
            $this->progress->recompute($plan, $today);
        }

        $items = $plan->items()->with(['chapter' => fn ($q) => $q->select('id', 'name', 'deleted_at')->with('topics:id,chapter_id,name')])->get();
        [$from, $to] = $this->calendar->session($plan->academic_year);
        $row = $this->rows->present($this->rows->plans($plan->academic_year, [])->where('p.id', $plan->id)->get()->all())[0];
        $canEdit = Access::editPlan($user, $plan);

        return Inertia::render('Teaching/Plan', [
            'plan' => $row,
            'items' => $items->map(fn ($i) => ['id' => $i->id, 'chapter' => $i->chapter->name, 'removed' => $i->chapter->deleted_at !== null, 'topics' => $i->chapter->topics->pluck('name'),
                'starts_on' => $i->starts_on?->toDateString(), 'ends_on' => $i->ends_on?->toDateString(), 'planned_periods' => $i->planned_periods,
                'progress' => $i->progress, 'status' => $i->statusOn($today), 'completed' => $i->completed_on !== null])->values(),
            'warnings' => $this->plans->warnings($plan, $items),
            'session' => ['from' => $from, 'to' => $to, 'today' => $today, 'closed' => $this->calendar->closedRanges($from, $to)],
            'perWeek' => $perWeek->for($plan->class_id, $plan->subject_id),
            'reviews' => $plan->reviews()->with('author:id,name')->limit(30)->get()->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'body' => $r->body, 'by' => $r->author?->name, 'at' => $r->created_at->format('d M Y, h:i A')]),
            // where this plan can be copied to, or filled from
            'copySources' => $canEdit ? $this->rows->copySources($plan) : [],
            'copyTargets' => $canEdit ? $this->rows->copyTargets($plan, $user) : [],
            'can' => [
                'edit' => $canEdit,
                'review' => $user->canDo('teaching.review') || $user->canDo('teaching.approve'),
                'approve' => $user->canDo('teaching.approve') && $plan->status === 'submitted',
                'submit' => $canEdit && Settings::get('approval_required') && in_array($plan->status, ['draft', 'returned'], true),
            ],
            'approval' => (bool) Settings::get('approval_required'),
            'flash' => $this->flash(),
        ]);
    }

    private function editable(Request $request, TeachingPlan $plan): User
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        abort_unless(Access::editPlan($user, $plan), 403);

        return $user;
    }

    public function updateItem(Request $request, TeachingPlanItem $item)
    {
        $this->editable($request, $item->plan);
        $d = $request->validate([
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'planned_periods' => ['sometimes', 'integer', 'between:1,500'], 'completed' => ['sometimes', 'boolean'],
        ]);
        $this->plans->updateItem($item, $d);

        return back();
    }

    public function destroyItem(Request $request, TeachingPlanItem $item)
    {
        $this->editable($request, $item->plan);
        $this->plans->removeItem($item);

        return back()->with('success', __('teaching.msg.chapter_removed'));
    }

    public function reorder(Request $request, TeachingPlan $plan)
    {
        $this->editable($request, $plan);
        $d = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);
        $this->plans->reorder($plan, array_map('intval', $d['ids']));

        return back();
    }

    public function sync(Request $request, TeachingPlan $plan)
    {
        $this->editable($request, $plan);
        $n = $this->plans->sync($plan);

        return back()->with('success', trans_choice('teaching.msg.synced', $n, ['count' => $n]));
    }

    public function schedule(Request $request, TeachingPlan $plan, Scheduler $scheduler)
    {
        $this->editable($request, $plan);
        $d = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $r = $scheduler->auto($plan, $d['from'] ?? null, $d['to'] ?? null);

        return back()->with($r['enough'] ? 'success' : 'error', __('teaching.msg.' . ($r['enough'] ? 'scheduled' : 'scheduled_short'), ['chapters' => $r['scheduled'], 'need' => $r['needed'], 'have' => $r['available']]));
    }

    /** one click for a teacher: every subject of theirs gets its plan, with dates already set */
    public function makeMine(Request $request, Scheduler $scheduler)
    {
        $user = $this->need($request, 'teaching.own');
        $year = (string) $request->validate(['session' => ['required', 'string', 'max:10']])['session'];
        $made = 0;
        $skipped = [];

        foreach ($this->rows->missing($year, $user->id, 60) as $m) {
            try {
                $plan = $this->plans->create(SchoolClass::findOrFail($m['class_id']), Subject::findOrFail($m['subject_id']), $user);
                $made++;
                $scheduler->auto($plan);
            } catch (ValidationException $e) {
                $skipped[] = "{$m['subject']} ({$m['class']})";
            }
        }

        if ($skipped) {
            return back()->with('error', __('teaching.msg.made_some', ['count' => $made, 'list' => implode(', ', array_slice($skipped, 0, 5))]));
        }

        return back()->with('success', trans_choice('teaching.msg.made_all', $made, ['count' => $made]));
    }

    /** every undated plan the user may edit, in the background with progress */
    public function scheduleAll(Request $request)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        $d = $request->validate(['session' => ['required', 'string', 'max:10']]);
        $ids = TeachingPlan::where('academic_year', $d['session'])
            ->when(! $user->canDo('teaching.manage_all'), fn ($q) => $q->where('teacher_id', $user->id))
            ->whereHas('items', fn ($q) => $q->whereNull('starts_on'))->pluck('id')->all();

        if (! $ids) {
            return response()->json(['message' => __('teaching.msg.nothing_to_schedule')], 422);
        }

        $task = BackgroundTask::queue('teaching_schedule', $user, ['plans' => $ids], count($ids));
        ScheduleTeachingPlans::dispatch($task->id);

        return response()->json(['task' => $task->id]);
    }

    public function copy(Request $request, TeachingPlan $plan)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        $d = $request->validate(['direction' => ['required', 'in:from,to'], 'other' => ['required', 'integer']]);

        if ($d['direction'] === 'from') {
            $source = TeachingPlan::findOrFail($d['other']);
            abort_unless(Access::editPlan($user, $plan) && Access::viewPlan($user, $source) && $source->subject_id === $plan->subject_id, 403);
            $this->plans->copy($source, $plan->schoolClass, User::findOrFail($plan->teacher_id));

            return back()->with('success', __('teaching.msg.plan_copied'));
        }

        // to another section: the same teacher's, which the class module must assign to them
        abort_unless(Access::editPlan($user, $plan), 403);
        $class = SchoolClass::findOrFail($d['other']);
        abort_unless($user->canDo('teaching.manage_all') || $this->rows->assigned($plan->teacher_id, $class->id, $plan->subject_id), 403);
        $new = $this->plans->copy($plan, $class, User::findOrFail($plan->teacher_id));

        return redirect()->route('teaching.plans.show', $new)->with('success', __('teaching.msg.plan_copied'));
    }

    public function destroy(Request $request, TeachingPlan $plan)
    {
        $this->editable($request, $plan);
        $this->plans->delete($plan);

        return redirect()->route('teaching.plans')->with('success', __('teaching.msg.plan_deleted'));
    }

    public function submit(Request $request, TeachingPlan $plan)
    {
        $user = $this->editable($request, $plan);
        $this->plans->submit($plan, $user);

        return back()->with('success', __('teaching.msg.submitted'));
    }

    public function decide(Request $request, TeachingPlan $plan)
    {
        $user = $this->need($request, 'teaching.approve');
        $d = $request->validate(['approve' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $this->plans->decide($plan, $user, (bool) $d['approve'], $d['comment'] ?? null);

        return back()->with('success', __($d['approve'] ? 'teaching.msg.approved' : 'teaching.msg.returned'));
    }

    public function review(Request $request, TeachingPlan $plan)
    {
        $user = $this->need($request, 'teaching.review', 'teaching.approve');
        $d = $request->validate(['body' => ['required', 'string', 'max:1000']]);
        $this->plans->comment($plan, $user, $d['body']);

        return back()->with('success', __('teaching.msg.review_saved'));
    }
}
