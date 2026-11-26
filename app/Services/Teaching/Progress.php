<?php

namespace App\Services\Teaching;

use App\Models\Teaching\TeachingPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planned against actual, the heart of the module.
 *
 *  chapter done     marked complete → 100; with topics: the average of the best coverage logged for each topic;
 *                   without topics: the periods taught (coverage added up) against the planned periods
 *  chapter expected the share of its teaching days (up to today) that have passed
 *  plan figures     both weighted by planned periods; gap = done − expected
 *  health           green (not behind by more than amber_gap), amber, red (behind by more than red_gap), none (no dates)
 *  projected finish the pace so far, carried forward over the coming teaching days
 *
 * The figures are cached on the plan row, so lists and dashboards never add up logs.
 */
class Progress
{
    /** up to this many stale plans are refreshed while a screen waits */
    private const AT_ONCE = 60;

    public function __construct(private Calendar $calendar)
    {
    }

    public function recompute(TeachingPlan $plan, ?string $today = null): TeachingPlan
    {
        $this->recomputeMany(collect([$plan]), $today);

        return $plan;
    }

    /** many plans with a handful of queries per batch, not per plan */
    public function recomputeMany(Collection $plans, ?string $today = null): void
    {
        if ($plans->isEmpty()) {
            return;
        }

        $today ??= Calendar::today();
        $ids = $plans->pluck('id')->all();
        $items = DB::table('teaching_plan_items')->whereIn('plan_id', $ids)->get(['id', 'plan_id', 'chapter_id', 'starts_on', 'ends_on', 'planned_periods', 'progress', 'completed_on'])->groupBy('plan_id');
        $topics = DB::table('syllabus_topics')->whereIn('chapter_id', $items->flatten(1)->pluck('chapter_id')->unique()->all())->whereNull('deleted_at')
            ->get(['id', 'chapter_id'])->groupBy('chapter_id');
        $logs = DB::table('teaching_logs')->whereIn('plan_id', $ids)->where('held', true)->whereNotNull('item_id')
            ->groupBy('plan_id', 'item_id', 'topic_id')->selectRaw('plan_id, item_id, topic_id, MAX(coverage) best, SUM(coverage) total, MAX(log_date) last_on')->get()->groupBy('item_id');
        $last = DB::table('teaching_logs')->whereIn('plan_id', $ids)->groupBy('plan_id')->selectRaw('plan_id, MAX(log_date) d')->pluck('d', 'plan_id');
        $settings = Settings::all();
        $changed = false;

        foreach ($plans as $plan) {
            $mine = $items[$plan->id] ?? collect();
            [$from, $to] = $this->calendar->session($plan->academic_year);
            $days = $this->calendar->teachingDays($from, $to, $plan->class_id);
            $weight = 0;
            $done = 0.0;
            $expected = 0.0;
            $delayed = 0;
            $firstStart = null;

            foreach ($mine as $i) {
                $progress = $this->itemProgress($i, $topics[$i->chapter_id] ?? collect(), $logs[$i->id] ?? collect());

                if (abs($progress - (float) $i->progress) > 0.001) {
                    DB::table('teaching_plan_items')->where('id', $i->id)->update(['progress' => $progress, 'updated_at' => now()]);
                }

                $w = max(1, (int) $i->planned_periods);
                $weight += $w;
                $done += $progress * $w;

                if ($i->starts_on && $i->ends_on) {
                    $expected += $this->share($days, $i->starts_on, $i->ends_on, $today) * 100 * $w;
                    $firstStart = $firstStart === null ? $i->starts_on : min($firstStart, $i->starts_on);
                    $progress < 100 && $i->ends_on < $today && $delayed++;
                }
            }

            $donePct = $weight ? round($done / $weight, 2) : 0;
            $expectedPct = $weight ? round($expected / $weight, 2) : 0;
            $gap = round($donePct - $expectedPct, 2);
            $health = match (true) {
                $firstStart === null => 'none',
                $gap >= -$settings['amber_gap'] => 'green',
                $gap >= -$settings['red_gap'] => 'amber',
                default => 'red',
            };

            // a calendar change clears this mark in the database while the model in hand still holds the old one
            $plan->summary_on = null;
            $plan->syncOriginalAttribute('summary_on');

            $plan->fill([
                'chapters' => $mine->count(), 'delayed' => $delayed, 'planned_periods' => (int) $mine->sum('planned_periods'),
                'done_pct' => $donePct, 'expected_pct' => $expectedPct, 'gap' => $health === 'none' ? 0 : $gap, 'health' => $health,
                'projected_finish' => $this->projectedFinish($donePct, $firstStart, $last[$plan->id] ?? null, $today, $plan->class_id),
                'last_log_on' => $last[$plan->id] ?? null, 'summary_on' => $today,
            ]);

            $changed = $changed || count($plan->getDirty()) > 1;

            if ($plan->isDirty()) {
                $plan->timestamps = false;
                $plan->save();
                $plan->timestamps = true;
            }
        }

        $changed && Insights::touch();
    }

    private function itemProgress(object $item, Collection $topics, Collection $logs): float
    {
        if ($item->completed_on) {
            return 100;
        }

        if ($topics->isNotEmpty()) {
            $best = $logs->whereNotNull('topic_id')->pluck('best', 'topic_id');

            return round($topics->sum(fn ($t) => (int) ($best[$t->id] ?? 0)) / $topics->count(), 2);
        }

        return round(min(100, $logs->sum('total') / max(1, (int) $item->planned_periods)), 2);
    }

    /** 0..1: how much of the chapter's teaching days have come, counting today */
    public function share(array $days, string $from, string $to, string $today): float
    {
        if ($today < $from) {
            return 0;
        }

        if ($today >= $to) {
            return 1;
        }

        $all = $this->upTo($days, $to) - $this->upTo($days, $from, false);

        return $all > 0 ? ($this->upTo($days, $today) - $this->upTo($days, $from, false)) / $all : 0;
    }

    /** how many of the sorted days are <= $date (or < $date) */
    private function upTo(array $days, string $date, bool $inclusive = true): int
    {
        $lo = 0;
        $hi = count($days);

        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            ($days[$mid] < $date || ($inclusive && $days[$mid] === $date)) ? $lo = $mid + 1 : $hi = $mid;
        }

        return $lo;
    }

    private function projectedFinish(float $done, ?string $start, ?string $lastLog, string $today, int $classId): ?string
    {
        if ($done >= 100) {
            return $lastLog ?? $today;
        }

        if (! $start || $done <= 0 || $today < $start) {
            return null;
        }

        $elapsed = count($this->calendar->teachingDays($start, $today, $classId));

        if ($elapsed < 1) {
            return null;
        }

        $need = (int) ceil((100 - $done) / ($done / $elapsed));

        if ($need > 700) {
            return null;                               // so slow that a date would mean nothing
        }

        $ahead = $this->calendar->teachingDays(Carbon::parse($today)->addDay()->toDateString(), Carbon::parse($today)->addDays(1000)->toDateString(), $classId);

        return $ahead[$need - 1] ?? null;
    }

    /** "expected by today" moves every day: bring the session's plans up to date (called before dashboards and reports) */
    public function refreshStale(string $year, ?string $today = null, int $limit = PHP_INT_MAX): int
    {
        $today ??= Calendar::today();
        $n = 0;

        while ($n < $limit) {
            $batch = TeachingPlan::where('academic_year', $year)->where(fn ($q) => $q->whereNull('summary_on')->orWhere('summary_on', '<', $today))
                ->orderBy('id')->limit(min(250, $limit - $n))->get();

            if ($batch->isEmpty()) {
                break;
            }

            $this->recomputeMany($batch, $today);
            $n += $batch->count();
        }

        return $n;
    }

    /**
     * Called by screens: a few stale plans are worked out at once; many are handed to the queue (the screen shows the
     * figures it has, which are at most a day old).
     */
    public function ensureFresh(string $year, ?string $today = null): void
    {
        $today ??= Calendar::today();
        $stale = $this->staleCount($year, $today);

        if ($stale > 0 && $stale <= self::AT_ONCE) {
            $this->refreshStale($year, $today);
        } elseif ($stale > self::AT_ONCE && \Illuminate\Support\Facades\Cache::add("teaching.refresh.{$year}.{$today}", true, 600)) {
            \App\Jobs\RefreshTeachingProgress::dispatch($year);
        }
    }

    public function staleCount(string $year, ?string $today = null): int
    {
        $today ??= Calendar::today();

        return TeachingPlan::where('academic_year', $year)->where(fn ($q) => $q->whereNull('summary_on')->orWhere('summary_on', '<', $today))->count();
    }
}
