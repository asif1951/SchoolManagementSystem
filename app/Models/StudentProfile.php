<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Support\Normalize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * The permanent identity of a child — one row per child, for life.
 * The per-session records (class, roll, status …) are `students` rows ("enrollments") pointing here.
 *
 * The profile is the source of truth for personal data. The personal columns repeated on each
 * enrollment row are a synchronized copy (see ASSUMPTIONS.md); this class keeps them in step and
 * writes every changed field to `student_field_histories`.
 */
class StudentProfile extends Model
{
    use SoftDeletes, Audited;

    /** the fields only an approver edits directly; everyone else goes through a change request */
    public const PROTECTED = ['name_en', 'name_bn', 'dob', 'father_name', 'mother_name', 'gender', 'brn'];

    /** the fields that are written to the history table when they change */
    public const TRACKED = [
        'name_en', 'name_bn', 'brn', 'dob', 'gender', 'religion', 'blood_group', 'father_name', 'mother_name',
        'guardian_name', 'guardian_mobile', 'guardian_relation', 'present_address', 'permanent_address',
        'photo', 'previous_school',
    ];

    /** profile field => column of the same data on the enrollment (`students`) row */
    public const ENROLLMENT_COLUMN = [
        'name_en'           => 'student_name',
        'dob'               => 'date_of_birth',
        'gender'            => 'gender',
        'blood_group'       => 'blood_group',
        'photo'             => 'student_photo',
        'father_name'       => 'father_name',
        'mother_name'       => 'mother_name',
        'guardian_name'     => 'guardian_name',
        'guardian_mobile'   => 'guardian_phone',
        'guardian_relation' => 'guardian_relation',
        'present_address'   => 'present_address',
        'permanent_address' => 'permanent_address',
        'admission_date'    => 'admission_date',
    ];

    protected $fillable = [
        'code', 'name_en', 'name_bn', 'brn', 'dob', 'gender', 'religion', 'blood_group', 'father_name', 'mother_name',
        'guardian_name', 'guardian_mobile', 'guardian_relation', 'present_address', 'permanent_address',
        'photo', 'previous_school', 'admission_date', 'created_by',
    ];

    protected $casts = ['dob' => 'date', 'admission_date' => 'date'];

    /** where a change comes from, for the history: screen | quick | import | approval | online | system */
    private static string $source = 'screen';

    /** who is making the change, when it is not the signed-in user (a service acting for someone) */
    private static ?int $actor = null;

    /** Run $fn with every change it makes recorded under $source, and under $actor if given (restored afterwards). */
    public static function inSource(string $source, callable $fn, ?User $actor = null)
    {
        $before = [self::$source, self::$actor];
        self::$source = $source;
        self::$actor = $actor?->id ?? self::$actor;

        try {
            return $fn();
        } finally {
            [self::$source, self::$actor] = $before;
        }
    }

    protected static function booted(): void
    {
        // the indexed, normalized copies are always derived, so no write path can forget them
        static::saving(function (StudentProfile $p) {
            $p->brn = Normalize::brn($p->brn);

            if ($p->isDirty('name_en') || blank($p->name_normalized)) {
                $p->name_normalized = Normalize::name($p->name_en);
            }

            if ($p->isDirty('guardian_mobile') || ($p->guardian_mobile && blank($p->guardian_mobile_norm))) {
                $p->guardian_mobile_norm = Normalize::mobile($p->guardian_mobile);
            }
        });

        static::updated(function (StudentProfile $p) {
            $changes = $p->getChanges();

            // 1. history: one line per changed personal field
            foreach (self::TRACKED as $field) {
                if (! array_key_exists($field, $changes)) {
                    continue;
                }

                StudentFieldHistory::create([
                    'profile_id' => $p->id,
                    'field'      => $field,
                    'old_value'  => self::plain($p->getOriginal($field)),
                    'new_value'  => self::plain($changes[$field]),
                    'changed_by' => self::$actor ?? auth()->id(),
                    'source'     => self::$source,
                ]);
            }

            // 2. keep the copy on every enrollment row in step
            $payload = [];
            foreach (self::ENROLLMENT_COLUMN as $field => $column) {
                if (array_key_exists($field, $changes)) {
                    $value = $p->getAttributes()[$field];
                    $payload[$column] = in_array($field, ['dob', 'admission_date'], true) ? Normalize::date($value) : $value;
                }
            }

            if ($payload) {
                DB::table('students')->where('profile_id', $p->id)->update($payload + ['updated_at' => now()]);
            }
        });
    }

    private static function plain($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // a date held in memory as "2014-05-03 00:00:00" is the date 2014-05-03
        return preg_match('/^(\d{4}-\d{2}-\d{2}) 00:00:00$/', (string) $value, $m) ? $m[1] : (string) $value;
    }

    // ------------------------------------------------------------------

    public function enrollments()
    {
        return $this->hasMany(Student::class, 'profile_id');
    }

    public function latestEnrollment()
    {
        return $this->hasOne(Student::class, 'profile_id')->latestOfMany('academic_year');
    }

    public function histories()
    {
        return $this->hasMany(StudentFieldHistory::class, 'profile_id')->orderByDesc('id');
    }

    public function changeRequests()
    {
        return $this->hasMany(StudentChangeRequest::class, 'profile_id')->orderByDesc('id');
    }

    /** Masked unless the user may see it all: 2004•••••••••123. */
    public function brnFor(?User $user): ?string
    {
        return $user?->canDo('students.view_full_brn') ? $this->brn : Normalize::maskBrn($this->brn);
    }

    public function getBrnMissingAttribute(): bool
    {
        return blank($this->brn);
    }

    public function auditLabel(): string
    {
        return $this->name_en . ' (' . $this->code . ')';
    }

    /** BRN is sensitive: never copy it into the audit log. */
    public function auditIgnore(): array
    {
        return ['brn', 'public_token'];
    }

    /**
     * The profile an enrollment belongs to: the existing one for its student code, or a new one
     * built from the enrollment's own columns (so older code paths that still create `students` rows keep working).
     */
    public static function forEnrollment(Student $s): self
    {
        $existing = static::withTrashed()->where('code', $s->student_id)->first();

        if ($existing) {
            return $existing;
        }

        return static::create([
            'code'              => $s->student_id,
            'name_en'           => $s->student_name,
            'dob'               => $s->date_of_birth,
            'gender'            => $s->gender,
            'blood_group'       => $s->blood_group,
            'father_name'       => $s->father_name,
            'mother_name'       => $s->mother_name,
            'guardian_name'     => $s->guardian_name,
            'guardian_mobile'   => $s->guardian_phone ?: ($s->father_phone ?: $s->mother_phone),
            'guardian_relation' => $s->guardian_relation,
            'present_address'   => $s->present_address,
            'permanent_address' => $s->permanent_address,
            'photo'             => $s->student_photo,
            'admission_date'    => $s->admission_date,
            'created_by'        => auth()->id(),
        ]);
    }
}
