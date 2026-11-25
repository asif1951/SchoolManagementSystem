<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'class_name',
        'class_title',
        'section',
        'section_title',
        'study_group_id',
        'shift_id',
        'medium_id',
        'academic_year',
        'class_teacher_id',
        'status',
        'max_students',
        'description',
    ];

    /**
     * Always load the teacher's name with the class — `class_teacher` (below) is
     * read in many list views and must not cost one query per class.
     */
    protected $with = ['currentTeacher:id,name', 'subjectModels:subjects.id,subjects.name', 'studyGroup:id,name', 'shift:id,name', 'medium:id,name'];

    /** Keeps `class.class_teacher` (the name) available to the existing pages. */
    protected $appends = ['class_teacher', 'subjects', 'group_name', 'shift_name', 'medium_name', 'class_label', 'section_label'];

    protected $casts = [];

    /**
     * Resolve the single active class row for (name, section, year).
     * An empty section is treated the same as NULL.
     */
    /** Only the classes $user may use for $ability (all of them for an admin / school-wide grant). */
    public function scopeVisibleTo($query, User $user, string $ability)
    {
        $ids = $user->classScope($ability);

        return $ids === null ? $query : $query->whereIn($this->getTable() . '.id', $ids);
    }

    public function currentTeacher()
    {
        return $this->belongsTo(User::class, 'class_teacher_id');
    }

    public function teacherAssignments()
    {
        return $this->hasMany(ClassTeacherAssignment::class, 'class_id')->orderByDesc('starts_on')->orderByDesc('id');
    }

    // ------------------------------------------------------------------
    // Subjects (master list in `subjects`, offered per class in `class_subjects`)
    // ------------------------------------------------------------------

    public function subjectModels()
    {
        return $this->belongsToMany(Subject::class, 'class_subjects', 'class_id', 'subject_id')
            ->withPivot(['sort_order', 'is_optional'])
            ->orderBy('class_subjects.sort_order')
            ->orderBy('subjects.name');
    }

    /** Names of this class's subjects, in order — what the rest of the app has always read. */
    public function getSubjectsAttribute(): array
    {
        return $this->subjectModels->pluck('name')->values()->all();
    }

    public function studyGroup()
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function medium()
    {
        return $this->belongsTo(Medium::class, 'medium_id');
    }

    public function getShiftNameAttribute(): ?string
    {
        return $this->shift?->name;
    }

    public function getMediumNameAttribute(): ?string
    {
        return $this->medium?->name;
    }

    /** "Morning · Bangla" — for headings and lists. */
    public function getShiftMediumLabelAttribute(): string
    {
        return collect([$this->shift_name, $this->medium_name])->filter()->implode(' · ');
    }

    /**
     * A class made without a shift or medium (older code paths, imports) gets the first active
     * entry of each list, so every class always has both.
     */
    protected static function booted(): void
    {
        static::creating(function (SchoolClass $class) {
            $class->shift_id  ??= Shift::active()->ordered()->value('id') ?? Shift::ordered()->value('id');
            $class->medium_id ??= Medium::active()->ordered()->value('id') ?? Medium::ordered()->value('id');
        });

        // the student module caches the session and class dropdowns; a changed class clears them at once
        $forget = function (SchoolClass $class) {
            \Illuminate\Support\Facades\Cache::forget('sm.sessions');
            \Illuminate\Support\Facades\Cache::forget('sm.classes.' . $class->academic_year);
            \Illuminate\Support\Facades\Cache::forget('sm.classes.' . $class->getOriginal('academic_year'));
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /** "Podma (Class 1)" when the class has a title of its own, else "Class 1" */
    public function getClassLabelAttribute(): string
    {
        return static::joinLabel($this->class_name, $this->class_title);
    }

    /** "Rose (A)" when the section has a title of its own, else "A" (null when there is no section) */
    public function getSectionLabelAttribute(): ?string
    {
        return filled($this->section) || filled($this->section_title) ? static::joinLabel((string) $this->section, $this->section_title) : null;
    }

    /** the one label every screen shows for a class: "Podma (Class 1) — Rose (A)" */
    public function getFullLabelAttribute(): string
    {
        return trim($this->class_label . ($this->section_label ? ' — ' . $this->section_label : ''));
    }

    /** the compact label other screens use: "Podma (Class 1) Rose (A)" — class and section separated by a space */
    public function getShortLabelAttribute(): string
    {
        return trim($this->class_label . ($this->section_label ? ' ' . $this->section_label : ''));
    }

    /** name + optional title: the title leads (it is what the school calls it), the name stays visible beside it */
    public static function joinLabel(string $name, ?string $title): string
    {
        $title = trim((string) $title);

        return $title !== '' && mb_strtolower($title) !== mb_strtolower($name) ? ($name !== '' ? "{$title} ({$name})" : $title) : $name;
    }

    /** "Rose, Lily" → ['Rose', 'Lily'] by position, empty entries kept so the titles line up with the sections */
    public static function parseTitles(?string $input): array
    {
        return array_map('trim', preg_split('/[,;\n]+/', (string) $input) ?: []);
    }

    public function getGroupNameAttribute(): ?string
    {
        return $this->studyGroup?->name;
    }

    /**
     * Set this class's subjects.
     *
     * Each item is either a name ("Physics") or a detailed array:
     *   ['name' => 'Physics', 'optional' => false, 'groups' => [3]]
     *     optional → compulsory (false) or an optional subject students choose (true)
     *     groups   → study-group ids the subject is limited to (empty / missing = everyone)
     * When an item is just a name, an existing subject keeps its optional flag and group limits.
     *
     * Names are matched to the master list case-insensitively; an unknown name creates a new
     * subject. Removed subjects also end their subject-teacher stint in this class.
     *
     * @param array<int,string|array<string,mixed>> $items
     */
    public function syncSubjects(array $items): void
    {
        $before   = $this->subjects;
        $existing = \Illuminate\Support\Facades\DB::table('class_subjects')->where('class_id', $this->id)->get()->keyBy('subject_id');

        $attach = [];
        $groups = [];

        foreach (array_values($items) as $position => $item) {
            $spec = is_array($item) ? $item : ['name' => $item];
            $name = Subject::normalize((string) ($spec['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $subject  = Subject::findOrCreateByName($name);
            $optional = array_key_exists('optional', $spec)
                ? (bool) $spec['optional']
                : (bool) ($existing->get($subject->id)->is_optional ?? false);

            $attach[$subject->id] = ['sort_order' => $position, 'is_optional' => $optional];

            if (array_key_exists('groups', $spec)) {
                $groups[$subject->id] = array_values(array_unique(array_map('intval', (array) $spec['groups'])));
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($attach, $groups) {
            $this->subjectModels()->sync($attach);

            $rowIds = \Illuminate\Support\Facades\DB::table('class_subjects')->where('class_id', $this->id)->pluck('id', 'subject_id');

            foreach ($groups as $subjectId => $groupIds) {
                $rowId = $rowIds[$subjectId] ?? null;
                if (! $rowId) {
                    continue;
                }

                \Illuminate\Support\Facades\DB::table('class_subject_groups')->where('class_subject_id', $rowId)->delete();

                foreach ($groupIds as $groupId) {
                    \Illuminate\Support\Facades\DB::table('class_subject_groups')->insert(['class_subject_id' => $rowId, 'study_group_id' => $groupId]);
                }
            }
        });

        $this->unsetRelation('subjectModels');
        $this->load('subjectModels');

        $removed = array_values(array_diff($before, $this->subjects));
        if ($removed) {
            ClassSubjectTeacher::query()
                ->where('class_id', $this->id)
                ->whereIn('subject', $removed)
                ->whereNull('ends_on')
                ->update(['ends_on' => now()->toDateString(), 'updated_at' => now()]);

            // and its periods and plan leave the routine
            app(\App\Services\RoutineService::class)->subjectsRemoved($this, $removed);
        }
    }

    /**
     * The class's subjects with their rules, in order:
     * [['subject_id', 'name', 'is_optional', 'group_ids' => [] ], ...]   (empty group_ids = everyone)
     *
     * @return array<int,array<string,mixed>>
     */
    public function subjectPlan(): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('class_subjects as cs')
            ->join('subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('cs.class_id', $this->id)
            ->orderBy('cs.sort_order')
            ->orderBy('s.name')
            ->get(['cs.id as cs_id', 's.id as subject_id', 's.name', 'cs.is_optional']);

        $scopes = \Illuminate\Support\Facades\DB::table('class_subject_groups')
            ->whereIn('class_subject_id', $rows->pluck('cs_id'))
            ->get()
            ->groupBy('class_subject_id');

        return $rows->map(fn ($r) => [
            'subject_id'  => (int) $r->subject_id,
            'name'        => $r->name,
            'is_optional' => (bool) $r->is_optional,
            'group_ids'   => ($scopes->get($r->cs_id) ?? collect())->pluck('study_group_id')->map(fn ($g) => (int) $g)->values()->all(),
        ])->values()->all();
    }

    /**
     * Fill this class's subjects from the curriculum template for its class level
     * (common subjects + the extras of its study group). A mixed class (no group) gets every
     * group's extras, each limited to that group.
     *
     * Existing subjects are kept unless $replace is true. Returns false when nothing was applied.
     */
    public function applyCurriculum(bool $replace = false): bool
    {
        if (! $replace && $this->subjectModels->isNotEmpty()) {
            return false;
        }

        $rows = CurriculumSubject::query()
            ->with('subject:id,name')
            ->where('class_name', $this->class_name)
            ->when($this->study_group_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('study_group_id')->orWhere('study_group_id', $this->study_group_id)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return false;
        }

        $items = [];

        foreach ($rows as $row) {
            $key = $row->subject_id;
            $name = $row->subject->name;

            if (! isset($items[$key])) {
                // common row → everyone; group row in a mixed class → limited to that group
                $limited = ! $this->study_group_id && $row->study_group_id;
                $items[$key] = ['name' => $name, 'optional' => $row->is_optional, 'groups' => $limited ? [$row->study_group_id] : [], '_common' => ! $row->study_group_id];
                continue;
            }

            if ($row->study_group_id && ! $items[$key]['_common'] && ! $this->study_group_id) {
                $items[$key]['groups'][] = $row->study_group_id;
            } elseif (! $row->study_group_id) {
                $items[$key]['groups'] = [];
                $items[$key]['_common'] = true;
            }
        }

        $this->syncSubjects(array_map(function ($i) {
            unset($i['_common']);
            return $i;
        }, array_values($items)));

        return true;
    }

    /**
     * Students of this class who take $subjectName — the roster for entering that subject's marks.
     * Honours group limits and optional choices.
     */
    public function studentsTaking(string $subjectName): \Illuminate\Database\Eloquent\Builder
    {
        $plan = collect($this->subjectPlan())->firstWhere('name', $subjectName);

        $query = Student::query()->where('students.class_id', $this->id);

        if (! $plan) {
            return $query->whereRaw('1 = 0');
        }

        if ($plan['is_optional']) {
            $query->whereIn('students.id', \Illuminate\Support\Facades\DB::table('student_optional_subjects')
                ->where('subject_id', $plan['subject_id'])
                ->select('student_id'));
        }

        if ($plan['group_ids']) {
            if ($this->study_group_id) {
                // group-wise class: the whole class is one group
                return in_array($this->study_group_id, $plan['group_ids'], true) ? $query : $query->whereRaw('1 = 0');
            }

            $query->whereIn('students.study_group_id', $plan['group_ids']);
        }

        return $query;
    }

    // ------------------------------------------------------------------
    // Subject teachers
    // ------------------------------------------------------------------

    public function subjectAssignments()
    {
        return $this->hasMany(ClassSubjectTeacher::class, 'class_id')->orderByDesc('starts_on')->orderByDesc('id');
    }

    /**
     * The MAIN current teacher of each subject: subject => assignment (with ->user_id / ->teacher_name). A subject may have
     * several teachers (see currentSubjectTeacherLists()); this is the first one assigned, for the places that need one name.
     */
    public function currentSubjectTeachers(): \Illuminate\Support\Collection
    {
        return $this->currentSubjectTeacherLists()->map(fn ($list) => $list->first());
    }

    /** Every current teacher of each subject: subject => collection of assignments, in the order they were assigned. */
    public function currentSubjectTeacherLists(): \Illuminate\Support\Collection
    {
        return ClassSubjectTeacher::query()
            ->where('class_id', $this->id)
            ->whereNull('ends_on')
            ->orderBy('id')
            ->get()
            ->groupBy('subject');
    }

    /**
     * Make $teacher THE teacher of $subject in this class (null = nobody): anyone else on the subject is let go.
     * To have several teachers use setSubjectTeachers().
     *
     * @throws \InvalidArgumentException when the subject is not one of this class's subjects
     */
    public function assignSubjectTeacher(string $subject, ?User $teacher, ?User $by = null, $on = null): array
    {
        return $this->setSubjectTeachers($subject, $teacher ? [$teacher] : [], $by, $on);
    }

    /**
     * Set the whole list of teachers of $subject in this class. Teachers who stay are left alone (no new history line),
     * teachers no longer in the list have their stint closed, new ones get a stint opened. An empty list means nobody.
     * Same single-write-path idea as assignTeacher(): the history can never disagree with who teaches now.
     *
     * @param  iterable<int,User>  $teachers
     * @return array{changed:int, moved:array<string,int>, without:array<int,string>}  what had to change in this class's routine because a teacher left the subject
     *
     * @throws \InvalidArgumentException when the subject is not one of this class's subjects
     */
    public function setSubjectTeachers(string $subject, iterable $teachers, ?User $by = null, $on = null): array
    {
        if (! in_array($subject, $this->subjects ?? [], true)) {
            throw new \InvalidArgumentException("\"{$subject}\" is not a subject of this class.");
        }

        $wanted = collect($teachers)->filter()->unique('id')->values();
        $on = $on ? \Illuminate\Support\Carbon::parse($on) : now();

        $left = [];

        \Illuminate\Support\Facades\DB::transaction(function () use ($subject, $wanted, $by, $on, &$left) {
            $open = ClassSubjectTeacher::query()
                ->where('class_id', $this->id)
                ->where('subject', $subject)
                ->whereNull('ends_on')
                ->lockForUpdate()
                ->get();

            $keep = $wanted->pluck('id')->map(fn ($i) => (int) $i)->all();

            // let go of everyone who is not in the new list (and of a duplicate open row, should one ever exist)
            $stay = [];
            $close = [];

            foreach ($open as $row) {
                if (in_array((int) $row->user_id, $keep, true) && ! isset($stay[(int) $row->user_id])) {
                    $stay[(int) $row->user_id] = true;
                } else {
                    $close[] = $row->id;
                    $row->user_id && ! in_array((int) $row->user_id, $keep, true) && ($left[] = (int) $row->user_id);
                }
            }

            if ($close) {
                ClassSubjectTeacher::query()->whereIn('id', $close)->update(['ends_on' => $on->toDateString(), 'updated_at' => now()]);
            }

            foreach ($wanted as $teacher) {
                if (isset($stay[(int) $teacher->id])) {
                    continue;
                }

                ClassSubjectTeacher::create([
                    'class_id'     => $this->id,
                    'subject'      => $subject,
                    'user_id'      => $teacher->id,
                    'teacher_name' => $teacher->name,
                    'starts_on'    => $on->toDateString(),
                    'assigned_by'  => $by?->id,
                ]);
            }
        });

        // a teacher who no longer teaches the subject cannot stay on its periods in the routine
        return $left ? app(\App\Services\RoutineService::class)->teacherLeftSubject($this, $subject, array_values(array_unique($left))) : ['changed' => 0, 'moved' => [], 'without' => []];
    }

    /** Display name of the current class teacher (empty string when unassigned). */
    public function getClassTeacherAttribute(): string
    {
        return $this->currentTeacher?->name ?? '';
    }

    /**
     * Make $teacher the class teacher. The single write path for this: it closes the
     * current assignment, opens a new one and updates the fast-lookup column together,
     * so the history can never disagree with the current teacher.
     * Passing null clears the teacher.
     */
    public function assignTeacher(?User $teacher, ?User $by = null, $on = null): void
    {
        $on = $on ? \Illuminate\Support\Carbon::parse($on) : now();

        if ($teacher && (int) $this->class_teacher_id === (int) $teacher->id
            && $this->teacherAssignments()->whereNull('ends_on')->exists()) {
            return; // already the current teacher — nothing to record
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($teacher, $by, $on) {
            ClassTeacherAssignment::query()
                ->where('class_id', $this->id)
                ->whereNull('ends_on')
                ->update(['ends_on' => $on->toDateString(), 'updated_at' => now()]);

            if ($teacher) {
                ClassTeacherAssignment::create([
                    'class_id'     => $this->id,
                    'user_id'      => $teacher->id,
                    'teacher_name' => $teacher->name,
                    'starts_on'    => $on->toDateString(),
                    'assigned_by'  => $by?->id,
                ]);
            }

            $this->forceFill(['class_teacher_id' => $teacher?->id])->save();
        });

        $this->unsetRelation('currentTeacher');
    }

    /**
     * Active classes matching a name + section + year (and a study group when one is given).
     * A section name can repeat across groups (Class 9 Science A / Class 9 Arts A).
     */
    public static function matchesFor(?string $name, ?string $section, $year, ?int $groupId = null, ?int $shiftId = null, ?int $mediumId = null)
    {
        if ($name === null || $name === '') {
            return collect();
        }

        return static::query()
            ->where('class_name', $name)
            ->whereRaw("COALESCE(section, '') = ?", [(string) $section])
            ->where('academic_year', (string) $year)
            ->where('status', 'active')
            ->when($groupId, fn ($q) => $q->where('study_group_id', $groupId))
            ->when($shiftId, fn ($q) => $q->where('shift_id', $shiftId))
            ->when($mediumId, fn ($q) => $q->where('medium_id', $mediumId))
            ->get();
    }

    /**
     * The single active class for (name, section, year[, group]).
     * Returns null when there is none, or when the section exists in several study groups and
     * no group was given (the caller must then ask which group is meant).
     */
    public static function resolveActive(?string $name, ?string $section, $year, ?int $groupId = null, ?int $shiftId = null, ?int $mediumId = null): ?self
    {
        $matches = static::matchesFor($name, $section, $year, $groupId, $shiftId, $mediumId);

        // the same name + section can exist in several groups, shifts or mediums: the caller must say which
        if ($matches->count() > 1) {
            return null;
        }

        return $matches->first();
    }

    /** The message to show when resolveActive() found nothing. */
    public static function resolutionError(?string $name, ?string $section, $year, ?int $groupId = null, ?int $shiftId = null, ?int $mediumId = null): string
    {
        $matches = static::matchesFor($name, $section, $year, $groupId, $shiftId, $mediumId);

        if ($matches->count() > 1) {
            $asks = [];

            if ($matches->pluck('study_group_id')->unique()->count() > 1) {
                $asks[] = 'study group';
            }
            if ($matches->pluck('shift_id')->unique()->count() > 1) {
                $asks[] = 'shift';
            }
            if ($matches->pluck('medium_id')->unique()->count() > 1) {
                $asks[] = 'medium';
            }

            return 'This section exists more than once. Please choose the ' . implode(' and ', $asks ?: ['class']) . '.';
        }

        return 'Selected class/section is not available for this academic year.';
    }

    /** "A, B ; C" becomes ['A', 'B', 'C'].  Empty input gives [null] (a class without sections). */
    public static function parseSections(?string $input): array
    {
        $parts = preg_split('/[,;\n]+/', (string) $input) ?: [];
        $parts = array_values(array_unique(array_filter(array_map('trim', $parts), fn ($p) => $p !== '')));

        return $parts ?: [null];
    }

    /**
     * Create one class per section. Sections that already exist are skipped.
     * New classes get the given subjects, or the curriculum template when none are given.
     *
     * @param  array<string,mixed>  $attrs  class_name, academic_year, study_group_id, shift_id, medium_id, status, max_students, description
     * @return array{created: array<int,self>, skipped: array<int,string>}
     */
    public static function createForSections(array $attrs, ?string $sections, array $subjects, ?User $teacher, ?User $by = null, ?string $sectionTitles = null): array
    {
        $created = [];
        $skipped = [];
        $titles = static::parseTitles($sectionTitles);

        foreach (array_values(static::parseSections($sections)) as $i => $section) {
            $exists = static::query()
                ->where('class_name', $attrs['class_name'])
                ->where('section', $section)
                ->where('academic_year', $attrs['academic_year'])
                ->where('study_group_id', $attrs['study_group_id'] ?? null)
                ->when($attrs['shift_id'] ?? null, fn ($q, $v) => $q->where('shift_id', $v))
                ->when($attrs['medium_id'] ?? null, fn ($q, $v) => $q->where('medium_id', $v))
                ->exists();

            if ($exists) {
                $skipped[] = (string) ($section ?? '(no section)');
                continue;
            }

            $class = static::create($attrs + ['section' => $section, 'section_title' => ($titles[$i] ?? '') !== '' ? $titles[$i] : null]);
            $class->syncSubjects($subjects);

            if (! $subjects) {
                $class->applyCurriculum();
            }

            $class->assignTeacher($teacher, $by);
            $created[] = $class;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public static function describeCreation(array $result): string
    {
        $n   = count($result['created']);
        $msg = $n > 1
            ? "{$n} classes created: " . collect($result['created'])->pluck('section')->implode(', ') . '.'
            : 'Class created successfully! 🎉';

        if ($result['skipped']) {
            $msg .= ' Already existed and skipped: ' . implode(', ', $result['skipped']) . '.';
        }

        return $msg;
    }

    /**
     * Make sure a year has class rows: if it has none, copy the class list
     * (name, section, teacher, subjects) from the nearest EARLIER year.
     */
    public static function ensureForYear(int $year): void
    {
        if (static::where('academic_year', (string) $year)->exists()) {
            return;
        }

        $sourceYear = static::where('academic_year', '<', (string) $year)
            ->orderByDesc('academic_year')
            ->value('academic_year');

        if (! $sourceYear) {
            return;
        }

        foreach (static::where('academic_year', $sourceYear)->orderBy('class_name')->orderBy('section')->get() as $src) {
            $new = static::firstOrCreate(
                [
                    'class_name'     => $src->class_name,
                    'section'        => $src->section,
                    'academic_year'  => (string) $year,
                    'study_group_id' => $src->study_group_id,
                    'shift_id'       => $src->shift_id,
                    'medium_id'      => $src->medium_id,
                ],
                [
                    'status'        => $src->status ?? 'active',
                    'class_title'   => $src->class_title,
                    'section_title' => $src->section_title,
                ]
            );

            // Same teacher by default; the admin changes it per class when needed.
            if ($new->wasRecentlyCreated) {
                $start = \Illuminate\Support\Carbon::create((int) $year, 1, 1);

                $new->syncSubjects(array_map(fn ($p) => [
                    'name' => $p['name'], 'optional' => $p['is_optional'], 'groups' => $p['group_ids'],
                ], $src->subjectPlan()));

                if ($src->currentTeacher) {
                    $new->assignTeacher($src->currentTeacher, null, $start);
                }

                foreach ($src->currentSubjectTeacherLists() as $subject => $assignments) {
                    $teachers = User::whereIn('id', $assignments->pluck('user_id')->filter())->get()->sortBy(fn ($u) => $assignments->pluck('user_id')->search($u->id));

                    if ($teachers->isNotEmpty() && in_array($subject, $new->subjects ?? [], true)) {
                        $new->setSubjectTeachers($subject, $teachers, null, $start);
                    }
                }
            }
        }
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'class_id');
    }
}
