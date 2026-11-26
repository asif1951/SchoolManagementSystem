<?php

namespace App\Services\Teaching;

use App\Models\AuditLog;
use App\Models\RoutinePeriod;
use App\Models\Teaching\TeachingLog;
use App\Models\Teaching\TeachingPlan;
use App\Models\User;
use App\Services\AcademicCalendar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Daily Teaching Log. A day opens with the teacher's classes from the routine already listed; one class is one row,
 * saved with a single small request.
 */
class DailyLog
{
    public function __construct(private Progress $progress, private Rows $rows)
    {
    }

    private function fail(string $key, array $params = []): never
    {
        throw ValidationException::withMessages(['log' => __("teaching.msg.{$key}", $params)]);
    }

    /** may the user still write this date? */
    public function editable(User $user, string $date, ?string $today = null): bool
    {
        $today ??= Calendar::today();

        if ($date > $today) {
            return false;
        }

        return $date >= Carbon::parse($today)->subDays((int) Settings::get('edit_window_days'))->toDateString() || $user->canDo('teaching.edit_past');
    }

    /**
     * The teacher's periods on a date, from the routine: the published copy of a class when it has one, else its draft.
     *
     * @return Collection<int,array{class_id:int,subject_id:int,period_id:int}>
     */
    public function routineSlots(int $teacherId, string $date): Collection
    {
        // the weekdays that could run today: the usual one, or those a make-up day follows
        $weekday = Carbon::parse($date)->dayOfWeek;
        $days = collect($this->academic()->events($date, $date))->where('type', 'makeup')->pluck('follows_day')->filter(fn ($d) => $d !== null)->push($weekday)->unique();
        $year = substr($date, 0, 4);
        $out = collect();

        foreach ($days as $day) {
            $draft = DB::table('class_routine_slots as s')->join('class_routines as r', 'r.id', '=', 's.class_routine_id')->join('classes as c', 'c.id', '=', 'r.class_id')
                ->where('s.teacher_id', $teacherId)->where('s.day', $day)->whereNull('r.published_slots')->whereNotNull('s.subject_id')->where('c.academic_year', 'like', "{$year}%")
                ->get(['r.class_id', 's.subject_id', 's.period_id'])
                ->map(fn ($s) => ['class_id' => (int) $s->class_id, 'subject_id' => (int) $s->subject_id, 'period_id' => (int) $s->period_id]);

            $published = DB::table('class_routines as r')->join('classes as c', 'c.id', '=', 'r.class_id')->where('c.academic_year', 'like', "{$year}%")
                ->whereNotNull('r.published_slots')->whereRaw("JSON_CONTAINS(r.published_slots, JSON_OBJECT('teacher_id', ?, 'day', ?))", [$teacherId, (int) $day])
                ->get(['r.class_id', 'r.published_slots'])
                ->flatMap(fn ($r) => collect(json_decode($r->published_slots, true))
                    ->filter(fn ($s) => (int) ($s['teacher_id'] ?? 0) === $teacherId && (int) $s['day'] === (int) $day && ! empty($s['subject_id']))
                    ->map(fn ($s) => ['class_id' => (int) $r->class_id, 'subject_id' => (int) $s['subject_id'], 'period_id' => (int) $s['period_id']]));

            // only the classes that are open and run this weekday's routine today
            $out = $out->concat($draft->concat($published)->filter(fn ($s) => $this->academic()->routineDay($date, $s['class_id']) === (int) $day));
        }

        return $out->values();
    }

    private function academic(): AcademicCalendar
    {
        return app(AcademicCalendar::class);
    }

    /**
     * Everything the day's screen needs, in a fixed number of queries.
     */
    public function day(int $teacherId, string $date): array
    {
        $slots = $this->routineSlots($teacherId, $date);
        $logs = DB::table('teaching_logs')->where('log_date', $date)->where(fn ($q) => $q->where('teacher_id', $teacherId)->orWhere('substitute_id', $teacherId))->get();
        $year = substr($date, 0, 4);

        $plans = DB::table('teaching_plans')->where('teacher_id', $teacherId)->where('academic_year', 'like', "{$year}%")
            ->get(['id', 'class_id', 'subject_id', 'done_pct', 'expected_pct', 'health', 'gap']);

        // logs of another teacher's class (this one substituted) need that plan too
        $foreign = $logs->pluck('plan_id')->filter()->diff($plans->pluck('id'));
        $foreign->isNotEmpty() && $plans = $plans->concat(DB::table('teaching_plans')->whereIn('id', $foreign->all())->get(['id', 'class_id', 'subject_id', 'done_pct', 'expected_pct', 'health', 'gap']));

        $byClassSubject = $plans->keyBy(fn ($p) => "{$p->class_id}-{$p->subject_id}");
        $classIds = $slots->pluck('class_id')->merge($logs->pluck('class_id'))->merge($plans->pluck('class_id'))->unique()->all();
        $subjectIds = $slots->pluck('subject_id')->merge($logs->pluck('subject_id'))->merge($plans->pluck('subject_id'))->unique()->all();
        $classes = DB::table('classes')->whereIn('id', $classIds)->get(['id', 'class_name', 'class_title', 'section', 'section_title'])->keyBy('id');
        $subjects = DB::table('subjects')->whereIn('id', $subjectIds)->pluck('name', 'id');
        $periods = RoutinePeriod::whereIn('id', $slots->pluck('period_id')->merge($logs->pluck('period_id')->filter())->unique()->all())->get()->keyBy('id');
        $options = $this->options($plans->pluck('id')->all());

        // the last class logged before this date, per plan: for "copy last" and the note "next class"
        $lastIds = DB::table('teaching_logs')->whereIn('plan_id', $plans->pluck('id')->all())->where('log_date', '<', $date)->where('held', true)
            ->groupBy('plan_id')->selectRaw('MAX(id) id')->pluck('id');
        $last = DB::table('teaching_logs')->whereIn('id', $lastIds->all())->get()->keyBy('plan_id');

        $used = [];
        $card = function (int $classId, int $subjectId, ?int $periodId, ?object $log) use ($classes, $subjects, $periods, $byClassSubject, $options, $last, $teacherId) {
            $c = $classes[$classId] ?? null;
            $plan = $byClassSubject["{$classId}-{$subjectId}"] ?? null;
            $period = $periodId ? ($periods[$periodId] ?? null) : null;
            $prev = $plan ? ($last[$plan->id] ?? null) : null;

            return [
                'key' => $log ? "log-{$log->id}" : "slot-{$classId}-{$periodId}",
                'class_id' => $classId, 'subject_id' => $subjectId, 'period_id' => $periodId, 'teacher_id' => $log ? (int) $log->teacher_id : $teacherId,
                'class' => $c ? $this->rows->label($c) : '?', 'level' => $c?->class_name, 'subject' => $subjects[$subjectId] ?? '?',
                'period' => $period?->name, 'time' => $period ? RoutinePeriod::clock($period->start) : null, 'sort' => $period?->start ?? '99:99',
                'covering' => $log && (int) $log->teacher_id !== $teacherId,
                'plan' => $plan ? ['id' => (int) $plan->id, 'done' => (float) $plan->done_pct, 'expected' => (float) $plan->expected_pct, 'health' => $plan->health, 'gap' => (float) $plan->gap] : null,
                'suggest' => $plan ? $this->suggest($options[$plan->id] ?? []) : ['item_id' => null, 'topic_id' => null],
                'previous' => $prev ? $this->present($prev) : null,
                'log' => $log ? $this->present($log) : null,
            ];
        };

        $cards = [];

        foreach ($slots as $s) {
            $log = $logs->first(fn ($l) => (int) $l->class_id === $s['class_id'] && (int) $l->period_id === $s['period_id']);
            $log && $used[$log->id] = true;
            $cards[] = $card($s['class_id'], $s['subject_id'], $s['period_id'], $log);
        }

        foreach ($logs as $l) {
            isset($used[$l->id]) || $cards[] = $card((int) $l->class_id, (int) $l->subject_id, $l->period_id ? (int) $l->period_id : null, $l);
        }

        usort($cards, fn ($a, $b) => [$a['sort'], $a['class']] <=> [$b['sort'], $b['class']]);

        return [
            'cards' => $cards,
            'options' => (object) $options,
            // for an extra class: every plan of the teacher
            'extra' => $plans->reject(fn ($p) => $foreign->contains($p->id))->map(fn ($p) => ['plan_id' => (int) $p->id, 'class_id' => (int) $p->class_id, 'subject_id' => (int) $p->subject_id,
                'label' => ($subjects[$p->subject_id] ?? '?') . ' · ' . (isset($classes[$p->class_id]) ? $this->rows->label($classes[$p->class_id]) : '?')])->values()->all(),
        ];
    }

    private function present(object $l): array
    {
        return ['id' => (int) $l->id, 'date' => substr((string) $l->log_date, 0, 10), 'held' => (bool) $l->held, 'not_held_reason' => $l->not_held_reason, 'coverage' => (int) $l->coverage,
            'understanding' => $l->understanding, 'homework' => (bool) $l->homework, 'next_plan' => $l->next_plan, 'note' => $l->note, 'kind' => $l->kind,
            'item_id' => $l->item_id ? (int) $l->item_id : null, 'topic_id' => $l->topic_id ? (int) $l->topic_id : null, 'substitute_id' => $l->substitute_id ? (int) $l->substitute_id : null];
    }

    /**
     * The chapters and topics of each plan, with how far each topic got.
     *
     * @return array<int,array<int,array{id:int,chapter:string,progress:float,topics:array}>>
     */
    public function options(array $planIds): array
    {
        $items = DB::table('teaching_plan_items as i')->join('syllabus_chapters as c', 'c.id', '=', 'i.chapter_id')->whereIn('i.plan_id', $planIds)
            ->orderBy('i.position')->orderBy('i.id')->get(['i.id', 'i.plan_id', 'i.chapter_id', 'i.progress', 'c.name']);
        $topics = DB::table('syllabus_topics')->whereIn('chapter_id', $items->pluck('chapter_id')->unique()->all())->whereNull('deleted_at')
            ->orderBy('position')->orderBy('id')->get(['id', 'chapter_id', 'name'])->groupBy('chapter_id');
        $best = DB::table('teaching_logs')->whereIn('plan_id', $planIds)->where('held', true)->whereNotNull('topic_id')
            ->groupBy('item_id', 'topic_id')->selectRaw('item_id, topic_id, MAX(coverage) best')->get()->keyBy(fn ($r) => "{$r->item_id}-{$r->topic_id}");

        $out = [];

        foreach ($items as $i) {
            $out[$i->plan_id][] = ['id' => (int) $i->id, 'chapter' => $i->name, 'progress' => (float) $i->progress,
                'topics' => ($topics[$i->chapter_id] ?? collect())->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'best' => (int) ($best["{$i->id}-{$t->id}"]->best ?? 0)])->values()->all()];
        }

        return $out;
    }

    /** the next unfinished chapter and topic */
    public function suggest(array $items): array
    {
        foreach ($items as $i) {
            if ($i['progress'] >= 100) {
                continue;
            }

            foreach ($i['topics'] as $t) {
                if ($t['best'] < 100) {
                    return ['item_id' => $i['id'], 'topic_id' => $t['id']];
                }
            }

            return ['item_id' => $i['id'], 'topic_id' => null];
        }

        return ['item_id' => null, 'topic_id' => null];
    }

    /**
     * Save one class. Works as "create or update": the same class period of the same date is one log.
     *
     * @return array{log:array, plan:?array, options:?array}
     */
    public function save(User $user, array $d): array
    {
        $today = Calendar::today();
        $existing = ! empty($d['id']) ? TeachingLog::findOrFail($d['id'])
            : (! empty($d['period_id']) ? TeachingLog::where('log_date', $d['log_date'])->where('class_id', $d['class_id'])->where('period_id', $d['period_id'])->first() : null);

        // the identity of an existing log cannot be changed by the request
        if ($existing) {
            $d = ['log_date' => $existing->log_date->toDateString(), 'class_id' => $existing->class_id, 'subject_id' => $existing->subject_id,
                'period_id' => $existing->period_id, 'teacher_id' => $existing->teacher_id] + $d;
        }

        $teacherId = (int) $d['teacher_id'];
        $date = $d['log_date'];

        abort_unless(Access::writeFor($user, $teacherId), 403);

        if ($date > $today) {
            $this->fail('future_date');
        }

        if (! $this->editable($user, $date, $today)) {
            $this->fail('too_old', ['days' => Settings::get('edit_window_days')]);
        }

        $periodId = $d['period_id'] ?? null;
        $classId = (int) $d['class_id'];
        $subjectId = (int) $d['subject_id'];

        if (! $existing) {
            // a routine period must be this teacher's on that day; an extra class one they teach (managers may record any)
            $mine = $periodId
                ? $this->routineSlots($teacherId, $date)->contains(fn ($s) => $s['class_id'] === $classId && $s['subject_id'] === $subjectId && $s['period_id'] === (int) $periodId)
                : TeachingPlan::where(['teacher_id' => $teacherId, 'class_id' => $classId, 'subject_id' => $subjectId])->exists() || $this->rows->assigned($teacherId, $classId, $subjectId);

            if ($periodId && ! $this->academic()->isWorkingDay($date, $classId)) {
                $this->fail('school_closed', ['reason' => $this->academic()->day($date, $classId)['reason']]);
            }

            if (! $mine && ! $user->canDo('teaching.manage_all')) {
                $this->fail('not_your_class');
            }
        }

        $held = (bool) ($d['held'] ?? true);
        $plan = TeachingPlan::where(['teacher_id' => $teacherId, 'class_id' => $classId, 'subject_id' => $subjectId])->first();
        $itemId = $plan && ! empty($d['item_id']) ? DB::table('teaching_plan_items')->where('id', $d['item_id'])->where('plan_id', $plan->id)->value('id') : null;
        $topicId = $itemId && ! empty($d['topic_id'])
            ? DB::table('syllabus_topics as t')->join('teaching_plan_items as i', 'i.chapter_id', '=', 't.chapter_id')->where('i.id', $itemId)->where('t.id', $d['topic_id'])->value('t.id') : null;

        if ((! empty($d['item_id']) && ! $itemId) || (! empty($d['topic_id']) && ! $topicId)) {
            $this->fail('wrong_topic');
        }

        $substitute = ! empty($d['substitute_id']) ? (int) $d['substitute_id'] : null;

        if ($substitute && ($substitute === $teacherId || ! User::whereKey($substitute)->where('role', User::ROLE_TEACHER)->exists())) {
            $this->fail('bad_substitute');
        }

        if (! $held && ! trim((string) ($d['not_held_reason'] ?? ''))) {
            $this->fail('reason_needed');
        }

        $data = [
            'log_date' => $date, 'class_id' => $classId, 'subject_id' => $subjectId, 'teacher_id' => $teacherId, 'period_id' => $periodId,
            'kind' => $periodId ? 'regular' : 'extra', 'plan_id' => $plan?->id, 'item_id' => $itemId, 'topic_id' => $topicId,
            'substitute_id' => $substitute, 'held' => $held, 'not_held_reason' => $held ? null : trim($d['not_held_reason']),
            'coverage' => $held ? (int) ($d['coverage'] ?? 0) : 0, 'understanding' => $held ? ($d['understanding'] ?? null) : null,
            'homework' => $held && ! empty($d['homework']), 'next_plan' => $d['next_plan'] ?? null, 'note' => $d['note'] ?? null,
        ];

        $log = DB::transaction(function () use ($existing, $data, $user, $date, $today, $periodId) {
            $log = $existing;

            if (! $log) {
                try {
                    $log = TeachingLog::create($data + ['created_by' => $user->id]);
                } catch (UniqueConstraintViolationException) {
                    // two taps at once: the other one made the row
                    $log = TeachingLog::where('log_date', $date)->where('class_id', $data['class_id'])->where('period_id', $periodId)->lockForUpdate()->firstOrFail();
                    $log->update($data);
                }
            } else {
                $log->fill($data);
            }

            if ($date < $today && ($log->wasRecentlyCreated || $log->isDirty())) {
                $changes = $log->wasRecentlyCreated ? ['created' => true] : collect($log->getDirty())->except('log_date')->all();
                AuditLog::record($log, 'log.past_edit', $changes + ['date' => $date]);
            }

            $log->save();

            return $log;
        });

        Insights::touch();

        return ['log' => $this->present($log->refresh())] + $this->after($plan, $existing?->plan_id);
    }

    /** refresh the plan the screen shows */
    private function after(?TeachingPlan $plan, ?int $otherPlanId = null): array
    {
        if ($otherPlanId && $otherPlanId !== $plan?->id && ($old = TeachingPlan::find($otherPlanId))) {
            $this->progress->recompute($old);
        }

        if (! $plan) {
            return ['plan' => null, 'options' => null];
        }

        $this->progress->recompute($plan);
        $options = $this->options([$plan->id])[$plan->id] ?? [];

        return ['plan' => ['id' => $plan->id, 'done' => $plan->done_pct, 'expected' => $plan->expected_pct, 'health' => $plan->health, 'gap' => $plan->gap],
            'options' => $options];
    }

    /** undo or remove a log */
    public function delete(User $user, TeachingLog $log): array
    {
        abort_unless(Access::writeFor($user, $log->teacher_id), 403);
        $date = $log->log_date->toDateString();

        if (! $this->editable($user, $date)) {
            $this->fail('too_old', ['days' => Settings::get('edit_window_days')]);
        }

        $plan = $log->plan_id ? TeachingPlan::find($log->plan_id) : null;

        DB::transaction(function () use ($log, $date) {
            if ($date < Calendar::today()) {
                AuditLog::record($log, 'log.past_delete', ['date' => $date, 'coverage' => $log->coverage]);
            }

            $log->delete();
        });
        Insights::touch();

        return $this->after($plan);
    }

    /**
     * "Same for all sections": record what was saved for one class on other classes of the same teacher and subject.
     * Chapters and topics are matched through the syllabus, so each section's own plan is used.
     *
     * @param  array<int,array{class_id:int,period_id:?int}>  $targets
     */
    public function copyTo(User $user, TeachingLog $source, array $targets): array
    {
        abort_unless(Access::writeFor($user, $source->teacher_id), 403);
        $chapterId = $source->item_id ? DB::table('teaching_plan_items')->where('id', $source->item_id)->value('chapter_id') : null;
        $saved = [];

        foreach ($targets as $t) {
            $planId = DB::table('teaching_plans')->where(['teacher_id' => $source->teacher_id, 'class_id' => $t['class_id'], 'subject_id' => $source->subject_id])->value('id');
            $itemId = $planId && $chapterId ? DB::table('teaching_plan_items')->where('plan_id', $planId)->where('chapter_id', $chapterId)->value('id') : null;

            $saved[] = ['class_id' => (int) $t['class_id'], 'period_id' => $t['period_id'] ?? null] + $this->save($user, [
                'log_date' => $source->log_date->toDateString(), 'class_id' => (int) $t['class_id'], 'subject_id' => $source->subject_id, 'teacher_id' => $source->teacher_id,
                'period_id' => $t['period_id'] ?? null, 'item_id' => $itemId, 'topic_id' => $itemId ? $source->topic_id : null, 'held' => $source->held,
                'not_held_reason' => $source->not_held_reason, 'coverage' => $source->coverage, 'understanding' => $source->understanding,
                'homework' => $source->homework, 'next_plan' => $source->next_plan, 'note' => $source->note, 'substitute_id' => $source->substitute_id,
            ]);
        }

        return $saved;
    }
}
