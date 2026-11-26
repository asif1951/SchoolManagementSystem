<?php

namespace App\Services\Teaching;

use App\Models\Exam;
use App\Models\Teaching\Holiday;
use App\Services\RoutineService;
use Illuminate\Support\Carbon;

/**
 * Which days teaching happens: the routine's working days, minus holidays, minus exam days.
 * Dates are plain 'Y-m-d' strings, which compare correctly and cost nothing.
 */
class Calendar
{
    /** @var array<string,array<int,string>> */
    private array $cache = [];

    public function __construct(private RoutineService $routines)
    {
    }

    /** today in the school's own time zone */
    public static function today(): string
    {
        return now(Settings::get('timezone') ?: 'Asia/Dhaka')->toDateString();
    }

    /** @return array{0:string,1:string} the teaching session of an academic year */
    public function session(string $year): array
    {
        $y = preg_match('/^\d{4}/', $year) ? substr($year, 0, 4) : substr(self::today(), 0, 4);

        return ["{$y}-01-01", "{$y}-12-31"];
    }

    /** @return array<int,string> every teaching day between the two dates, in order */
    public function teachingDays(string $from, string $to): array
    {
        $key = "{$from}|{$to}";

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if ($to < $from || Carbon::parse($from)->diffInDays($to) > 1100) {
            return [];
        }

        $working = array_flip($this->routines->workingDays());
        $closed = $this->closedRanges($from, $to);
        $days = [];

        for ($d = Carbon::parse($from); ($s = $d->toDateString()) <= $to; $d->addDay()) {
            if (! isset($working[$d->dayOfWeek])) {
                continue;
            }

            foreach ($closed as [$a, $b]) {
                if ($s >= $a && $s <= $b) {
                    continue 2;
                }
            }

            $days[] = $s;
        }

        return $this->cache[$key] = $days;
    }

    /** holidays and exams that overlap the range: [[from, to, title, kind]] */
    public function closedRanges(string $from, string $to): array
    {
        $out = Holiday::where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->orderBy('starts_on')->get()
            ->map(fn ($h) => [$h->starts_on->toDateString(), $h->ends_on->toDateString(), $h->title, 'holiday'])->all();

        foreach (Exam::whereNotNull('starts_on')->where('starts_on', '<=', $to)->get(['name', 'starts_on', 'ends_on']) as $e) {
            $end = ($e->ends_on ?? $e->starts_on)->toDateString();
            $end >= $from && $out[] = [$e->starts_on->toDateString(), $end, $e->name, 'exam'];
        }

        return $out;
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
