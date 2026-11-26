<?php

namespace App\Services\Teaching;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teaching\TeachingPlan;
use App\Models\Teaching\TeachingPlanItem;
use App\Models\User;
use App\Notifications\TeachingNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/** The Yearly Teaching Plan: making it from the syllabus, dating its chapters, approving and reviewing it. */
class PlanService
{
    public function __construct(private SyllabusService $syllabus, private Progress $progress, private Calendar $calendar, private PeriodsPerWeek $perWeek)
    {
    }

    private function fail(string $key, array $params = []): never
    {
        throw ValidationException::withMessages(['plan' => __("teaching.msg.{$key}", $params)]);
    }

    /** make the plan with every chapter of the syllabus; the teacher then only sets dates */
    public function create(SchoolClass $class, Subject $subject, User $teacher): TeachingPlan
    {
        if (! in_array($subject->name, $class->subjects, true)) {
            $this->fail('subject_not_in_class');
        }

        if (TeachingPlan::where(['class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id])->exists()) {
            $this->fail('plan_exists');
        }

        $year = $this->syllabus->effectiveYear((string) $class->academic_year, $class->class_name, $subject->id);

        if (! $year) {
            $this->fail('no_syllabus', ['class' => $class->class_name, 'subject' => $subject->name]);
        }

        return DB::transaction(function () use ($class, $subject, $teacher, $year) {
            $plan = TeachingPlan::create(['academic_year' => (string) $class->academic_year, 'class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id]);
            $this->fillFromSyllabus($plan, $this->syllabus->chapters($year, $class->class_name, $subject->id));
            AuditLog::record($plan, 'plan.created', ['class_id' => $class->id, 'subject' => $subject->name, 'teacher_id' => $teacher->id]);
            $this->progress->recompute($plan);

            return $plan;
        });
    }

    /** add the chapters the plan does not have yet */
    private function fillFromSyllabus(TeachingPlan $plan, Collection $chapters): int
    {
        $have = TeachingPlanItem::where('plan_id', $plan->id)->pluck('chapter_id')->flip();
        $new = $chapters->reject(fn ($c) => isset($have[$c->id]))->values();

        if ($new->isEmpty()) {
            return 0;
        }

        // chapters without a suggested number share the periods the subject has in a year (about 36 teaching weeks)
        $year = $this->perWeek->for($plan->class_id, $plan->subject_id) * 36;
        $given = $chapters->sum('periods');
        $without = $chapters->where('periods', 0)->count();
        $default = $without ? max(1, (int) floor(max($without, $year - $given) / $without)) : 1;
        $position = (int) TeachingPlanItem::where('plan_id', $plan->id)->max('position');
        $now = now();

        TeachingPlanItem::insert($new->map(fn ($c, $i) => ['plan_id' => $plan->id, 'chapter_id' => $c->id, 'position' => $position + $i + 1,
            'planned_periods' => $c->periods ?: $default, 'progress' => 0, 'created_at' => $now, 'updated_at' => $now])->all());

        return $new->count();
    }

    /** chapters added to the syllabus after the plan was made */
    public function sync(TeachingPlan $plan): int
    {
        $class = $plan->schoolClass;
        $year = $this->syllabus->effectiveYear($plan->academic_year, $class->class_name, $plan->subject_id);
        $n = $year ? $this->fillFromSyllabus($plan, $this->syllabus->chapters($year, $class->class_name, $plan->subject_id)) : 0;

        if ($n) {
            $this->changed($plan, 'plan.synced', ['added' => $n]);
        }

        return $n;
    }

    /**
     * Change one chapter's dates or periods (from the timeline, the table, or the weekly and monthly views: there is
     * only this one record, so every view follows).
     */
    public function updateItem(TeachingPlanItem $item, array $data, ?string $today = null): void
    {
        $plan = $item->plan;
        [$from, $to] = $this->calendar->session($plan->academic_year);
        $start = array_key_exists('starts_on', $data) ? $data['starts_on'] : $item->starts_on?->toDateString();
        $end = array_key_exists('ends_on', $data) ? $data['ends_on'] : $item->ends_on?->toDateString();

        // one date given for an undated chapter: it is a one-day chapter until the other is set
        $start ??= $end;
        $end ??= $start;

        if ($start && ($end < $start)) {
            $this->fail('end_before_start');
        }

        if ($start && ($start < $from || $end > $to)) {
            $this->fail('outside_session', ['from' => $from, 'to' => $to]);
        }

        $item->fill(['starts_on' => $start, 'ends_on' => $end] + array_intersect_key($data, ['planned_periods' => 1]));

        if (array_key_exists('completed', $data)) {
            $item->completed_on = $data['completed'] ? ($item->completed_on ?? ($today ?? Calendar::today())) : null;
        }

        if (! $item->isDirty()) {
            return;
        }

        $changes = ['chapter' => $item->chapter?->name];

        foreach ($item->getDirty() as $k => $v) {
            $old = $item->getOriginal($k);
            $changes[$k] = ['from' => $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old, 'to' => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v];
        }

        $replans = $item->isDirty(['starts_on', 'ends_on', 'planned_periods']);

        DB::transaction(function () use ($item, $plan, $changes, $replans) {
            $item->save();
            $this->changed($plan, 'plan.item', $changes, $replans);
        });
    }

    public function removeItem(TeachingPlanItem $item): void
    {
        if (DB::table('teaching_logs')->where('item_id', $item->id)->exists()) {
            $this->fail('chapter_has_logs');
        }

        DB::transaction(function () use ($item) {
            $plan = $item->plan;
            $name = $item->chapter?->name;
            $item->delete();
            $this->changed($plan, 'plan.item_removed', ['chapter' => $name]);
        });
    }

    public function reorder(TeachingPlan $plan, array $ids): void
    {
        DB::transaction(function () use ($plan, $ids) {
            $mine = TeachingPlanItem::where('plan_id', $plan->id)->pluck('id')->flip();
            $n = 0;

            foreach ($ids as $id) {
                isset($mine[$id]) && TeachingPlanItem::whereKey($id)->update(['position' => ++$n]);
            }

            $this->changed($plan, 'plan.order', ['order' => array_values($ids)], false);
        });
    }

    /** after a change: an approved plan needs approval again (only when approval is switched on); figures are refreshed */
    private function changed(TeachingPlan $plan, string $event, array $changes, bool $replans = true): void
    {
        if ($replans && Settings::get('approval_required') && in_array($plan->status, ['approved', 'submitted'], true)) {
            $plan->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'submitted_at' => null]);
            $changes['needs_approval_again'] = true;
        }

        AuditLog::record($plan, $event, $changes);
        $this->progress->recompute($plan);
    }

    /**
     * Start a plan from another one: last year's, or another section's. Chapters are matched by name, dates move by the
     * difference in years.
     */
    public function copy(TeachingPlan $source, SchoolClass $class, User $teacher): TeachingPlan
    {
        $subject = Subject::findOrFail($source->subject_id);
        $plan = TeachingPlan::where(['class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id])->first()
            ?? $this->create($class, $subject, $teacher);

        if ($plan->id === $source->id) {
            $this->fail('copy_same');
        }

        $shift = (int) substr((string) $class->academic_year, 0, 4) - (int) substr($source->academic_year, 0, 4);
        $from = $source->items()->with('chapter:id,name')->get()->keyBy(fn ($i) => mb_strtolower($i->chapter->name));
        $move = fn ($d) => $d ? Carbon::parse($d)->addYearsNoOverflow($shift)->toDateString() : null;

        DB::transaction(function () use ($plan, $source, $from, $move) {
            foreach ($plan->items()->with('chapter:id,name')->get() as $item) {
                if ($old = $from[mb_strtolower($item->chapter->name)] ?? null) {
                    $item->update(['starts_on' => $move($old->starts_on), 'ends_on' => $move($old->ends_on), 'planned_periods' => $old->planned_periods, 'position' => $old->position]);
                }
            }

            $this->changed($plan, 'plan.copied', ['from_plan' => $source->id]);
        });

        return $plan;
    }

    public function delete(TeachingPlan $plan): void
    {
        if (DB::table('teaching_logs')->where('plan_id', $plan->id)->exists()) {
            $this->fail('plan_has_logs');
        }

        DB::transaction(function () use ($plan) {
            AuditLog::record($plan, 'plan.deleted', ['class_id' => $plan->class_id, 'subject_id' => $plan->subject_id, 'teacher_id' => $plan->teacher_id]);
            $plan->delete();
        });
    }

    /**
     * Things the teacher should know: chapters overlapping, a chapter with fewer teaching periods than planned,
     * chapters without dates.
     *
     * @return array<int,array{type:string,item?:int,text:string}>
     */
    public function warnings(TeachingPlan $plan, Collection $items): array
    {
        $out = [];
        $perWeek = $this->perWeek->for($plan->class_id, $plan->subject_id);
        $perDay = $perWeek / max(1, count(app(\App\Services\RoutineService::class)->workingDays()));
        [$from, $to] = $this->calendar->session($plan->academic_year);
        $days = $this->calendar->teachingDays($from, $to);
        $dated = $items->filter(fn ($i) => $i->starts_on)->sortBy(fn ($i) => $i->starts_on->toDateString())->values();

        foreach ($dated as $k => $i) {
            $a = $i->starts_on->toDateString();
            $b = $i->ends_on->toDateString();
            $room = (int) floor(count(array_filter($days, fn ($d) => $d >= $a && $d <= $b)) * $perDay + 0.001);

            if ($room < $i->planned_periods) {
                $out[] = ['type' => 'periods', 'item' => $i->id, 'text' => __('teaching.warn.periods', ['chapter' => $i->chapter->name, 'room' => $room, 'need' => $i->planned_periods])];
            }

            $next = $dated[$k + 1] ?? null;

            // sharing the boundary day is normal; more than that is an overlap
            if ($next && $next->starts_on->toDateString() < $b) {
                $out[] = ['type' => 'overlap', 'item' => $next->id, 'text' => __('teaching.warn.overlap', ['a' => $i->chapter->name, 'b' => $next->chapter->name])];
            }
        }

        $need = (int) $items->sum('planned_periods');
        $have = (int) floor(count($days) * $perDay);

        if ($need > $have) {
            array_unshift($out, ['type' => 'total', 'text' => __('teaching.warn.total', ['need' => $need, 'have' => $have, 'week' => $perWeek])]);
        }

        if ($n = $items->whereNull('starts_on')->count()) {
            $out[] = ['type' => 'undated', 'text' => trans_choice('teaching.warn.undated', $n, ['count' => $n])];
        }

        return $out;
    }

    // ---- approval and review ----------------------------------------------------------------------------------------

    public function submit(TeachingPlan $plan, User $by): void
    {
        if (! Settings::get('approval_required') || ! in_array($plan->status, ['draft', 'returned'], true)) {
            $this->fail('cannot_submit');
        }

        if ($plan->items()->whereNull('starts_on')->exists() || ! $plan->items()->exists()) {
            $this->fail('set_dates_first');
        }

        DB::transaction(function () use ($plan, $by) {
            $plan->update(['status' => 'submitted', 'submitted_at' => now()]);
            AuditLog::record($plan, 'plan.submitted', []);

            Notification::send($this->approvers()->where('id', '!=', $by->id), new TeachingNotice('notify.submitted', ['name' => $by->name], "/teaching/plans/{$plan->id}"));
        });
    }

    /** active people whose role or personal permissions allow approving */
    private function approvers(): Collection
    {
        $roles = collect(\App\Models\Role::abilityMap())->filter(fn ($a) => in_array('*', $a, true) || in_array('teaching.approve', $a, true))->keys()->push(User::ROLE_ADMIN)->all();

        return User::where('status', User::STATUS_ACTIVE)->where(fn ($q) => $q->whereIn('role', $roles)->orWhereJsonContains('extra_abilities', 'teaching.approve'))->limit(50)->get();
    }

    public function decide(TeachingPlan $plan, User $by, bool $approve, ?string $comment = null): void
    {
        if ($plan->status !== 'submitted') {
            $this->fail('not_submitted');
        }

        if (! $approve && ! trim((string) $comment)) {
            $this->fail('say_why');
        }

        DB::transaction(function () use ($plan, $by, $approve, $comment) {
            $plan->update(['status' => $approve ? 'approved' : 'returned', 'approved_by' => $approve ? $by->id : null, 'approved_at' => $approve ? now() : null]);
            $plan->reviews()->create(['user_id' => $by->id, 'kind' => $approve ? 'approved' : 'returned', 'body' => trim((string) $comment) ?: '—']);
            AuditLog::record($plan, $approve ? 'plan.approved' : 'plan.returned', ['comment' => $comment]);
            $this->tell($plan, $by, $approve ? 'notify.approved' : 'notify.returned');
        });
    }

    public function comment(TeachingPlan $plan, User $by, string $body): void
    {
        DB::transaction(function () use ($plan, $by, $body) {
            $plan->reviews()->create(['user_id' => $by->id, 'kind' => 'comment', 'body' => trim($body)]);
            AuditLog::record($plan, 'plan.reviewed', ['comment' => $body]);
            $this->tell($plan, $by, 'notify.review');
        });
    }

    private function tell(TeachingPlan $plan, User $by, string $key): void
    {
        $teacher = User::find($plan->teacher_id);

        if ($teacher && $teacher->id !== $by->id) {
            $teacher->notify(new TeachingNotice($key, ['name' => $by->name], "/teaching/plans/{$plan->id}"));
        }
    }
}
