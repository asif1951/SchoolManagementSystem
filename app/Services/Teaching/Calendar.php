<?php

namespace App\Services\Teaching;

use App\Services\AcademicCalendar;
use Illuminate\Support\Carbon;

/**
 * Teaching days, as the Academic Calendar gives them (working days without exam days).
 * Dates are plain 'Y-m-d' strings, which compare correctly and cost nothing.
 */
class Calendar
{
    /** @var array<string,array<int,string>> */
    private array $cache = [];

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

    /** @return array<int,string> every teaching day between the two dates (for one class, or the school) */
    public function teachingDays(string $from, string $to, ?int $classId = null): array
    {
        return $this->cache["{$from}|{$to}|{$classId}"] ??= $this->academic()->teachingDays($from, $to, $classId);
    }

    /** holidays, closures and exams that overlap the range and concern the school or this class: [[from, to, title, kind]] */
    public function closedRanges(string $from, string $to, ?int $classId = null): array
    {
        return collect($this->academic()->events($from, $to))
            ->filter(fn ($e) => ($e['off'] || $e['type'] === 'exam') && ($e['audience'] === 'all' || ($classId && in_array($classId, $e['class_ids'], true))))
            ->map(fn ($e) => [$e['starts_on'], $e['ends_on'], $e['title'], $e['type'] === 'exam' ? 'exam' : 'holiday'])->values()->all();
    }

    /** asked for when needed: the calendar tells this class to forget when dates change */
    private function academic(): AcademicCalendar
    {
        return app(AcademicCalendar::class);
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
