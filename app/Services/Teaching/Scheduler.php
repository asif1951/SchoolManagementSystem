<?php

namespace App\Services\Teaching;

use App\Models\AuditLog;
use App\Models\Teaching\TeachingPlan;
use App\Services\RoutineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Auto-schedule: the chapters, in order, share the teaching days of the chosen range in proportion to their planned
 * periods. The routine says how many periods a week the subject has; holidays and exams are not teaching days.
 */
class Scheduler
{
    public function __construct(private Calendar $calendar, private RoutineService $routines, private PeriodsPerWeek $perWeek, private Progress $progress)
    {
    }

    /**
     * @return array{scheduled:int, days:int, needed:int, available:int, per_week:int, enough:bool}
     */
    public function auto(TeachingPlan $plan, ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->calendar->session($plan->academic_year);
        $from = max($from ?: $start, $start);
        $to = min($to ?: $end, $end);
        $days = $this->calendar->teachingDays($from, $to);
        $items = $plan->items()->get();

        // chapters already finished keep their dates: only the rest is spread
        $open = $items->filter(fn ($i) => $i->progress < 100)->values();

        if (! $days || $open->isEmpty()) {
            throw ValidationException::withMessages(['schedule' => $open->isEmpty() ? __('teaching.msg.nothing_to_schedule') : __('teaching.msg.no_teaching_days')]);
        }

        $perWeek = $this->perWeek->for($plan->class_id, $plan->subject_id);
        $available = (int) floor(count($days) * $perWeek / max(1, count($this->routines->workingDays())));
        $weights = $open->map(fn ($i) => max(1, $i->planned_periods))->all();
        $shares = $this->shareOut(count($days), $weights);

        DB::transaction(function () use ($plan, $open, $days, $shares) {
            $at = 0;
            $total = count($days);

            foreach ($open as $k => $item) {
                $first = min($at, $total - 1);
                $last = min(max($first, $at + $shares[$k] - 1), $total - 1);
                $item->update(['starts_on' => $days[$first], 'ends_on' => $days[$last]]);
                $at += $shares[$k];
            }

            AuditLog::record($plan, 'plan.scheduled', ['chapters' => $open->count(), 'from' => $days[0], 'to' => end($days)]);
        });

        $this->progress->recompute($plan);
        $needed = array_sum($weights);

        return ['scheduled' => $open->count(), 'days' => count($days), 'needed' => $needed, 'available' => $available, 'per_week' => $perWeek, 'enough' => $needed <= $available];
    }

    /** split $total days by weight so that they add up exactly (largest remainder), at least one each while days last */
    private function shareOut(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $exact = array_map(fn ($w) => $total * $w / $sum, $weights);
        $shares = array_map(fn ($x) => max(1, (int) floor($x)), $exact);
        $left = $total - array_sum($shares);

        $order = array_keys($weights);
        usort($order, fn ($a, $b) => ($exact[$b] - floor($exact[$b])) <=> ($exact[$a] - floor($exact[$a])) ?: $a <=> $b);

        for ($i = 0; $left > 0; $i++, $left--) {
            $shares[$order[$i % count($order)]]++;
        }

        while ($left < 0) {
            $big = array_search(max($shares), $shares, true);

            if ($shares[$big] <= 1) {
                break;                                  // more chapters than days: the last ones share the final day
            }

            $shares[$big]--;
            $left++;
        }

        return $shares;
    }
}
