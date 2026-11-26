<?php

namespace App\Services\Teaching;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Weekly and monthly plans are not stored: they are the part of each Yearly Plan that falls in the range, with how far
 * each chapter should be by the end of it. Changing a date here changes the Yearly Plan, because it is the same record.
 */
class PeriodView
{
    public function __construct(private Calendar $calendar, private Progress $progress, private Rows $rows)
    {
    }

    /** @return array{0:string,1:string} the week (Saturday to Friday), month or session holding the date */
    public function range(string $mode, string $date, ?string $year = null): array
    {
        $d = Carbon::parse($date);

        return match ($mode) {
            'week' => [($from = $d->copy()->startOfWeek(Carbon::SATURDAY))->toDateString(), $from->copy()->addDays(6)->toDateString()],
            'year' => $this->calendar->session($year ?? (string) $d->year),
            default => [$d->copy()->startOfMonth()->toDateString(), $d->copy()->endOfMonth()->toDateString()],
        };
    }

    /**
     * @return array<int,array{id:int,class:string,subject:string,teacher:string,items:array}>  one entry per plan with chapters in the range
     */
    public function plans(string $year, array $filters, string $from, string $to, int $limit = 60): array
    {
        $plans = collect($this->rows->present($this->rows->plans($year, $filters)->orderBy('c.class_name')->orderBy('c.section')->orderBy('s.name')->limit($limit)->get()->all()))->keyBy('id');

        $items = DB::table('teaching_plan_items as i')->join('syllabus_chapters as c', 'c.id', '=', 'i.chapter_id')
            ->whereIn('i.plan_id', $plans->keys()->all())->where('i.starts_on', '<=', $to)->where('i.ends_on', '>=', $from)
            ->orderBy('i.starts_on')->orderBy('i.position')
            ->get(['i.id', 'i.plan_id', 'i.starts_on', 'i.ends_on', 'i.planned_periods', 'i.progress', 'c.name'])->groupBy('plan_id');

        [$a, $b] = $this->calendar->session($year);
        $days = $this->calendar->teachingDays($a, $b);
        $today = Calendar::today();

        return $plans->filter(fn ($p) => isset($items[$p['id']]))->map(function ($p) use ($items, $days, $from, $to, $today) {
            $p['items'] = $items[$p['id']]->map(function ($i) use ($days, $from, $to, $today) {
                $start = substr($i->starts_on, 0, 10);
                $end = substr($i->ends_on, 0, 10);
                $byStart = $from > $start ? $this->progress->share($days, $start, $end, Carbon::parse($from)->subDay()->toDateString()) : 0;
                $byEnd = $this->progress->share($days, $start, $end, $to);

                return ['id' => (int) $i->id, 'chapter' => $i->name, 'starts_on' => $start, 'ends_on' => $end,
                    // the periods that fall in this range, and how far the chapter should be when it ends
                    'periods' => (int) round($i->planned_periods * ($byEnd - $byStart)), 'expected' => (int) round($byEnd * 100), 'progress' => (float) $i->progress,
                    'status' => $i->progress >= 100 ? 'completed' : ($end < $today ? 'delayed' : ($start <= $today ? 'running' : 'upcoming'))];
            })->values()->all();

            return $p;
        })->values()->all();
    }
}
