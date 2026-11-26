<?php

namespace App\Services\Teaching;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for dashboards, progress and reports. School-wide numbers come from the cached figures on the plan rows;
 * they are cached briefly and the cache key changes whenever a plan or a log changes.
 */
class Insights
{
    private const VERSION = 'teaching.version';

    public function __construct(private Rows $rows, private DailyLog $log, private Calendar $calendar)
    {
    }

    /** call when anything a dashboard counts has changed */
    public static function touch(): void
    {
        Cache::forever(self::VERSION, microtime(true));
    }

    private function remember(string $key, \Closure $make): mixed
    {
        return Cache::remember("teaching.{$key}." . Cache::get(self::VERSION, 0), 120, $make);
    }

    /** how the session stands: plans by health, weighted completion, delayed chapters */
    public function summary(string $year, ?int $teacherId = null): array
    {
        return $this->remember("summary.{$year}.{$teacherId}", function () use ($year, $teacherId) {
            $r = DB::table('teaching_plans')->where('academic_year', $year)->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
                ->selectRaw("COUNT(*) plans, COALESCE(SUM(health = 'green'), 0) green, COALESCE(SUM(health = 'amber'), 0) amber, COALESCE(SUM(health = 'red'), 0) red, COALESCE(SUM(health = 'none'), 0) none,
                             COALESCE(SUM(`delayed`), 0) delayed_chapters, COALESCE(SUM(status = 'submitted'), 0) submitted,
                             COALESCE(SUM(done_pct * planned_periods) / NULLIF(SUM(planned_periods), 0), 0) done,
                             COALESCE(SUM(expected_pct * planned_periods) / NULLIF(SUM(planned_periods), 0), 0) expected")->first();

            return ['plans' => (int) $r->plans, 'green' => (int) $r->green, 'amber' => (int) $r->amber, 'red' => (int) $r->red, 'none' => (int) $r->none,
                'delayed' => (int) $r->delayed_chapters, 'submitted' => (int) $r->submitted, 'done' => round((float) $r->done, 1), 'expected' => round((float) $r->expected, 1)];
        });
    }

    /** completion per subject or per class of the session */
    public function completion(string $year, string $by = 'subject', ?int $teacherId = null): array
    {
        return $this->remember("completion.{$year}.{$by}.{$teacherId}", function () use ($year, $by, $teacherId) {
            $q = DB::table('teaching_plans as p')->where('p.academic_year', $year)->when($teacherId, fn ($q) => $q->where('p.teacher_id', $teacherId));
            $figures = 'COUNT(*) plans, SUM(p.`delayed`) delayed_chapters, COALESCE(SUM(p.done_pct * p.planned_periods) / NULLIF(SUM(p.planned_periods), 0), 0) done,
                        COALESCE(SUM(p.expected_pct * p.planned_periods) / NULLIF(SUM(p.planned_periods), 0), 0) expected';

            if ($by === 'subject') {
                $rows = $q->join('subjects as s', 's.id', '=', 'p.subject_id')->groupBy('p.subject_id', 's.name')->selectRaw("s.name as name, p.subject_id as id, {$figures}")->orderBy('s.name')->get();
            } else {
                $rows = $q->join('classes as c', 'c.id', '=', 'p.class_id')->groupBy('c.id', 'c.class_name', 'c.class_title', 'c.section', 'c.section_title')
                    ->selectRaw("c.id as id, c.class_name, c.class_title, c.section, c.section_title, {$figures}")->get()
                    ->sort(fn ($a, $b) => strnatcasecmp($a->class_name, $b->class_name) ?: strnatcasecmp((string) $a->section, (string) $b->section))->values()
                    ->each(fn ($r) => $r->name = $this->rows->label($r));
            }

            return $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'plans' => (int) $r->plans, 'delayed' => (int) $r->delayed_chapters,
                'done' => round((float) $r->done, 1), 'expected' => round((float) $r->expected, 1), 'gap' => round($r->done - $r->expected, 1)])->all();
        });
    }

    /** unfinished chapters whose end date has passed, longest overdue first */
    public function delayedQuery(string $year, array $filters = [], ?string $today = null)
    {
        $today ??= Calendar::today();

        return DB::table('teaching_plan_items as i')->join('teaching_plans as p', 'p.id', '=', 'i.plan_id')->join('syllabus_chapters as ch', 'ch.id', '=', 'i.chapter_id')
            ->join('classes as c', 'c.id', '=', 'p.class_id')->join('subjects as s', 's.id', '=', 'p.subject_id')->join('users as u', 'u.id', '=', 'p.teacher_id')
            ->where('p.academic_year', $year)->where('i.ends_on', '<', $today)->where('i.progress', '<', 100)
            ->when($filters['teacher'] ?? null, fn ($q, $v) => $q->where('p.teacher_id', $v))
            ->when($filters['class'] ?? null, fn ($q, $v) => $q->where('p.class_id', $v))
            ->when($filters['subject'] ?? null, fn ($q, $v) => $q->where('p.subject_id', $v))
            ->orderBy('i.ends_on')->orderBy('i.id')
            ->select(['i.id', 'i.plan_id', 'i.ends_on', 'i.progress', 'ch.name as chapter', 's.name as subject', 'u.name as teacher', 'c.class_name', 'c.class_title', 'c.section', 'c.section_title']);
    }

    /** the longest overdue chapters, for the progress screen */
    public function delayedTop(string $year, array $filters, int $limit = 30): array
    {
        $today = Calendar::today();

        return $this->remember('delayed.' . md5(json_encode([$year, $filters, $limit, $today])), fn () => $this->presentDelayed($this->delayedQuery($year, $filters, $today)->limit($limit)->get(), $today));
    }

    public function presentDelayed(Collection $rows, ?string $today = null): array
    {
        $today = Carbon::parse($today ?? Calendar::today());

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'plan_id' => (int) $r->plan_id, 'chapter' => $r->chapter, 'subject' => $r->subject, 'teacher' => $r->teacher,
            'class' => $this->rows->label($r), 'due' => substr($r->ends_on, 0, 10), 'days' => (int) Carbon::parse($r->ends_on)->diffInDays($today), 'progress' => (float) $r->progress])->all();
    }

    /**
     * Who was due to teach on each day of the week, from the routines: [day][teacher_id] => [[class_id, period_id]].
     */
    private function weekSlots(string $year): array
    {
        return Cache::remember("teaching.slots.{$year}", 120, function () use ($year) {
            $out = [];
            $draft = DB::table('class_routine_slots as s')->join('class_routines as r', 'r.id', '=', 's.class_routine_id')->join('classes as c', 'c.id', '=', 'r.class_id')
                ->whereNull('r.published_slots')->whereNotNull('s.teacher_id')->whereNotNull('s.subject_id')->where('c.academic_year', 'like', "{$year}%")
                ->get(['s.day', 's.teacher_id', 'r.class_id', 's.period_id']);

            foreach ($draft as $s) {
                $out[$s->day][$s->teacher_id][] = [(int) $s->class_id, (int) $s->period_id];
            }

            $published = DB::table('class_routines as r')->join('classes as c', 'c.id', '=', 'r.class_id')->whereNotNull('r.published_slots')->where('c.academic_year', 'like', "{$year}%")
                ->get(['r.class_id', 'r.published_slots']);

            foreach ($published as $r) {
                foreach (json_decode($r->published_slots, true) as $s) {
                    if (! empty($s['teacher_id']) && ! empty($s['subject_id'])) {
                        $out[$s['day']][$s['teacher_id']][] = [(int) $r->class_id, (int) $s['period_id']];
                    }
                }
            }

            return $out;
        });
    }

    /**
     * Routine classes that have no log, per teacher and date. A class counts as logged when any log exists for that
     * date, class and period.
     *
     * @return array<int,array{date:string,teacher_id:int,missing:int,due:int}>
     */
    public function pending(string $from, string $to, ?int $teacherId = null): array
    {
        $to = min($to, Calendar::today());

        if ($to < $from) {
            return [];
        }

        // every date: a class may be open (a make-up day) when the school normally is not
        $days = [];

        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $days[] = $d->toDateString();
        }

        $slots = $this->weekSlots(substr($from, 0, 4));
        $logged = DB::table('teaching_logs')->whereBetween('log_date', [$from, $to])->whereNotNull('period_id')
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))->get(['log_date', 'class_id', 'period_id'])
            ->mapWithKeys(fn ($l) => [substr($l->log_date, 0, 10) . "|{$l->class_id}|{$l->period_id}" => true]);

        $out = [];

        $calendar = app(\App\Services\AcademicCalendar::class);

        foreach (array_reverse($days) as $date) {
            $weekday = Carbon::parse($date)->dayOfWeek;
            $candidates = collect($calendar->events($date, $date))->where('type', 'makeup')->pluck('follows_day')->filter(fn ($d) => $d !== null)->push($weekday)->unique();
            $due = [];
            $follows = [];                              // class id => the weekday whose routine it runs today (null: none)

            foreach ($candidates as $day) {
                foreach ($slots[$day] ?? [] as $teacher => $classes) {
                    if ($teacherId && (int) $teacher !== $teacherId) {
                        continue;
                    }

                    foreach ($classes as $c) {
                        if (! array_key_exists($c[0], $follows)) {
                            $state = $calendar->day($date, $c[0]);
                            $follows[$c[0]] = $state['exam'] ? null : $state['routine_day'];       // nothing is due on exam days
                        }

                        $follows[$c[0]] === (int) $day && $due[$teacher][] = $c;
                    }
                }
            }

            foreach ($due as $teacher => $classes) {
                $missing = count(array_filter($classes, fn ($c) => ! isset($logged["{$date}|{$c[0]}|{$c[1]}"])));

                $missing && $out[] = ['date' => $date, 'teacher_id' => (int) $teacher, 'missing' => $missing, 'due' => count($classes)];
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    public function names(array $ids): array
    {
        return DB::table('users')->whereIn('id', array_unique($ids))->pluck('name', 'id')->all();
    }

    /** what each teacher did in a date range */
    public function activity(string $from, string $to, ?int $teacherId = null)
    {
        return DB::table('teaching_logs as l')->join('users as u', 'u.id', '=', 'l.teacher_id')->whereBetween('l.log_date', [$from, $to])
            ->when($teacherId, fn ($q) => $q->where('l.teacher_id', $teacherId))->groupBy('l.teacher_id', 'u.name')->orderBy('u.name')
            ->selectRaw("u.name as teacher, COUNT(*) entries, SUM(l.held) held, SUM(l.held = 0) not_held, SUM(l.kind = 'extra') extra, SUM(l.substitute_id IS NOT NULL) substituted,
                         SUM(l.homework) homework, SUM(l.understanding = 'revision') revision, MAX(l.log_date) last_on");
    }
}
