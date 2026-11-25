<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PromotionItem;
use App\Models\PromotionRun;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moving students from one session to the next.
 *
 *  Automatic   plan() builds a preview for a class (or all classes): who is promoted to which class and roll,
 *              who is held back and why. execute() carries a plan out.
 *  Manual      applyManual() takes a per-student decision: promote to a class and roll, repeat the class,
 *              or drop out. For the exceptions the automatic run cannot decide.
 *
 * Both only ever ADD an enrollment for the new session; the old enrollment is never overwritten. Each run is one
 * transaction (all or nothing) and safe to repeat: a student already enrolled for the new session is skipped,
 * and the database refuses a second enrollment of the same student in the same session anyway.
 */
class StudentPromotion
{
    public const ROLL_MODES = ['sequential', 'keep', 'result'];

    public function __construct(private StudentRegistry $registry, private TabulationBuilder $tabulation, private TransferService $transfers)
    {
    }

    // ------------------------------------------------------------------
    // the next class
    // ------------------------------------------------------------------

    /**
     * Class names in promotion order: config('student.class_order') when the school sets one
     * (needed for names that do not sort naturally, such as Nursery, KG), else natural order.
     *
     * @param  array<int,string>  $names  the class names present in the two sessions
     */
    public function classOrder(array $names): array
    {
        $names = array_values(array_unique($names));

        if ($set = config('student.class_order')) {
            return array_values(array_filter($set, fn ($n) => in_array($n, $names, true))) ?: $this->natural($names);
        }

        return $this->natural($names);
    }

    private function natural(array $names): array
    {
        usort($names, 'strnatcasecmp');

        return $names;
    }

    // ------------------------------------------------------------------
    // preview
    // ------------------------------------------------------------------

    /** how many students of each class the preview lists (the counts are always complete) */
    public const PREVIEW_SAMPLE = 12;

    /**
     * The preview: for every class its target, the counts, and a short sample of students. Classes are planned one at a
     * time, so a school of a million students needs no more memory than a school of a hundred.
     *
     * @param  array{exclude_failed?:bool, roll_mode?:string, graduate_last?:bool}  $opts
     * @return array{from:string,to:string,scope:?string,options:array,classes:array<int,array>,totals:array<string,int>}
     */
    public function plan(string $from, string $to, ?string $className = null, array $opts = []): array
    {
        $opts = $this->options($opts);
        $ctx = $this->context($from, $to, $className);

        $used = [];
        $classes = [];
        $totals = ['promote' => 0, 'skipped' => 0, 'graduate' => 0, 'blocked' => 0];

        foreach ($ctx['sources'] as $src) {
            $c = $this->planClass($src, $ctx, $opts, $used);

            foreach ($c['counts'] as $k => $n) {
                $totals[$k] += $n;
            }

            // the sample leads with the students who will move, then the ones left out (the reason is what matters there)
            $all = collect($c['entries']);
            $sample = ($all->count() > self::PREVIEW_SAMPLE ? $all->sortBy(fn ($e) => $e['action'] === 'promote' ? 0 : 1) : $all)->take(self::PREVIEW_SAMPLE)->values()->all();

            $classes[] = [
                'from_class_id' => $src->id, 'from' => $this->label($src),
                'to_class_id'   => $c['target']?->id, 'to' => $c['target'] ? $this->label($c['target']) : ($c['nextName'] === null ? null : $c['nextName'] . ($src->section ? " {$src->section}" : '')),
                'blocked'       => $c['blocked'],
                'final_class'   => $c['nextName'] === null,
                'counts'        => $c['counts'],
                'students'      => $sample,
                'students_total' => count($c['entries']),
            ];
        }

        return ['from' => $from, 'to' => $to, 'scope' => $className, 'options' => $opts, 'classes' => $classes, 'totals' => $totals];
    }

    private function options(array $opts): array
    {
        $opts = array_merge(['exclude_failed' => false, 'roll_mode' => 'sequential', 'graduate_last' => false], $opts);
        $opts['roll_mode'] = in_array($opts['roll_mode'], self::ROLL_MODES, true) ? $opts['roll_mode'] : 'sequential';

        return $opts;
    }

    /** what every class of a run shares: its source classes (in order), the target classes, the class order */
    private function context(string $from, string $to, ?string $className): array
    {
        return [
            'from' => $from, 'to' => $to,
            'sources' => SchoolClass::query()->where('academic_year', $from)->where('status', 'active')
                ->when($className, fn ($q) => $q->where('class_name', $className))->get()
                ->sort(fn ($a, $b) => strnatcasecmp($a->class_name, $b->class_name) ?: strnatcasecmp((string) $a->section, (string) $b->section))   // Class 2 before Class 10
                ->values(),
            'targets' => SchoolClass::query()->where('academic_year', $to)->where('status', 'active')->get(),
            'order'   => $this->classOrder(SchoolClass::query()->whereIn('academic_year', [$from, $to])->pluck('class_name')->all()),
        ];
    }

    /**
     * One source class: what happens to each of its students and the roll each gets. Reads only this class's students
     * (and the rolls already in its target), never the whole school.
     *
     * @param  array<int,array<string,bool>>  $used  rolls taken per target class; filled lazily, shared across classes of one run
     * @return array{entries:array<int,array>, students:\Illuminate\Support\Collection, target:?SchoolClass, blocked:?string, nextName:?string, counts:array<string,int>}
     */
    private function planClass(SchoolClass $src, array $ctx, array $opts, array &$used): array
    {
        $pos = array_search($src->class_name, $ctx['order'], true);
        $nextName = $pos === false ? null : ($ctx['order'][$pos + 1] ?? null);       // null = this is the final class
        [$target, $blocked] = $nextName === null ? [null, null] : $this->resolveTarget($src, $nextName, $ctx['targets'], $ctx['to']);

        $students = Student::where('class_id', $src->id)->get();

        // already in the new session (so a repeated run skips them)
        $alreadyEnrolled = Student::where('academic_year', (int) $ctx['to'])->whereIn('profile_id', $students->pluck('profile_id'))->pluck('profile_id')->flip();

        if ($target && ! isset($used[$target->id])) {
            $used[$target->id] = Student::where('class_id', $target->id)->whereNotNull('roll_number')->where('roll_number', '!=', '')->pluck('roll_number')
                ->mapWithKeys(fn ($r) => [(string) $r => true])->all();
        }

        $results = [];
        if ($opts['exclude_failed'] || $opts['roll_mode'] === 'result') {
            $results = collect($this->tabulation->build($src, 'final')['rows'])->keyBy('id')->all();
        }

        $entries = [];
        $promoting = [];

        foreach ($students as $s) {
            $e = ['enrollment_id' => $s->id, 'profile_id' => $s->profile_id, 'name' => $s->student_name, 'code' => $s->student_id,
                  'roll' => $s->roll_number, 'new_roll' => null, 'action' => 'skipped', 'reason' => null];

            if ($s->status !== 'active') {
                $e['reason'] = (Student::STATUSES[$s->status] ?? $s->status) . ': not promoted';
            } elseif (isset($alreadyEnrolled[$s->profile_id])) {
                $e['reason'] = "Already enrolled for {$ctx['to']}";
            } elseif ($opts['exclude_failed'] && ($results[$s->id]['status'] ?? null) === 'Failed') {
                $e['reason'] = 'Failed the final examination';
            } elseif ($nextName === null) {
                if ($opts['graduate_last']) {
                    $e['action'] = 'graduate';
                } else {
                    $e['reason'] = 'Final class: there is no next class';
                }
            } elseif (! $target) {
                $e['action'] = 'blocked';
                $e['reason'] = $blocked;
            } else {
                $e['action'] = 'promote';
                $promoting[] = $s->id;
            }

            $entries[$s->id] = $e;
        }

        // roll numbers in the target class
        if ($promoting && $target) {
            $this->assignRolls($entries, $promoting, $students->keyBy('id'), $results, $opts['roll_mode'], $used[$target->id] ?? [], $used, $target->id);
        }

        $counts = ['promote' => 0, 'skipped' => 0, 'graduate' => 0, 'blocked' => 0];
        foreach ($entries as $e) {
            $counts[$e['action']] = ($counts[$e['action']] ?? 0) + 1;
        }

        return ['entries' => $entries, 'students' => $students->keyBy('id'), 'target' => $target, 'blocked' => $blocked, 'nextName' => $nextName, 'counts' => $counts];
    }

    /** @return array{0:?SchoolClass, 1:?string}  the class students move to, or why there is none */
    private function resolveTarget(SchoolClass $src, string $nextName, $targets, string $to): array
    {
        $candidates = $targets->filter(fn ($t) => $t->class_name === $nextName
            && (string) $t->section === (string) $src->section
            && $t->shift_id === $src->shift_id && $t->medium_id === $src->medium_id)->values();

        if ($candidates->isEmpty()) {
            return [null, "No {$nextName}" . ($src->section ? " section {$src->section}" : '') . " in {$to}. Create the class first."];
        }

        if ($src->study_group_id) {
            $candidates = $candidates->filter(fn ($t) => $t->study_group_id === $src->study_group_id)->values();

            return $candidates->count() === 1
                ? [$candidates[0], null]
                : [null, "No {$nextName} for the {$src->group_name} group in {$to}. Create the class first."];
        }

        // from an ungrouped class into grouped classes (e.g. Class 8 → Class 9): each student must choose their group
        if ($candidates->count() > 1 || $candidates[0]->study_group_id) {
            return [null, "{$nextName} has study groups: use Manual promotion to place each student in their group."];
        }

        return [$candidates[0], null];
    }

    /** fill new_roll for the students being promoted into one class */
    private function assignRolls(array &$entries, array $promoting, $students, array $results, string $mode, array $taken, array &$used, int $targetId): void
    {
        $ordered = collect($promoting)->sortBy(function ($id) use ($students, $results, $mode) {
            $s = $students[$id];
            $rank = $mode === 'result' ? ($results[$id]['rank'] ?? PHP_INT_MAX) : 0;

            return sprintf('%010d|%010d|%s', $rank, (int) $s->roll_number, mb_strtolower($s->student_name));
        })->values();

        $next = 1;

        foreach ($ordered as $id) {
            $keep = (string) $students[$id]->roll_number;

            if ($mode === 'keep' && $keep !== '' && ! isset($taken[$keep])) {
                $roll = $keep;
            } else {
                while (isset($taken[(string) $next])) {
                    $next++;
                }
                $roll = (string) $next;
            }

            $taken[$roll] = true;
            $used[$targetId][$roll] = true;
            $entries[$id]['new_roll'] = $roll;
        }
    }

    // ------------------------------------------------------------------
    // execution
    // ------------------------------------------------------------------

    /**
     * Carry out a promotion: one transaction, nothing half-done. It is set up class by class, and each class is saved
     * with bulk inserts (a school of 500 students is a handful of statements, not 500 × 10). Students already enrolled
     * for the new session are skipped, so running the same promotion twice changes nothing the second time.
     *
     * The plan passed in says WHAT to run (sessions, scope, options); who moves where is worked out again here, from the
     * data as it is now, class by class.
     *
     * @param  callable(int):void|null  $tick  called with the number of students handled in each class
     */
    public function execute(array $plan, User $by, string $mode = 'auto', ?int $taskId = null, ?callable $tick = null): PromotionRun
    {
        $opts = $this->options($plan['options'] ?? []);

        try {
            return DB::transaction(function () use ($plan, $by, $mode, $taskId, $opts, $tick) {
                $run = PromotionRun::create([
                    'user_id' => $by->id, 'mode' => $mode, 'from_session' => $plan['from'], 'to_session' => $plan['to'],
                    'scope' => $plan['scope'] ?? null, 'options' => $plan['options'] ?? [], 'task_id' => $taskId,
                ]);

                $ctx = $this->context($plan['from'], $plan['to'], $plan['scope'] ?? null);
                $used = [];
                $counts = ['promoted' => 0, 'repeated' => 0, 'dropped' => 0, 'transferred' => 0, 'graduated' => 0, 'skipped' => 0];
                $blocked = 0;

                foreach ($ctx['sources'] as $src) {
                    $c = $this->planClass($src, $ctx, $opts, $used);
                    $blocked += $c['counts']['blocked'];
                    $this->applyClass($run, $c, (int) $plan['to'], $counts);
                    $tick && $tick(count($c['entries']));
                }

                $run->update($counts);
                $run->setAttribute('blocked', $blocked);          // reported to the screen; not a column
                AuditLog::record($run, 'promotion', ['mode' => $mode, 'from' => $plan['from'], 'to' => $plan['to'], 'scope' => $plan['scope'] ?? 'all classes'] + $counts, $by->id);

                return $run;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // a roll or a student code was taken by someone else while this ran; the whole run was rolled back
            throw ValidationException::withMessages(['promotion' => 'Something changed while the promotion ran (a roll number was taken). Nobody was moved: run it again.']);
        }
    }

    /** save one planned class: new enrollments for those who move, "passed out" for graduates, a line per student in the run */
    private function applyClass(PromotionRun $run, array $c, int $to, array &$counts): void
    {
        $now = now()->toDateTimeString();
        $items = [];
        $newRows = [];
        $graduates = [];

        foreach ($c['entries'] as $id => $e) {
            $item = ['run_id' => $run->id, 'profile_id' => $e['profile_id'], 'from_enrollment_id' => $e['enrollment_id'], 'to_enrollment_id' => null, 'action' => 'skipped', 'reason' => $e['reason'], 'created_at' => $now];

            if ($e['action'] === 'promote') {
                $s = $c['students'][$id];
                $row = [
                    'profile_id' => $s->profile_id, 'student_id' => $s->student_id, 'academic_year' => $to, 'class_id' => $c['target']->id,
                    'study_group_id' => $c['target']->study_group_id, 'roll_number' => $e['new_roll'], 'status' => 'active', 'promoted_from_id' => $s->id,
                    'public_token' => (string) \Illuminate\Support\Str::ulid(), 'created_at' => $now, 'updated_at' => $now,
                ];

                foreach (StudentProfile::ENROLLMENT_COLUMN as $column) {          // the synchronized copy of the personal data
                    $row[$column] = $s->getRawOriginal($column);
                }

                $row['admission_date'] = $s->getRawOriginal('admission_date');
                $newRows[] = $row;
                $items[$id] = ['action' => 'promoted', 'reason' => null] + $item;
            } elseif ($e['action'] === 'graduate') {
                $graduates[] = $id;
                $items[$id] = ['action' => 'graduated', 'reason' => 'Completed the final class'] + $item;
            } else {
                $items[$id] = $item;
            }
        }

        foreach (array_chunk($newRows, 1000) as $part) {
            DB::table('students')->insert($part);
        }

        if ($newRows) {
            $from = array_keys(array_filter($c['entries'], fn ($e) => $e['action'] === 'promote'));

            foreach (array_chunk($from, 5000) as $part) {
                foreach (DB::table('students')->where('academic_year', $to)->whereIn('promoted_from_id', $part)->pluck('id', 'promoted_from_id') as $old => $new) {
                    $items[$old]['to_enrollment_id'] = $new;
                }
            }
        }

        if ($graduates) {
            foreach (array_chunk($graduates, 5000) as $part) {
                DB::table('students')->whereIn('id', $part)->update(['status' => 'passed_out', 'status_changed_on' => now()->toDateString(), 'status_reason' => 'Completed the final class', 'updated_at' => $now]);
            }
        }

        foreach (array_chunk(array_values($items), 1000) as $part) {
            DB::table('promotion_items')->insert($part);
        }

        $counts['promoted'] += count($newRows);
        $counts['graduated'] += count($graduates);
        $counts['skipped'] += count($items) - count($newRows) - count($graduates);
    }

    // ------------------------------------------------------------------
    // manual
    // ------------------------------------------------------------------

    /**
     * Per-student decisions for a session change.
     *
     * @param  array<int,array{enrollment_id:int, action:string, class_id?:?int, roll_number?:?string}>  $decisions
     *         action: promote (needs class_id) | repeat (class_id optional: the same class again) | drop
     *
     * @throws ValidationException  any invalid decision cancels the whole run
     */
    public function applyManual(string $from, string $to, array $decisions, User $by): PromotionRun
    {
        if (! $decisions) {
            throw ValidationException::withMessages(['decisions' => 'Choose at least one student.']);
        }

        $ids = array_column($decisions, 'enrollment_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['decisions' => 'A student appears twice in this promotion.']);
        }

        $enrollments = Student::whereIn('id', $ids)->get()->keyBy('id');
        $targets = SchoolClass::where('academic_year', $to)->where('status', 'active')->get()->keyBy('id');

        return DB::transaction(function () use ($from, $to, $decisions, $by, $enrollments, $targets) {
            $run = PromotionRun::create(['user_id' => $by->id, 'mode' => 'manual', 'from_session' => $from, 'to_session' => $to]);
            $counts = ['promoted' => 0, 'repeated' => 0, 'dropped' => 0, 'transferred' => 0, 'graduated' => 0, 'skipped' => 0];

            foreach ($decisions as $i => $d) {
                $s = $enrollments[$d['enrollment_id']] ?? null;
                $who = $s?->student_name ?? "row " . ($i + 1);

                if (! $s || (string) $s->academic_year !== $from) {
                    throw ValidationException::withMessages(['decisions' => "{$who}: this student is not enrolled for {$from}."]);
                }

                if ($s->status !== 'active') {
                    throw ValidationException::withMessages(['decisions' => "{$who} is " . strtolower($s->status_label) . ' and cannot be promoted.']);
                }

                $item = ['run_id' => $run->id, 'profile_id' => $s->profile_id, 'from_enrollment_id' => $s->id, 'created_at' => now()];

                switch ($d['action']) {
                    case 'drop':
                        $this->setStatus($s->id, 'dropped', 'Dropped at promotion');
                        PromotionItem::create($item + ['action' => 'dropped']);
                        $counts['dropped']++;
                        break;

                    case 'promote':
                    case 'repeat':
                        $class = $targets[$d['class_id'] ?? 0] ?? ($d['action'] === 'repeat' ? $this->sameClassAgain($s, $targets) : null);

                        if (! $class) {
                            throw ValidationException::withMessages(['decisions' => "{$who}: choose a class of {$to}" . ($d['action'] === 'repeat' ? ' (the same class does not exist there yet).' : '.')]);
                        }

                        try {
                            $new = $this->registry->enroll($s->profile, [
                                'class_id' => $class->id, 'roll_number' => $d['roll_number'] ?? null, 'promoted_from_id' => $s->id,
                            ]);
                        } catch (ValidationException $e) {
                            throw ValidationException::withMessages(['decisions' => "{$who}: " . collect($e->errors())->flatten()->implode(' ')]);
                        }

                        PromotionItem::create($item + ['to_enrollment_id' => $new->id, 'action' => $d['action'] === 'repeat' ? 'repeated' : 'promoted']);
                        $counts[$d['action'] === 'repeat' ? 'repeated' : 'promoted']++;
                        break;

                    case 'transfer':
                        // not a status change by itself: it opens a transfer request, and the dues check, approval and
                        // Transfer Certificate follow in Transfers. The student stays active until it is approved.
                        try {
                            $this->transfers->request($s, $by, 'Decided at promotion to ' . $to, null);
                        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
                            throw ValidationException::withMessages(['decisions' => "{$who}: you may not request a transfer."]);
                        }

                        PromotionItem::create($item + ['action' => 'transferred', 'reason' => 'Transfer requested']);
                        $counts['transferred']++;
                        break;

                    default:
                        throw ValidationException::withMessages(['decisions' => "{$who}: unknown action \"{$d['action']}\"."]);
                }
            }

            $run->update($counts);
            AuditLog::record($run, 'promotion', ['mode' => 'manual', 'from' => $from, 'to' => $to] + $counts, $by->id);

            return $run;
        });
    }

    /** the class a held-back student repeats: same name, section, group, shift and medium in the new session */
    private function sameClassAgain(Student $s, $targets): ?SchoolClass
    {
        $src = $s->schoolClass;

        return $targets->first(fn ($t) => $t->class_name === $src->class_name && (string) $t->section === (string) $src->section
            && $t->study_group_id === $src->study_group_id && $t->shift_id === $src->shift_id && $t->medium_id === $src->medium_id);
    }

    private function setStatus(int $enrollmentId, string $status, string $reason): void
    {
        Student::whereKey($enrollmentId)->first()?->update(['status' => $status, 'status_changed_on' => now()->toDateString(), 'status_reason' => $reason]);
    }

    private function label(SchoolClass $c): string
    {
        return trim($c->short_label . ($c->group_name ? " ({$c->group_name})" : ''));
    }
}
