<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Support\Normalize;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Student Report and Student Statistics. One query builder serves the on-screen list, the print view and the
 * export, so the three always agree. Everything is limited to the classes the user may see (`students.reports`),
 * the BRN is masked unless the user holds `students.view_full_brn`, and the statistics are cached for a short time.
 */
class StudentReports
{
    /** column key => [label, selected by default] — the report's selectable columns, in print order */
    public const COLUMNS = [
        'code'            => ['Student code', true],
        'name_en'         => ['Name', true],
        'name_bn'         => ['Name (Bangla)', false],
        'class'           => ['Class', true],
        'section'         => ['Section', true],
        'group'           => ['Group', false],
        'roll'            => ['Roll', true],
        'gender'          => ['Gender', true],
        'religion'        => ['Religion', false],
        'dob'             => ['Date of birth', false],
        'father_name'     => ["Father's name", false],
        'mother_name'     => ["Mother's name", false],
        'guardian_mobile' => ['Guardian mobile', true],
        'brn'             => ['BRN', false],
        'blood_group'     => ['Blood group', false],
        'present_address' => ['Address', false],
        'admission_date'  => ['Admission date', false],
        'status'          => ['Status', true],
    ];

    public const SORTS = ['roll' => 'students.roll_no', 'name' => 'student_profiles.name_normalized', 'code' => 'student_profiles.code'];

    /** the columns the user asked for, in report order; unknown keys are ignored, none = the defaults */
    public function columns(?array $asked): array
    {
        $keys = array_values(array_intersect(array_keys(self::COLUMNS), $asked ?: []));

        return $keys ?: array_keys(array_filter(self::COLUMNS, fn ($c) => $c[1]));
    }

    /** @return array<string,mixed> the filters that count, cleaned */
    public function filters(array $in, string $defaultSession): array
    {
        $clean = fn ($k) => isset($in[$k]) && is_scalar($in[$k]) && trim((string) $in[$k]) !== '' ? trim((string) $in[$k]) : null;

        return [
            'session'    => preg_match('/^\d{4}$/', (string) ($in['session'] ?? '')) ? (string) $in['session'] : $defaultSession,
            'class_name' => $clean('class_name'),
            'section'    => $clean('section'),
            'group_id'   => ctype_digit((string) ($in['group_id'] ?? '')) ? (int) $in['group_id'] : null,
            'gender'     => in_array($in['gender'] ?? null, ['Male', 'Female', 'Other'], true) ? $in['gender'] : null,
            'religion'   => $clean('religion'),
            'status'     => in_array($in['status'] ?? null, array_keys(Student::STATUSES), true) ? $in['status'] : null,
            'admission'  => in_array($in['admission'] ?? null, ['new', 'old'], true) ? $in['admission'] : null,
            'sort'       => array_key_exists($in['sort'] ?? '', self::SORTS) ? $in['sort'] : 'roll',
        ];
    }

    /** the base query for the report, already limited to what $user may see */
    public function query(array $f, User $user)
    {
        $scope = $user->classScope('students.reports');

        $q = Student::query()
            ->join('student_profiles', 'student_profiles.id', '=', 'students.profile_id')
            ->join('classes', 'classes.id', '=', 'students.class_id')
            ->leftJoin('study_groups', 'study_groups.id', '=', DB::raw('COALESCE(students.study_group_id, classes.study_group_id)'))
            ->where('students.academic_year', $f['session'])
            ->when($scope !== null, fn ($w) => $w->whereIn('students.class_id', $scope ?: [0]))
            ->when($f['class_name'], fn ($w, $v) => $w->where('classes.class_name', $v))
            ->when($f['section'], fn ($w, $v) => $w->where('classes.section', $v))
            ->when($f['group_id'], fn ($w, $v) => $w->where(DB::raw('COALESCE(students.study_group_id, classes.study_group_id)'), $v))
            ->when($f['gender'], fn ($w, $v) => $w->where('students.gender', $v))
            ->when($f['religion'], fn ($w, $v) => $w->where('student_profiles.religion', $v))
            ->when($f['status'], fn ($w, $v) => $w->where('students.status', $v));

        if ($f['admission']) {
            // new = a first admission in this session: not promoted from an earlier one
            $f['admission'] === 'new' ? $q->whereNull('students.promoted_from_id') : $q->whereNotNull('students.promoted_from_id');
        }

        return $q->select([
            'students.id', 'students.roll_number', 'students.status', 'students.academic_year', 'students.class_id', 'students.roll_no', 'student_profiles.name_normalized',
            'student_profiles.code', 'student_profiles.name_en', 'student_profiles.name_bn', 'student_profiles.gender', 'student_profiles.religion',
            'student_profiles.dob', 'student_profiles.father_name', 'student_profiles.mother_name', 'student_profiles.guardian_mobile',
            'student_profiles.brn', 'student_profiles.blood_group', 'student_profiles.present_address', 'student_profiles.admission_date',
            'classes.class_name', 'classes.class_title', 'classes.section as cls_section', 'classes.section_title', 'study_groups.name as group_name',
        ])->when($f['sort'] === 'roll', fn ($w) => $w->orderBy('students.class_id')->orderBy('students.roll_no'), fn ($w) => $w->orderBy(self::SORTS[$f['sort']]))->orderBy('students.id');
    }

    /**
     * Every row of the report, in the report's order, a page at a time (keyset paging: each page continues after the
     * last row of the previous one, so page 5,000 costs the same as page 1 and memory stays flat).
     *
     * @return \Generator<int,object>
     */
    public function stream(array $f, User $user, int $page = 5000): \Generator
    {
        $after = null;

        do {
            $q = $this->query($f, $user)->limit($page);

            if ($after !== null) {
                if ($f['sort'] === 'roll') {
                    $q->whereRaw('(students.class_id, students.roll_no, students.id) > (?, ?, ?)', [$after->class_id, $after->roll_no, $after->id]);
                } elseif ($f['sort'] === 'name') {
                    $q->whereRaw('(student_profiles.name_normalized, students.id) > (?, ?)', [$after->name_normalized, $after->id]);
                } else {
                    $q->whereRaw('(student_profiles.code, students.id) > (?, ?)', [$after->code, $after->id]);
                }
            }

            $rows = $q->get();

            foreach ($rows as $row) {
                yield $row;
            }

            $after = $rows->last();
        } while ($rows->count() === $page);
    }

    /** the count stops here: beyond it a list says "10,000+" instead of reading a million rows to say exactly how many */
    public const COUNT_CAP = 10000;

    /**
     * How many rows the report has, up to COUNT_CAP: the same filters, but only the joins they need, and the database
     * stops reading at the cap. An exact count of a million rows takes seconds; "10,000+" takes milliseconds.
     *
     * @return array{n:int, capped:bool}
     */
    public function count(array $f, User $user): array
    {
        $scope = $user->classScope('students.reports');
        $q = $this->countQuery($f, $user, $scope);
        $scopeKey = $scope === null ? 'all' : md5(implode(',', $scope));

        // a count may be a minute old: it is only a heading
        return Cache::remember('sm.count.' . md5(json_encode($f) . $scopeKey), 60, function () use ($q) {
            $n = (int) DB::query()->fromSub($q->select(DB::raw('1'))->limit(self::COUNT_CAP + 1), 't')->count();

            return $n > self::COUNT_CAP ? ['n' => self::COUNT_CAP, 'capped' => true] : ['n' => $n, 'capped' => false];
        });
    }

    /** the exact number of rows (for a progress bar in a background job: it may take seconds on a very large school) */
    public function exact(array $f, User $user): int
    {
        return (int) $this->countQuery($f, $user, $user->classScope('students.reports'))->count();
    }

    private function countQuery(array $f, User $user, ?array $scope)
    {
        $q = DB::table('students')->whereNull('students.deleted_at')->where('students.academic_year', (int) $f['session'])
            ->when($scope !== null, fn ($w) => $w->whereIn('students.class_id', $scope ?: [0]))
            ->when($f['status'], fn ($w, $v) => $w->where('students.status', $v))
            ->when($f['gender'], fn ($w, $v) => $w->where('students.gender', $v));          // kept on the enrollment too: no join

        if ($f['class_name'] || $f['section'] || $f['group_id']) {
            $q->join('classes', 'classes.id', '=', 'students.class_id')
                ->when($f['class_name'], fn ($w, $v) => $w->where('classes.class_name', $v))
                ->when($f['section'], fn ($w, $v) => $w->where('classes.section', $v))
                ->when($f['group_id'], fn ($w, $v) => $w->where(DB::raw('COALESCE(students.study_group_id, classes.study_group_id)'), $v));
        }

        if ($f['religion']) {
            $q->join('student_profiles', 'student_profiles.id', '=', 'students.profile_id')->where('student_profiles.religion', $f['religion']);
        }

        if ($f['admission']) {
            $f['admission'] === 'new' ? $q->whereNull('students.promoted_from_id') : $q->whereNotNull('students.promoted_from_id');
        }

        return $q;
    }

    /** one row of the report, with only the chosen columns, BRN masked as the user's permission requires */
    public function row($s, array $columns, User $user, ?bool $full = null): array
    {
        $full ??= $user->canDo('students.view_full_brn');

        $all = [
            'code' => $s->code, 'name_en' => $s->name_en, 'name_bn' => $s->name_bn, 'class' => \App\Models\SchoolClass::joinLabel($s->class_name, $s->class_title), 'section' => $s->cls_section || $s->section_title ? \App\Models\SchoolClass::joinLabel((string) $s->cls_section, $s->section_title) : null,
            'group' => $s->group_name, 'roll' => $s->roll_number, 'gender' => $s->gender, 'religion' => $s->religion,
            'dob' => $s->dob ? substr((string) $s->dob, 0, 10) : null, 'father_name' => $s->father_name, 'mother_name' => $s->mother_name,
            'guardian_mobile' => $s->guardian_mobile, 'brn' => $full ? $s->brn : Normalize::maskBrn($s->brn), 'blood_group' => $s->blood_group,
            'present_address' => $s->present_address, 'admission_date' => $s->admission_date ? substr((string) $s->admission_date, 0, 10) : null,
            'status' => Student::STATUSES[$s->status] ?? ucfirst(str_replace('_', ' ', (string) $s->status)),
        ];

        return array_intersect_key($all, array_flip($columns));
    }

    /** the distinct religions and sections of a session, for the filter lists */
    public function options(string $session, User $user): array
    {
        $scope = $user->classScope('students.reports');

        $classes = DB::table('classes')->where('academic_year', $session)->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]));

        return [
            'classNames' => (clone $classes)->distinct()->pluck('class_name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'sections'   => (clone $classes)->whereNotNull('section')->where('section', '!=', '')->distinct()->pluck('section')->sort()->values()->all(),
            'religions'  => DB::table('student_profiles')->whereNotNull('religion')->where('religion', '!=', '')->distinct()->orderBy('religion')->pluck('religion')->all(),
        ];
    }

    // ------------------------------------------------------------------
    // statistics
    // ------------------------------------------------------------------

    /** kept only so the last figures can be shown while a large school's new ones are built (seconds) */
    public const CACHE_SECONDS = 120;

    /** a school with more enrollments than this builds its statistics in the background, and they stay for an hour */
    public const LARGE = 20000;
    public const LARGE_CACHE_SECONDS = 3600;

    /**
     * The statistics. A small school gets them at once. A large one gets the last built figures straight away
     * (marked "building" while a fresh set is made by a background job): reading a million rows takes about half a minute.
     */
    public function statistics(string $session, User $user, bool $refresh = false): array
    {
        $scope = $user->classScope('students.reports');
        $key = $this->statsKey($session, $scope);
        // up to LARGE enrollments the figures are always live (they take milliseconds): what is on screen is what is in the database
        if (! $this->isLarge($session, $scope)) {
            return $this->rebuild($session, $user);
        }

        $cached = Cache::get($key);

        if ($cached && ! $refresh) {
            return $cached;
        }

        // large: keep the old figures on screen and make new ones in the background (one build at a time)
        if (Cache::add($key . '.lock', 1, 1800)) {
            \App\Jobs\BuildStatistics::dispatch($session, $user->id);
        }

        return ($cached ?? Cache::get($key . '.last') ?? $this->emptyStatistics($session)) + ['building' => true];
    }

    /** build now and cache (used by the background job, and directly for a small school) */
    public function rebuild(string $session, User $user): array
    {
        $scope = $user->classScope('students.reports');
        $key = $this->statsKey($session, $scope);
        $stats = $this->buildStatistics($session, $scope);

        Cache::put($key, $stats, $this->isLarge($session, $scope) ? self::LARGE_CACHE_SECONDS : self::CACHE_SECONDS);
        Cache::put($key . '.last', $stats, 86400 * 7);

        return $stats;
    }

    public function unlock(string $session, ?User $user): void
    {
        if ($user) {
            Cache::forget($this->statsKey($session, $user->classScope('students.reports')) . '.lock');
        }
    }

    private function statsKey(string $session, ?array $scope): string
    {
        return 'sm.stats.' . $session . '.' . ($scope === null ? 'all' : md5(implode(',', $scope)));
    }

    private function isLarge(string $session, ?array $scope): bool
    {
        $q = DB::table('students')->whereNull('deleted_at')->where('academic_year', (int) $session)->when($scope !== null, fn ($w) => $w->whereIn('class_id', $scope ?: [0]));

        return DB::query()->fromSub($q->select(DB::raw('1'))->limit(self::LARGE + 1), 't')->count() > self::LARGE;
    }

    private function emptyStatistics(string $session): array
    {
        return ['session' => $session, 'total' => 0, 'active' => 0, 'by_status' => [], 'gender' => [], 'religion' => [], 'new' => 0, 'old' => 0, 'brn_missing' => 0, 'classes' => [], 'built_at' => null];
    }

    /**
     * Three passes over the session's enrollments, however many there are:
     *   1. by status                          (students only)
     *   2. by class, with gender and new/old  (students only: gender is kept on the enrollment too)
     *   3. by religion, and the BRN count     (students joined to profiles once)
     * "New" means the child was not promoted from an earlier session (a first admission in this session).
     */
    private function buildStatistics(string $session, ?array $scope): array
    {
        $live = fn () => DB::table(DB::raw('`students` force index (idx_students_stats)'))->whereNull('students.deleted_at')->where('students.academic_year', (int) $session)
            ->when($scope !== null, fn (Builder $q) => $q->whereIn('students.class_id', $scope ?: [0]));

        $byStatus = $live()->selectRaw('students.status, COUNT(*) n')->groupBy('students.status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $total = array_sum($byStatus);

        $perClass = $live()->where('students.status', 'active')
            ->selectRaw("students.class_id, COUNT(*) total, SUM(LOWER(students.gender) = 'male') male, SUM(LOWER(students.gender) = 'female') female, SUM(students.promoted_from_id IS NULL) new_n")
            ->groupBy('students.class_id')->get()->keyBy('class_id');

        $meta = DB::table('classes')->leftJoin('study_groups', 'study_groups.id', '=', 'classes.study_group_id')->where('classes.academic_year', $session)
            ->whereIn('classes.id', $perClass->keys()->all() ?: [0])->get(['classes.id', 'classes.class_name', 'classes.class_title', 'classes.section', 'classes.section_title', 'study_groups.name as group_name'])->keyBy('id');

        $classes = $perClass->map(function ($r, $id) use ($meta) {
            $m = $meta[$id] ?? null;

            return ['id' => (int) $id, 'class' => $m ? \App\Models\SchoolClass::joinLabel($m->class_name, $m->class_title) : '—', 'section' => $m && ($m->section || $m->section_title) ? \App\Models\SchoolClass::joinLabel((string) $m->section, $m->section_title) : null, 'group' => $m->group_name ?? null, 'total' => (int) $r->total,
                    'male' => (int) $r->male, 'female' => (int) $r->female, 'other' => (int) $r->total - (int) $r->male - (int) $r->female];
        })->sortBy(fn ($r) => [$this->classRank($r['class']), $r['class'], $r['section']])->values()->all();

        $gender = ['Male' => array_sum(array_column($classes, 'male')), 'Female' => array_sum(array_column($classes, 'female'))];
        $other = array_sum(array_column($classes, 'other'));

        $gender = array_filter($gender + ['Unknown' => $other]);
        $new = (int) $perClass->sum('new_n');
        $active = (int) $perClass->sum('total');

        $religion = [];
        $brnMissing = 0;

        foreach ($live()->where('students.status', 'active')->join('student_profiles', 'student_profiles.id', '=', 'students.profile_id')
            ->selectRaw("COALESCE(NULLIF(student_profiles.religion, ''), 'Not given') r, COUNT(*) n, SUM(student_profiles.brn IS NULL) no_brn")->groupBy('r')->orderByDesc('n')->get() as $row) {
            $religion[$row->r] = (int) $row->n;
            $brnMissing += (int) $row->no_brn;
        }

        return [
            'session'    => $session,
            'total'      => $total,
            'active'     => (int) ($byStatus['active'] ?? 0),
            'by_status'  => $byStatus,
            'gender'     => $gender,
            'religion'   => $religion,
            'new'        => $new,
            'old'        => $active - $new,
            'brn_missing' => $brnMissing,
            'classes'    => $classes,
            'built_at'   => now()->format('d M Y, h:i A'),
        ];
    }

    /** "Class 10" sorts after "Class 9": order by the number inside the name */
    private function classRank(string $name): int
    {
        return preg_match('/(\d+)/', $name, $m) ? (int) $m[1] : 999;
    }
}
