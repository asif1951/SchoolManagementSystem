<?php

namespace App\Services;

use App\Models\ClassRoutineSlot;
use App\Models\RoutinePlan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds routines automatically from each class's weekly plan (how many periods a week each subject gets, and the most
 * in one day).
 *
 * What it guarantees:
 *   - a subject gets its planned number of periods whenever the week has room for them, never more than its per-day limit;
 *   - a teacher is never in two places at overlapping CLOCK times: not within this run, and not against the routines of
 *     classes outside it (so classes with different bells are safe);
 *   - periods you chose to keep stay where they are (keep_existing).
 *
 * How: every needed (class, subject, teacher) period is placed one at a time, hardest first (the busiest teachers), in the
 * best free slot by a score that spreads a subject over different days and different positions in the day, balances a
 * teacher's day, and avoids the same subject twice in a row. When a period has nowhere to go, one already-placed period of
 * that class is moved to make room. The whole thing is run several times with different random tie-breaks and the best
 * result (fewest unplaced) is kept. Nothing is saved until apply().
 */
class RoutineGenerator
{
    public function __construct(private RoutineService $routines)
    {
    }

    /**
     * @param  Collection<int,SchoolClass>  $classes
     * @param  array{keep_existing?:bool, teacher_max_per_day?:?int, seed?:int, attempts?:int, seconds?:float}  $o
     * @return array{seed:int, classes:array<int,array>, ok:bool}
     */
    public function run(Collection $classes, array $o = []): array
    {
        $keep = (bool) ($o['keep_existing'] ?? false);
        $tcap = isset($o['teacher_max_per_day']) && $o['teacher_max_per_day'] ? (int) $o['teacher_max_per_day'] : null;
        $seed = (int) ($o['seed'] ?? random_int(1, 2147483000));
        $attempts = max(1, min(60, (int) ($o['attempts'] ?? 25)));
        $deadline = microtime(true) + (float) ($o['seconds'] ?? 8.0);

        $days = $this->routines->workingDays();
        $inRun = $classes->pluck('id')->all();

        // ---- what each class offers and needs ------------------------------------------------------------------------
        $cls = [];
        $out = [];

        foreach ($classes->values() as $ci => $class) {
            $periods = $this->routines->periodsForClass($class)->where('is_break', false)->values();
            $slots = [];

            foreach ($days as $d) {
                foreach ($periods as $pos => $p) {
                    $slots[] = ['day' => $d, 'pid' => $p->id, 'pos' => $pos, 's' => $this->mins($p->start), 'e' => $this->mins($p->end)];
                }
            }

            $subjects = collect($this->routines->subjectsOf($class))->keyBy('name');
            $plan = RoutinePlan::where('class_id', $class->id)->get()->keyBy('subject');

            $out[$class->id] = ['label' => $class->full_label, 'cells' => [], 'needed' => 0, 'placed' => 0, 'kept' => 0, 'unplaced' => [], 'free_left' => 0, 'error' => null];

            if (! $slots) {
                $out[$class->id]['error'] = 'This class has no teaching periods or no working days.';
                continue;
            }

            $needs = [];
            foreach ($plan as $name => $row) {
                if (! $subjects->has($name) || $row->periods_per_week < 1) {
                    continue;
                }

                $needs[$name] = ['n' => (int) $row->periods_per_week, 'max' => max(1, (int) $row->max_per_day)];
            }

            if (! $needs) {
                $out[$class->id]['error'] = 'There is no weekly plan yet: say how many periods each subject gets.';
                continue;
            }

            // periods already placed, kept as they are
            $pre = [];
            if ($keep) {
                $names = Subject::pluck('name', 'id');
                $index = [];

                foreach ($slots as $i => $s) {
                    $index[$s['day'] . '|' . $s['pid']] = $i;
                }

                $routine = $this->routines->routineFor($class);

                foreach ($routine->slots()->get() as $s) {
                    $i = $index[$s->day . '|' . $s->period_id] ?? null;

                    if ($i !== null) {
                        $pre[$i] = ['name' => $s->subject_id ? ($names[$s->subject_id] ?? null) : null, 'label' => $s->label, 'teacher' => $s->teacher_id ? (int) $s->teacher_id : null];
                    }
                }
            }

            $cls[$class->id] = ['class' => $class, 'slots' => $slots, 'subjects' => $subjects, 'needs' => $needs, 'pre' => $pre];
        }

        // ---- teachers' time already taken by routines outside this run ------------------------------------------------
        $busy0 = [];
        $rows = ClassRoutineSlot::query()
            ->join('class_routines as r', 'r.id', '=', 'class_routine_slots.class_routine_id')
            ->join('routine_periods as p', 'p.id', '=', 'class_routine_slots.period_id')
            ->whereNotNull('class_routine_slots.teacher_id')->where('p.is_break', false)
            ->when($inRun, fn ($q) => $q->whereNotIn('r.class_id', $inRun))
            ->get(['class_routine_slots.teacher_id as t', 'class_routine_slots.day as d', 'p.starts_at as a', 'p.ends_at as b']);

        foreach ($rows as $r) {
            $busy0[$r->t][(int) $r->d][] = [$this->mins(substr($r->a, 0, 5)), $this->mins(substr($r->b, 0, 5))];
        }

        // ---- the units to place --------------------------------------------------------------------------------------
        $units = [];
        $load = [];
        $drop = [];

        foreach ($cls as $cid => &$c) {
            $placedAlready = [];

            foreach ($c['pre'] as $i => $p) {
                if ($p['teacher']) {
                    $busy0[$p['teacher']][$c['slots'][$i]['day']][] = [$c['slots'][$i]['s'], $c['slots'][$i]['e']];
                }

                if ($p['name']) {
                    $placedAlready[$p['name']] = ($placedAlready[$p['name']] ?? 0) + 1;
                }
            }

            $need = 0;

            foreach ($c['needs'] as $name => $n) {
                $remaining = max(0, $n['n'] - ($placedAlready[$name] ?? 0));
                $teachers = array_values(array_unique(array_map(fn ($t) => (int) $t['id'], $c['subjects'][$name]['teachers'] ?? [])));
                $need += $remaining;

                for ($k = 0; $k < $remaining; $k++) {
                    $units[] = ['c' => $cid, 'name' => $name, 'teachers' => $teachers, 'max' => $n['max']];
                }

                // a subject taught by several teachers shares its periods between them
                foreach ($teachers as $t) {
                    $load[$t] = ($load[$t] ?? 0) + $remaining / count($teachers);
                }
            }

            $free = count($c['slots']) - count($c['pre']);
            $out[$cid]['needed'] = array_sum(array_column($c['needs'], 'n'));
            $out[$cid]['kept'] = count($c['pre']);

            // more periods asked for than the week has: tell the admin now instead of leaving some out silently
            if ($need > $free) {
                $out[$cid]['error'] = "The plan asks for {$need} more periods but only {$free} are free in the week. Lower some numbers or add periods.";
                $drop[] = $cid;
            }
        }
        unset($c);

        foreach ($drop as $cid) {
            $units = array_values(array_filter($units, fn ($u) => $u['c'] !== $cid));
            unset($cls[$cid]);
        }

        if (! $cls) {
            return ['seed' => $seed, 'classes' => $out, 'ok' => false];
        }

        // ---- try several times, keep the best ------------------------------------------------------------------------
        $best = null;

        for ($a = 0; $a < $attempts; $a++) {
            mt_srand($seed + $a * 7919);
            $result = $this->attempt($cls, $units, $load, $busy0, $tcap);

            if ($best === null || $result['unplaced'] < $best['unplaced'] || ($result['unplaced'] === $best['unplaced'] && $result['cost'] < $best['cost'])) {
                $best = $result;
            }

            if (($best['unplaced'] === 0 && $a >= 4) || microtime(true) > $deadline) {
                break;
            }
        }

        // ---- the answer ------------------------------------------------------------------------------------------------
        $teacherNames = User::whereIn('id', array_filter(array_keys($load)))->pluck('name', 'id');

        foreach ($cls as $cid => $c) {
            $names = $c['subjects'];
            $cells = [];

            foreach ($c['pre'] as $i => $p) {
                $s = $c['slots'][$i];
                $cells[$s['day'] . '-' . $s['pid']] = ['subject' => $p['name'] ?? $p['label'], 'teacher_id' => $p['teacher'], 'teacher' => $p['teacher'] ? ($teacherNames[$p['teacher']] ?? null) : null, 'kept' => true];
            }

            foreach ($best['occ'][$cid] as $i => $u) {
                if ($u === null || ($c['pre'][$i] ?? null)) {
                    continue;
                }

                $s = $c['slots'][$i];
                $cells[$s['day'] . '-' . $s['pid']] = ['subject' => $u['name'], 'teacher_id' => $u['teacher'], 'teacher' => $u['teacher'] ? ($teacherNames[$u['teacher']] ?? $names[$u['name']]['teacher'] ?? null) : null, 'kept' => false];
                $out[$cid]['placed']++;
            }

            $out[$cid]['cells'] = $cells;
            $out[$cid]['free_left'] = count($c['slots']) - count($cells);

            foreach ($best['missing'][$cid] ?? [] as $name => $n) {
                $out[$cid]['unplaced'][] = ['subject' => $name, 'missing' => $n, 'why' => $this->why($c, $best, $cid, $name, $tcap, $teacherNames)];
            }
        }

        return ['seed' => $seed, 'classes' => $out, 'ok' => $best['unplaced'] === 0 && ! collect($out)->contains(fn ($r) => $r['error'])];
    }

    /**
     * One greedy pass with random tie-breaks.
     *
     * The state S holds: occ [class][slot] => null | unit; cnt [class][subject][day]; pos [class][subject][position];
     * busy [teacher][day] => [[from, to]…] in minutes; tc [class][subject][teacher] => periods given to that teacher.
     *
     * @return array{occ:array, missing:array, unplaced:int, cost:float, busy:array}
     */
    private function attempt(array $cls, array $units, array $load, array $busy, ?int $tcap): array
    {
        $S = ['occ' => [], 'cnt' => [], 'pos' => [], 'busy' => $busy, 'tc' => []];

        foreach ($cls as $cid => $c) {
            $S['occ'][$cid] = array_fill(0, count($c['slots']), null);

            foreach ($c['pre'] as $i => $p) {
                $S['occ'][$cid][$i] = ['name' => $p['name'] ?? ('~' . $p['label']), 'teacher' => $p['teacher'], 'teachers' => [], 'max' => 99, 'fixed' => true];

                if ($p['name']) {
                    $d = $c['slots'][$i]['day'];
                    $S['cnt'][$cid][$p['name']][$d] = ($S['cnt'][$cid][$p['name']][$d] ?? 0) + 1;
                }
            }
        }

        // hardest first: the busiest teachers, then the biggest subjects, with a little chance so attempts differ
        $weight = fn ($u) => ($u['teachers'] ? max(array_map(fn ($t) => $load[$t] ?? 0, $u['teachers'])) : 0) * 100 + $u['max'] + mt_rand(0, 40) / 10;
        usort($units, fn ($x, $y) => $weight($y) <=> $weight($x));

        $missing = [];
        $unplaced = 0;
        $cost = 0.0;

        foreach ($units as $u) {
            $cid = $u['c'];
            $pick = $this->best($cls[$cid], $S, $cid, $u, $tcap);

            if ($pick === null && $this->makeRoom($cls[$cid], $S, $tcap, $u, $cid)) {
                continue;
            }

            if ($pick === null) {
                $missing[$cid][$u['name']] = ($missing[$cid][$u['name']] ?? 0) + 1;
                $unplaced++;
                continue;
            }

            $this->put($cls[$cid], $S, $u, $cid, $pick['slot'], $pick['teacher']);
            $cost += $pick['score'];
        }

        return ['occ' => $S['occ'], 'missing' => $missing, 'unplaced' => $unplaced, 'cost' => $cost, 'busy' => $S['busy']];
    }

    /**
     * The best free slot (and the teacher of the subject who takes it) for a unit, or null.
     *
     * @return array{slot:int, teacher:?int, score:float}|null
     */
    private function best(array $c, array $S, int $cid, array $u, ?int $tcap, ?int $skip = null): ?array
    {
        $bestPick = null;
        $bestScore = INF;
        $options = $u['teachers'] ?: [null];
        $several = count($options) > 1;

        foreach ($c['slots'] as $i => $s) {
            if ($S['occ'][$cid][$i] !== null || $i === $skip) {
                continue;
            }

            $d = $s['day'];
            $onDay = $S['cnt'][$cid][$u['name']][$d] ?? 0;

            if ($onDay >= $u['max']) {
                continue;
            }

            $shape = 8 * $onDay                                                                   // a subject on a day that already has it
                + 3 * ($S['pos'][$cid][$u['name']][$s['pos']] ?? 0)                               // the same position in the day again
                + $this->neighbours($c, $S['occ'][$cid], $i, $u['name']) * 5;                     // the same subject right before or after

            foreach ($options as $t) {
                if ($t) {
                    $busyToday = $S['busy'][$t][$d] ?? [];

                    if ($this->overlaps($busyToday, $s['s'], $s['e']) || ($tcap !== null && count($busyToday) >= $tcap)) {
                        continue;
                    }
                }

                $sc = $shape
                    + ($t ? 1.5 * count($S['busy'][$t][$d] ?? []) : 0)                            // an already heavy day for that teacher
                    + ($several && $t ? 2.5 * ($S['tc'][$cid][$u['name']][$t] ?? 0) : 0)          // share the subject evenly between its teachers
                    + mt_rand(0, 20) / 10;

                if ($sc < $bestScore) {
                    $bestScore = $sc;
                    $bestPick = ['slot' => $i, 'teacher' => $t, 'score' => $sc];
                }
            }
        }

        return $bestPick;
    }

    private function neighbours(array $c, array $occ, int $i, string $name): int
    {
        $n = 0;
        $s = $c['slots'][$i];

        foreach ([-1, 1] as $dx) {
            $j = $i + $dx;

            if (isset($c['slots'][$j]) && $c['slots'][$j]['day'] === $s['day'] && ($occ[$j]['name'] ?? null) === $name) {
                $n++;
            }
        }

        return $n;
    }

    private function put(array $c, array &$S, array $u, int $cid, int $slot, ?int $teacher): void
    {
        $s = $c['slots'][$slot];
        $S['occ'][$cid][$slot] = ['name' => $u['name'], 'teacher' => $teacher, 'teachers' => $u['teachers'], 'max' => $u['max'], 'fixed' => false];
        $S['cnt'][$cid][$u['name']][$s['day']] = ($S['cnt'][$cid][$u['name']][$s['day']] ?? 0) + 1;
        $S['pos'][$cid][$u['name']][$s['pos']] = ($S['pos'][$cid][$u['name']][$s['pos']] ?? 0) + 1;

        if ($teacher) {
            $S['busy'][$teacher][$s['day']][] = [$s['s'], $s['e']];
            $S['tc'][$cid][$u['name']][$teacher] = ($S['tc'][$cid][$u['name']][$teacher] ?? 0) + 1;
        }
    }

    /** lift a placed period out of its slot; returns it as a unit that can be placed again */
    private function take(array $c, array &$S, int $cid, int $slot): array
    {
        $s = $c['slots'][$slot];
        $o = $S['occ'][$cid][$slot];
        $S['occ'][$cid][$slot] = null;
        $S['cnt'][$cid][$o['name']][$s['day']]--;
        $S['pos'][$cid][$o['name']][$s['pos']]--;

        if ($o['teacher']) {
            $S['tc'][$cid][$o['name']][$o['teacher']]--;

            foreach ($S['busy'][$o['teacher']][$s['day']] as $k => [$a, $b]) {
                if ($a === $s['s'] && $b === $s['e']) {
                    unset($S['busy'][$o['teacher']][$s['day']][$k]);
                    $S['busy'][$o['teacher']][$s['day']] = array_values($S['busy'][$o['teacher']][$s['day']]);
                    break;
                }
            }
        }

        return ['c' => $cid, 'name' => $o['name'], 'teachers' => $o['teachers'], 'max' => $o['max'], 'was' => $o['teacher']];
    }

    /**
     * No free slot works for $u. Move one already-placed period of the same class somewhere else so that its slot
     * becomes available to $u. One step deep: enough to untangle the common cases without a long search.
     */
    private function makeRoom(array $c, array &$S, ?int $tcap, array $u, int $cid): bool
    {
        $order = array_keys($c['slots']);
        shuffle($order);

        foreach ($order as $x) {
            $o = $S['occ'][$cid][$x];

            if ($o === null || $o['fixed']) {
                continue;
            }

            $s = $c['slots'][$x];

            if (($S['cnt'][$cid][$u['name']][$s['day']] ?? 0) >= $u['max']) {
                continue;
            }

            // would one of $u's teachers be free in slot x once its occupant leaves?  (a teacher the occupant itself frees counts)
            $moved = $this->take($c, $S, $cid, $x);
            $fit = null;

            foreach ($u['teachers'] ?: [null] as $t) {
                $busyToday = $t ? ($S['busy'][$t][$s['day']] ?? []) : [];

                if (! $t || (! $this->overlaps($busyToday, $s['s'], $s['e']) && ($tcap === null || count($busyToday) < $tcap))) {
                    $fit = $t;
                    break;
                }
            }

            $canFit = $u['teachers'] === [] || $fit !== null;
            $y = $canFit ? $this->best($c, $S, $cid, $moved, $tcap, $x) : null;

            if ($canFit && $y !== null) {
                $this->put($c, $S, $moved, $cid, $y['slot'], $y['teacher']);
                $this->put($c, $S, $u, $cid, $x, $fit);

                return true;
            }

            $this->put($c, $S, $moved, $cid, $x, $moved['was']);                  // put it back: this one could not move
        }

        return false;
    }

    private function overlaps(array $intervals, int $s, int $e): bool
    {
        foreach ($intervals as [$a, $b]) {
            if ($a < $e && $b > $s) {
                return true;
            }
        }

        return false;
    }

    /** a plain reason a subject could not be fully placed */
    private function why(array $c, array $best, int $cid, string $name, ?int $tcap, $teacherNames): string
    {
        $free = array_keys(array_filter($best['occ'][$cid], fn ($x) => $x === null));

        if (! $free) {
            return 'the week is full';
        }

        $teachers = array_map(fn ($t) => (int) $t['id'], $c['subjects'][$name]['teachers'] ?? []);

        if ($teachers) {
            $someoneFree = false;

            foreach ($free as $i) {
                $s = $c['slots'][$i];

                foreach ($teachers as $t) {
                    if (! $this->overlaps($best['busy'][$t][$s['day']] ?? [], $s['s'], $s['e']) && ($tcap === null || count($best['busy'][$t][$s['day']] ?? []) < $tcap)) {
                        $someoneFree = true;
                        break 2;
                    }
                }
            }

            if (! $someoneFree) {
                $who = count($teachers) > 1 ? 'every teacher of the subject is' : (($c['subjects'][$name]['teacher'] ?? null) ?: 'the teacher') . ' is';

                return $who . ' already teaching in every period that is still free' . ($tcap ? ' (or has reached ' . $tcap . ' a day)' : '');
            }
        }

        return 'the per-day limit leaves no free period for it';
    }

    private function mins(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)) + [0, 0]);

        return $h * 60 + $m;
    }

    // ------------------------------------------------------------------
    // saving a result
    // ------------------------------------------------------------------

    /**
     * Write a result into the drafts. Each cell goes through the routine's own rules (subject of the class, working day,
     * teacher clash), so a result that went stale since the preview cannot put a teacher in two places.
     *
     * @param  Collection<int,SchoolClass>  $classes
     * @return array{applied:int, classes:int, skipped:array<int,string>}
     */
    public function apply(array $result, Collection $classes, bool $keep): array
    {
        $applied = 0;
        $touched = 0;
        $skipped = [];

        foreach ($classes as $class) {
            $r = $result['classes'][$class->id] ?? null;

            if (! $r || $r['error'] || ! $r['cells']) {
                continue;
            }

            DB::transaction(function () use ($class, $r, $keep, &$applied, &$skipped) {
                if (! $keep) {
                    $this->routines->clearAll($class);
                }

                foreach ($r['cells'] as $key => $cell) {
                    if ($cell['kept']) {
                        continue;
                    }

                    [$day, $pid] = array_map('intval', explode('-', $key));

                    try {
                        $this->routines->setCell($class, $day, $pid, $cell['subject'], null, $cell['teacher_id']);
                        $applied++;
                    } catch (ValidationException $e) {
                        $skipped[] = $class->full_label . ': ' . collect($e->errors())->flatten()->first();
                    }
                }
            });

            $this->routines->routineFor($class)->touch();
            $touched++;
        }

        return ['applied' => $applied, 'classes' => $touched, 'skipped' => $skipped];
    }
}
