<?php

namespace App\Services\Teaching;

use App\Services\AcademicCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * How many periods a subject really has in a class in an academic year: every teaching day of the Academic Calendar
 * (holidays, closures, weekly off days and exam days are not teaching days; a make-up day follows its weekday) times
 * the periods the class's PUBLISHED routine gives the subject on that weekday.
 *
 * Without a published routine that has the subject there is no figure: the screen asks for the routine to be published,
 * and nothing is limited.
 *
 * Cost: two small queries (the routine and the calendar's events).
 */
class PeriodBudget
{
    public function __construct(private Calendar $calendar, private AcademicCalendar $academic)
    {
    }

    /** @return array{total:?int,per_week:int,days:int} */
    public function for(int $classId, int $subjectId, string $year): array
    {
        $weekdays = [];

        foreach (json_decode((string) DB::table('class_routines')->where('class_id', $classId)->value('published_slots'), true) ?: [] as $s) {
            if ((int) ($s['subject_id'] ?? 0) === $subjectId) {
                $weekdays[(int) $s['day']] = ($weekdays[(int) $s['day']] ?? 0) + 1;
            }
        }

        if (! $weekdays) {
            return ['total' => null, 'per_week' => 0, 'days' => 0];
        }

        [$from, $to] = $this->calendar->session($year);
        $days = $this->academic->teachingWeekdays($from, $to, $classId);          // date => the weekday whose routine runs
        $total = 0;

        foreach ($days as $weekday) {
            $total += $weekdays[$weekday] ?? 0;
        }

        return ['total' => $total ?: null, 'per_week' => array_sum($weekdays), 'days' => count($days)];
    }

    /**
     * A syllabus serves every section of a class level, and sections have the same number of classes in a subject, so
     * one section answers for all: the first (A, B, …) whose published routine has the subject.
     *
     * @return array{limit:?int,section:?string}
     */
    public function forLevel(string $year, string $className, int $subjectId): array
    {
        $class = DB::table('classes as c')->join('class_routines as r', 'r.class_id', '=', 'c.id')
            ->where('c.academic_year', $year)->where('c.class_name', $className)->where('c.status', 'active')
            ->whereNotNull('r.published_slots')->whereRaw("JSON_CONTAINS(r.published_slots, JSON_OBJECT('subject_id', ?))", [$subjectId])
            ->orderBy('c.section')->orderBy('c.id')->first(['c.id', 'c.section']);
        $limit = $class ? $this->for((int) $class->id, $subjectId, $year)['total'] : null;

        return ['limit' => $limit, 'section' => $limit === null ? null : $class->section];
    }

    /** stop a save that would plan more periods than the year holds */
    public function guard(int $planned, ?int $limit, string $year, string $field = 'periods'): void
    {
        if ($limit !== null && $planned > $limit) {
            throw ValidationException::withMessages([$field => __('teaching.budget.over', ['limit' => $limit, 'year' => $year, 'planned' => $planned, 'extra' => $planned - $limit])]);
        }
    }
}
