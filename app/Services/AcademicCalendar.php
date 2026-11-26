<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Teaching\Insights;
use App\Services\Teaching\Settings;
use App\Support\BanglaDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE shared answer to every question about school dates. Routine, Attendance, Teaching Plan, Exam, Fees, Admission and
 * the dashboards ask here and nowhere else.
 *
 *   isWorkingDay($date, $classId)              is there school?
 *   workingDays($from, $to, $classId)          which days, and countWorkingDays() how many
 *   teachingDays($from, $to, $classId)         working days without exam days
 *   routineDay($date, $classId)                which weekday's routine runs (a make-up day follows another)
 *   events($from, $to)                         every occurrence in a range (yearly repeats expanded)
 *   eventsFor($user, $from, $to)               only what this person may see
 *
 * Rules, in this order: a closing event (holiday, vacation, closure) shuts the day for its audience; otherwise a
 * make-up day opens it; otherwise the weekly off days apply. Events for teachers only never close classes.
 * Dates are 'Y-m-d' strings throughout.
 */
class AcademicCalendar
{
    /** @var array<string,array> */
    private array $eventCache = [];

    /** @var array<string,array> */
    private array $mapCache = [];

    /** @var array<int,int>|null */
    private ?array $weekdays = null;

    public function __construct(private RoutineService $routines)
    {
    }

    public static function today(): string
    {
        return now(Settings::get('timezone') ?: 'Asia/Dhaka')->toDateString();
    }

    /** weekday numbers (0 = Sunday) on which the school normally runs */
    public function weekdays(): array
    {
        return $this->weekdays ??= $this->routines->workingDays();
    }

    public function forget(): void
    {
        $this->eventCache = [];
        $this->mapCache = [];
        $this->weekdays = null;
    }

    /** a date moved, appeared or went: everything worked out from dates is worked out again */
    public function changed(?CalendarEvent $event = null): void
    {
        $this->forget();
        app(Teaching\Calendar::class)->forget();

        // "expected by today" of the teaching plans depends on the calendar
        DB::table('teaching_plans')->whereNotNull('summary_on')->update(['summary_on' => null]);
        Insights::touch();
    }

    // ------------------------------------------------------------------
    // events
    // ------------------------------------------------------------------

    /**
     * Every occurrence that touches the range, in date order.
     *
     * @return array<int,array>
     */
    public function events(string $from, string $to): array
    {
        $key = "{$from}|{$to}";

        if (isset($this->eventCache[$key])) {
            return $this->eventCache[$key];
        }

        $rows = DB::table('calendar_events')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('repeat', 'none')->where('starts_on', '<=', $to)->where('ends_on', '>=', $from))
                ->orWhere(fn ($w) => $w->where('repeat', '!=', 'none')->where('starts_on', '<=', $to)))
            ->get();
        $classes = $rows->where('audience', 'classes')->isEmpty() ? collect()
            : DB::table('calendar_event_class')->whereIn('event_id', $rows->where('audience', 'classes')->pluck('id')->all())->get()->groupBy('event_id');

        $out = [];

        foreach ($rows as $r) {
            $base = [
                'id' => (int) $r->id, 'type' => $r->type, 'title' => $r->title, 'title_bn' => $r->title_bn, 'audience' => $r->audience,
                'class_ids' => ($classes[$r->id] ?? collect())->pluck('class_id')->map(fn ($i) => (int) $i)->values()->all(),
                'off' => (bool) $r->off, 'tentative' => (bool) $r->tentative, 'repeat' => $r->repeat, 'note' => $r->note,
                'follows_day' => $r->follows_day === null ? null : (int) $r->follows_day, 'exam_id' => $r->exam_id ? (int) $r->exam_id : null,
            ];

            foreach ($this->occurrences($r, $from, $to) as [$a, $b]) {
                $out[] = $base + ['starts_on' => $a, 'ends_on' => $b];
            }
        }

        usort($out, fn ($x, $y) => [$x['starts_on'], $x['ends_on'], $x['id']] <=> [$y['starts_on'], $y['ends_on'], $y['id']]);

        return $this->eventCache[$key] = $out;
    }

    /** the dates on which a row happens inside the range */
    private function occurrences(object $r, string $from, string $to): array
    {
        $start = substr($r->starts_on, 0, 10);
        $end = substr($r->ends_on, 0, 10);

        if ($r->repeat === 'none') {
            return [[$start, $end]];
        }

        $length = (int) Carbon::parse($start)->diffInDays($end);
        $skip = array_map('intval', json_decode($r->skip_years ?? '[]', true) ?: []);
        $out = [];

        // one year earlier than the range, so a long event that began last December is not missed
        for ($year = max((int) substr($start, 0, 4), (int) substr($from, 0, 4) - 1); $year <= (int) substr($to, 0, 4); $year++) {
            if (in_array($year, $skip, true)) {
                continue;
            }

            if ($r->repeat === 'bangla') {
                $b = BanglaDate::fromGregorian($start);
                // the occurrence of the same Bangla day that falls in this Gregorian year
                $by = $b['year'] + ($year - (int) substr($start, 0, 4));
                $day = min($b['day'], BanglaDate::monthLengths($by)[$b['month'] - 1]);
                $a = BanglaDate::toGregorian($by, $b['month'], $day);
            } else {
                $md = substr($start, 5);
                $md === '02-29' && ! date('L', mktime(0, 0, 0, 1, 1, $year)) && $md = '02-28';
                $a = "{$year}-{$md}";
            }

            if (! $a || $a < $start) {
                continue;
            }

            $z = $length ? Carbon::parse($a)->addDays($length)->toDateString() : $a;
            $a <= $to && $z >= $from && $out[] = [$a, $z];
        }

        return $out;
    }

    /** does an event concern this class? (null = the school as a whole) */
    private function concerns(array $e, ?int $classId): bool
    {
        return match ($e['audience']) {
            'all' => true,
            'classes' => $classId !== null && in_array($classId, $e['class_ids'], true),
            default => false,
        };
    }

    /** date => the events on it, for the range */
    private function byDay(string $from, string $to): array
    {
        $key = "{$from}|{$to}";

        if (isset($this->mapCache[$key])) {
            return $this->mapCache[$key];
        }

        $map = [];

        foreach ($this->events($from, $to) as $e) {
            for ($d = Carbon::parse(max($e['starts_on'], $from)), $last = min($e['ends_on'], $to); ($s = $d->toDateString()) <= $last; $d->addDay()) {
                $map[$s][] = $e;
            }
        }

        return $this->mapCache[$key] = $map;
    }

    // ------------------------------------------------------------------
    // days
    // ------------------------------------------------------------------

    private function judge(string $date, ?int $classId, array $events): array
    {
        $weekday = Carbon::parse($date)->dayOfWeek;
        $closed = null;
        $makeup = null;
        $exam = null;

        foreach ($events as $e) {
            if (! $this->concerns($e, $classId)) {
                continue;
            }

            if ($e['off']) {
                // a closure explains the day better than a holiday it falls on
                if (! $closed || $e['type'] === 'closure') {
                    $closed = $e;
                }
            } elseif ($e['type'] === 'makeup') {
                $makeup = $e;
            } elseif ($e['type'] === 'exam') {
                $exam = $e;
            }
        }

        if ($closed) {
            return ['working' => false, 'reason' => $closed['title'], 'type' => $closed['type'], 'tentative' => $closed['tentative'], 'exam' => null, 'routine_day' => null];
        }

        $usual = in_array($weekday, $this->weekdays(), true);

        if (! $usual && ! $makeup) {
            return ['working' => false, 'reason' => 'Weekly off day', 'type' => 'weekly', 'tentative' => false, 'exam' => null, 'routine_day' => null];
        }

        return ['working' => true, 'reason' => $makeup && ! $usual ? $makeup['title'] : null, 'type' => $makeup ? 'makeup' : null, 'tentative' => false,
            'exam' => $exam['title'] ?? null, 'routine_day' => $makeup && $makeup['follows_day'] !== null ? $makeup['follows_day'] : $weekday];
    }

    /** @return array{working:bool, reason:?string, type:?string, tentative:bool, exam:?string, routine_day:?int} */
    public function day(string $date, ?int $classId = null): array
    {
        return $this->judge($date, $classId, $this->byDay($date, $date)[$date] ?? []);
    }

    public function isWorkingDay(string $date, ?int $classId = null): bool
    {
        return $this->day($date, $classId)['working'];
    }

    /** the weekday whose routine runs that day for the class, or null when there is no school */
    public function routineDay(string $date, ?int $classId = null): ?int
    {
        return $this->day($date, $classId)['routine_day'];
    }

    private function daysWhere(string $from, string $to, ?int $classId, \Closure $keep): array
    {
        if ($to < $from || Carbon::parse($from)->diffInDays($to) > 1100) {
            return [];
        }

        $map = $this->byDay($from, $to);
        $out = [];

        for ($d = Carbon::parse($from); ($s = $d->toDateString()) <= $to; $d->addDay()) {
            $keep($this->judge($s, $classId, $map[$s] ?? [])) && $out[] = $s;
        }

        return $out;
    }

    /** @return array<int,string> */
    public function workingDays(string $from, string $to, ?int $classId = null): array
    {
        return $this->daysWhere($from, $to, $classId, fn ($j) => $j['working']);
    }

    public function countWorkingDays(string $from, string $to, ?int $classId = null): int
    {
        return count($this->workingDays($from, $to, $classId));
    }

    /** working days on which ordinary lessons are taught (no exam) */
    public function teachingDays(string $from, string $to, ?int $classId = null): array
    {
        return $this->daysWhere($from, $to, $classId, fn ($j) => $j['working'] && ! $j['exam']);
    }

    /** the days of a range with no school for the class: what attendance must not count */
    public function closedDays(string $from, string $to, ?int $classId = null): array
    {
        return $this->daysWhere($from, $to, $classId, fn ($j) => ! $j['working']);
    }

    /**
     * The days shut by a holiday, vacation or closure (not the ordinary weekly off days): attendance recorded on them is
     * not counted. Weekly off days are left out on purpose, so changing that setting never rewrites old attendance.
     */
    public function holidayDays(string $from, string $to, ?int $classId = null): array
    {
        return $this->daysWhere($from, $to, $classId, fn ($j) => ! $j['working'] && $j['type'] !== 'weekly');
    }

    // ------------------------------------------------------------------
    // what a person sees
    // ------------------------------------------------------------------

    /** the classes whose class-only events a person who does not manage the calendar sees */
    private function ownClasses(User $user): array
    {
        return array_values(array_unique([...$user->ownedClassIds(), ...array_keys($user->taughtSubjects())]));
    }

    public function canSee(User $user, array $event, ?array $own = null): bool
    {
        if ($user->canDo('calendar.manage')) {
            return true;
        }

        return match ($event['audience']) {
            'all' => true,
            'teachers' => $user->isStaff(),
            default => array_intersect($event['class_ids'], $own ?? $this->ownClasses($user)) !== [],
        };
    }

    /** @param array{types?:array<int,string>, class?:?int} $filters */
    public function eventsFor(User $user, string $from, string $to, array $filters = []): array
    {
        $own = $user->canDo('calendar.manage') ? [] : $this->ownClasses($user);
        $class = $filters['class'] ?? null;

        return array_values(array_filter($this->events($from, $to), fn ($e) => $this->canSee($user, $e, $own)
            && (empty($filters['types']) || in_array($e['type'], $filters['types'], true))
            && (! $class || $e['audience'] === 'all' || in_array((int) $class, $e['class_ids'], true))));
    }

    /** what is coming: events still running or starting within the next days */
    public function upcoming(User $user, int $limit = 6, array $types = [], int $days = 120): array
    {
        $today = self::today();
        $events = $this->eventsFor($user, $today, Carbon::parse($today)->addDays($days)->toDateString(), ['types' => $types]);

        return array_slice(array_values(array_filter($events, fn ($e) => $e['type'] !== 'term')), 0, $limit);
    }

    /** the next day or days on which the whole school is closed by an event */
    public function nextHoliday(User $user): ?array
    {
        $today = self::today();

        foreach ($this->eventsFor($user, $today, Carbon::parse($today)->addDays(366)->toDateString(), ['types' => ['holiday', 'vacation', 'closure']]) as $e) {
            if ($e['off'] && $e['audience'] === 'all') {
                return $e + ['in_days' => max(0, (int) Carbon::parse($today)->diffInDays($e['starts_on'], false))];
            }
        }

        return null;
    }

    /**
     * Working days of a session, per month and per term.
     *
     * @return array{year:int, total:int, months:array, terms:array}
     */
    public function statistics(int $year, ?int $classId = null): array
    {
        $days = $this->workingDays("{$year}-01-01", "{$year}-12-31", $classId);
        $perMonth = array_count_values(array_map(fn ($d) => (int) substr($d, 5, 2), $days));
        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $months[] = ['month' => $m, 'name' => date('F', mktime(0, 0, 0, $m, 1, $year)), 'working' => $perMonth[$m] ?? 0];
        }

        $terms = [];

        foreach ($this->events("{$year}-01-01", "{$year}-12-31") as $e) {
            if ($e['type'] === 'term') {
                $terms[] = ['id' => $e['id'], 'title' => $e['title'], 'starts_on' => $e['starts_on'], 'ends_on' => $e['ends_on'],
                    'working' => $this->countWorkingDays($e['starts_on'], $e['ends_on'], $classId)];
            }
        }

        return ['year' => $year, 'total' => count($days), 'months' => $months, 'terms' => $terms];
    }
}
