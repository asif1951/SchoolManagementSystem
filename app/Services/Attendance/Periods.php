<?php

namespace App\Services\Attendance;

use App\Services\AcademicCalendar;
use Illuminate\Support\Carbon;

/**
 * Reporting periods. Half-yearly and term periods come from the Academic Calendar's terms; without terms, from the
 * periods set in the settings; without those, the year's working days split in two equal halves.
 */
class Periods
{
    public function __construct(private AcademicCalendar $calendar)
    {
    }

    /** @return array{0:string,1:string} the academic session of a year */
    public function session(string $year): array
    {
        $y = substr($year, 0, 4);

        return ["{$y}-01-01", "{$y}-12-31"];
    }

    /** @return array<int,array{key:string,name:string,from:string,to:string}> terms, configured periods or halves */
    public function parts(string $year): array
    {
        [$start, $end] = $this->session($year);
        $out = [];

        foreach ($this->calendar->events($start, $end) as $e) {
            if ($e['type'] === 'term') {
                $out[] = ['key' => "term:{$e['id']}", 'name' => $e['title'], 'from' => max($e['starts_on'], $start), 'to' => min($e['ends_on'], $end)];
            }
        }

        if ($out) {
            return $out;
        }

        foreach (Settings::get('report_periods') as $i => $p) {
            $y = substr($start, 0, 4);
            $out[] = ['key' => "part:{$i}", 'name' => $p['name'], 'from' => "{$y}-{$p['from']}", 'to' => "{$y}-{$p['to']}"];
        }

        if ($out) {
            return $out;
        }

        $days = $this->calendar->workingDays($start, $end);

        if (count($days) < 2) {
            return [];
        }

        $middle = $days[intdiv(count($days), 2)];

        return [
            ['key' => 'half:1', 'name' => __('attendance.half_first'), 'from' => $start, 'to' => Carbon::parse($middle)->subDay()->toDateString()],
            ['key' => 'half:2', 'name' => __('attendance.half_second'), 'from' => $middle, 'to' => $end],
        ];
    }

    /**
     * @param  string  $period  day | week | month | half (or a part's key) | year | custom
     * @return array{from:string,to:string,label:string}
     */
    public function resolve(string $period, string $year, ?string $date = null, ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->session($year);
        $today = AcademicCalendar::today();
        $date = $date ?: ($today >= $start && $today <= $end ? $today : $end);
        $d = Carbon::parse($date);

        [$a, $b, $label] = match (true) {
            $period === 'day' => [$date, $date, __('attendance.period.day')],
            $period === 'week' => [$d->copy()->startOfWeek(Carbon::SATURDAY)->toDateString(), $d->copy()->startOfWeek(Carbon::SATURDAY)->addDays(6)->toDateString(), __('attendance.period.week')],
            $period === 'month' => [$d->copy()->startOfMonth()->toDateString(), $d->copy()->endOfMonth()->toDateString(), __('attendance.period.month')],
            $period === 'custom' && $from && $to => [min($from, $to), max($from, $to), __('attendance.period.custom')],
            $period === 'year' => [$start, $end, __('attendance.period.year')],
            default => $this->part($period, $year, $date),
        };

        return ['from' => $a, 'to' => $b, 'label' => $label];
    }

    private function part(string $period, string $year, string $date): array
    {
        $parts = $this->parts($year);
        $found = collect($parts)->firstWhere('key', $period)
            ?? collect($parts)->first(fn ($p) => $date >= $p['from'] && $date <= $p['to'])
            ?? ($parts[0] ?? null);

        if (! $found) {
            [$a, $b] = $this->session($year);

            return [$a, $b, __('attendance.period.year')];
        }

        return [$found['from'], $found['to'], $found['name']];
    }
}
