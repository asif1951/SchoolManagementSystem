<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One student's enrollment in one session (class, roll, status …). The child's permanent identity is
 * the linked StudentProfile; the personal columns here are a synchronized copy of it (see ASSUMPTIONS.md).
 */
class Student extends Model
{
    use HasFactory, \Illuminate\Database\Eloquent\SoftDeletes, \App\Models\Concerns\Audited;

    public function profile()
    {
        return $this->belongsTo(StudentProfile::class, 'profile_id')->withTrashed();
    }

    /**
     * Where a student stands. Only "active" students are counted in classes, marksheets,
     * tabulation sheets and the dashboard; the others keep every record (marks, attendance,
     * fees) but stop appearing in current work. "inactive" is the old catch-all, kept so
     * existing records stay valid.
     */
    public const STATUSES = [
        'active'      => 'Active',
        'suspended'   => 'Suspended',
        'transferred' => 'Transferred out',
        'dropped'     => 'Dropped out',
        'passed_out'  => 'Passed out',
        'inactive'    => 'Inactive',
    ];

    protected $casts = [
        'status_changed_on' => 'date',
    ];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /** The name an audit-log line shows for this student. */
    public function auditLabel(): string
    {
        return trim($this->student_name . ' (' . $this->student_id . ')');
    }

    /**
     * `class` and `section` are no longer stored on students — they are read from
     * the linked class so they can never go out of sync. Appended so existing
     * pages (student.class / student.section) keep working. Eager-load
     * `schoolClass` when listing students to avoid extra queries.
     */
    protected $appends = ['class', 'section', 'status_label'];

    public function getClassAttribute(): ?string
    {
        return $this->schoolClass?->class_name;
    }

    public function getSectionAttribute(): ?string
    {
        return $this->schoolClass?->section;
    }

    /** true only while TransferService::reinstate() runs */
    private static bool $reinstating = false;

    /** Run $fn with the "transferred → anything else" lock lifted (used by the reinstate workflow only). */
    public static function reinstating(callable $fn)
    {
        self::$reinstating = true;

        try {
            return $fn();
        } finally {
            self::$reinstating = false;
        }
    }

    protected static function booted(): void
    {
        // A transferred student cannot be made active (or anything else) by any other route: not the edit form,
        // not the status screen, not an import. Only a user holding students.reinstate_student, with a reason.
        static::updating(function (Student $student) {
            if ($student->isDirty('status') && $student->getOriginal('status') === 'transferred' && $student->status !== 'transferred' && ! self::$reinstating) {
                throw new \DomainException('A transferred student can only be reinstated by someone with that permission, who must give a reason.');
            }
        });

        static::creating(function (Student $student) {
            $student->public_token ??= (string) \Illuminate\Support\Str::ulid();

            // permanent 8-digit ID, given once at first admission
            if (blank($student->student_id)) {
                $student->student_id = \App\Support\PersonId::next(\App\Support\PersonId::STUDENT);
            }

            // every enrollment belongs to a profile: the child's existing one, or a new one
            if (blank($student->profile_id)) {
                $student->profile_id = StudentProfile::forEnrollment($student)->id;
            }
        });

        // a change of class inside a session is remembered, so attendance rosters of earlier dates stay right
        static::updated(function (Student $student) {
            if ($student->wasChanged('class_id') && $student->getOriginal('class_id')) {
                \Illuminate\Support\Facades\DB::table('student_class_moves')->insert([
                    'student_id' => $student->id, 'from_class_id' => $student->getOriginal('class_id'), 'to_class_id' => $student->class_id,
                    'moved_on' => now('Asia/Dhaka')->toDateString(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        // a personal field edited on an enrollment row is an edit of the child's profile
        static::updated(function (Student $student) {
            if (! $student->profile_id) {
                return;
            }

            $payload = [];
            foreach (StudentProfile::ENROLLMENT_COLUMN as $field => $column) {
                if ($column !== 'admission_date' && $student->wasChanged($column)) {
                    $payload[$field] = $student->getAttributes()[$column];
                }
            }

            if ($payload) {
                StudentProfile::withTrashed()->find($student->profile_id)?->update($payload);
            }
        });

        // the last live enrollment of a child going away takes the profile with it (and coming back restores it)
        static::deleted(function (Student $student) {
            if ($student->profile_id && ! static::where('profile_id', $student->profile_id)->exists()) {
                StudentProfile::find($student->profile_id)?->delete();
            }
        });

        static::restored(function (Student $student) {
            StudentProfile::withTrashed()->find($student->profile_id)?->restore();
        });
    }

    protected $fillable = [
        'profile_id',

        // Student Information
        'student_name',
        'student_id',
        'public_token',
        'date_of_birth',
        'gender',
        'blood_group',
        'student_photo',
        
        // Academic Information
        'academic_year',
        'class_id',
        'promoted_from_id',
        'study_group_id',
        'roll_number',
        'admission_date',
        'status',
        'status_changed_on',
        'status_reason',
        
        // Father's Information
        'father_name',
        'father_phone',
        
        // Mother's Information
        'mother_name',
        'mother_phone',
        
        // Guardian Information
        'guardian_name',
        'guardian_phone',
        'guardian_relation',
        
        // Address
        'present_address',
        'permanent_address',
    ];

    /**
     * The 8-digit ID the next new student will get (see App\Support\PersonId), shown on the
     * admission form without using the number up.
     */
    public static function nextStudentId(?int $year = null): string
    {
        return \App\Support\PersonId::peek(\App\Support\PersonId::STUDENT, $year);
    }

    /**
     * What stops this student from being deleted. A student with marks, attendance, fee payments
     * or a later-year record is part of the school's history: change the status instead.
     * Only a mistaken entry (nothing recorded yet) may be deleted.
     *
     * @return array<string,int> label => how many
     */
    public function deletionBlockers(): array
    {
        $count = fn (string $table, string $column = 'student_id') => (int) \Illuminate\Support\Facades\DB::table($table)->where($column, $this->id)->count();

        return array_filter([
            'marks'            => $count('results'),
            'attendance days'  => $count('attendance_records') + $count('attendances'),
            'leave applications' => $count('student_leaves'),
            'fee payments'     => \Illuminate\Support\Facades\Schema::hasTable('fee_payments') ? $count('fee_payments') : 0,
            'later-year records' => $count('students', 'promoted_from_id'),
        ]);
    }

    /**
     * Students already in the system who look like the one being admitted: the same name and
     * either the same date of birth or a shared parent / guardian phone number.
     *
     * @param  array<int,?string>  $phones
     * @return \Illuminate\Support\Collection<int,Student>
     */
    public static function possibleDuplicates(?string $name, ?string $dateOfBirth, array $phones = [], ?int $ignoreId = null)
    {
        $name   = trim((string) $name);
        // digits only; a number shorter than 7 digits is too weak to call two people the same family
        $phones = array_values(array_filter(
            array_map(fn ($p) => preg_replace('/\D+/', '', (string) $p), $phones),
            fn ($p) => strlen($p) >= 7
        ));

        if ($name === '' || ($dateOfBirth === null && ! $phones)) {
            return collect();
        }

        return static::query()
            ->whereRaw('LOWER(student_name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($dateOfBirth, $phones) {
                if ($dateOfBirth !== null) {
                    $q->orWhere('date_of_birth', $dateOfBirth);
                }

                foreach (['father_phone', 'mother_phone', 'guardian_phone'] as $column) {
                    foreach ($phones as $phone) {
                        $q->orWhereRaw("REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . ltrim($phone, '0') ]);
                    }
                }
            })
            ->with('schoolClass:id,class_name,section')
            ->limit(3)
            ->get();
    }

    /** The previous year's record of this same student (null for a first-year record). */
    public function promotedFrom()
    {
        return $this->belongsTo(self::class, 'promoted_from_id');
    }

    /** Only the students in classes $user may use for $ability. */
    public function scopeVisibleTo($query, User $user, string $ability)
    {
        $ids = $user->classScope($ability);

        return $ids === null ? $query : $query->whereIn($this->getTable() . '.class_id', $ids);
    }

    public function studyGroup()
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    /** The optional subject(s) this student chose. */
    public function optionalSubjects()
    {
        return $this->belongsToMany(Subject::class, 'student_optional_subjects', 'student_id', 'subject_id')->withTimestamps();
    }

    /** The student's own group, or the class's group for a group-wise class. */
    public function effectiveGroupId(): ?int
    {
        return $this->study_group_id ?? $this->schoolClass?->study_group_id;
    }

    /**
     * Names of the subjects this student takes: the class's compulsory subjects that apply to
     * their group, plus the optional subjects they chose.
     *
     * @return array<int,string>
     */
    public function subjectsTaken(): array
    {
        $class = $this->schoolClass;
        if (! $class) {
            return [];
        }

        $group  = $this->effectiveGroupId();
        $chosen = $this->optionalSubjects()->pluck('subjects.id')->all();

        return collect($class->subjectPlan())
            ->filter(fn ($p) => ($p['group_ids'] === [] || ($group !== null && in_array($group, $p['group_ids'], true)))
                && (! $p['is_optional'] || in_array($p['subject_id'], $chosen, true)))
            ->pluck('name')
            ->values()
            ->all();
    }

    public function takesSubject(string $name): bool
    {
        return in_array($name, $this->subjectsTaken(), true);
    }

    /**
     * Record the student's optional subject choice(s).
     *
     * @param array<int,int> $subjectIds
     * @throws \InvalidArgumentException when a chosen subject is not an optional subject open to them
     */
    public function chooseOptionalSubjects(array $subjectIds): void
    {
        $group = $this->effectiveGroupId();

        $allowed = collect($this->schoolClass?->subjectPlan() ?? [])
            ->filter(fn ($p) => $p['is_optional'] && ($p['group_ids'] === [] || ($group !== null && in_array($group, $p['group_ids'], true))))
            ->pluck('subject_id')
            ->all();

        foreach ($subjectIds as $id) {
            if (! in_array((int) $id, $allowed, true)) {
                throw new \InvalidArgumentException('That subject is not an optional subject open to this student.');
            }
        }

        $this->optionalSubjects()->sync(array_map('intval', $subjectIds));
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function attendanceRecords()
    {
        return $this->hasMany(\App\Models\Attendance\Record::class);
    }

    public function results()
    {
        return $this->hasMany(ResultInput::class);
    }

    public function feePayments()
    {
        return $this->hasMany(FeePayment::class);
    }
}
