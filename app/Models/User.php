<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // ============================================================
    // ✅ Role Constants
    // ============================================================
    const ROLE_ADMIN   = 'admin';
    const ROLE_TEACHER = 'teacher';
    const ROLE_USER    = 'user';
    const ROLE_FINANCE_HEAD = 'finance_head';
    const ROLE_CASHIER      = 'cashier';
    const ROLE_EXAM_CONTROLLER = 'exam_controller';

    // ============================================================
    // ✅ Status Constants
    // ============================================================
    const STATUS_ACTIVE    = 'active';
    const STATUS_INACTIVE  = 'inactive';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_PENDING   = 'pending';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected static function booted(): void
    {
        // Every account gets its permanent 8-digit ID when it is created. It never changes,
        // even if the person later moves to another role.
        static::creating(function (User $user) {
            if (blank($user->uid)) {
                $user->uid = \App\Support\PersonId::next(\App\Support\PersonId::typeForRole($user->role));
            }
        });
    }

    /** The email to sign in with for a typed value: an 8-digit ID is looked up, anything else is the email itself. */
    public static function loginEmailFor(?string $typed): ?string
    {
        $typed = trim((string) $typed);

        return \App\Support\PersonId::isValid($typed)
            ? (static::query()->where('uid', $typed)->value('email') ?? $typed)
            : $typed;
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'extra_abilities',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'extra_abilities'   => 'array',
        ];
    }

    /**
     * ✅ Default attributes
     * — Role = 'user'
     * — Status = 'active'
     */
    protected $attributes = [
        'role'   => self::ROLE_USER,
        'status' => self::STATUS_ACTIVE,
    ];

    // ============================================================
    // ✅ Role Helpers
    // ============================================================
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->role === self::ROLE_TEACHER;
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /**
     * ✅ Admin OR Teacher (staff access)
     */
    public function isStaff(): bool
    {
        return $this->role !== self::ROLE_USER;
    }

    // ============================================================
    // ✅ Abilities (what this user's ROLE allows) — see config/abilities.php
    // ============================================================

    /**
     * Ability strings granted by this user's role. Admin always gets '*'.
     * Entries look like "fees.collect" (whole school) or "attendance.take:own".
     *
     * @return array<int,string>
     */
    public function grantedAbilities(): array
    {
        if ($this->isAdmin()) {
            return ['*'];
        }

        $role = \App\Models\Role::abilityMap()[$this->role] ?? [];

        // permissions given to this one person on top of the role (never mass assignable)
        return $this->extra_abilities ? array_values(array_unique([...$role, ...$this->extra_abilities])) : $role;
    }

    /** True if the role grants the ability in ANY scope (whole school or own classes only). */
    public function hasAbility(string $ability): bool
    {
        $granted = $this->grantedAbilities();

        return in_array('*', $granted, true)
            || in_array($ability, $granted, true)
            || in_array($ability . ':own', $granted, true);
    }

    /**
     * Which classes does this ability apply to?
     *   null  → every class       (admin, or the ability is granted school-wide)
     *   [ids] → only these classes (granted as "ability:own" → classes they teach)
     *   []    → no class          (ability not granted)
     *
     * @return array<int,int>|null
     */
    public function classScope(string $ability): ?array
    {
        $granted = $this->grantedAbilities();

        if (in_array('*', $granted, true) || in_array($ability, $granted, true)) {
            return null;
        }

        if (in_array($ability . ':own', $granted, true)) {
            // For marks entry, "own" = classes I am class teacher of + classes where I teach a subject.
            if (in_array($ability, self::SUBJECT_AWARE_ABILITIES, true)) {
                return array_values(array_unique(array_merge($this->ownedClassIds(), array_keys($this->taughtSubjects()))));
            }

            return $this->ownedClassIds();
        }

        return [];
    }

    /** Abilities where being a SUBJECT teacher of a class counts as "own". */
    public const SUBJECT_AWARE_ABILITIES = ['results.view', 'results.enter', 'results.lock'];

    /**
     * Which of the class's subjects may this user use for $ability?
     *   null → all subjects   (admin, school-wide grant, or the class teacher)
     *   [..] → only these      (a subject teacher — just the subjects they teach in this class)
     *   []   → none
     *
     * @return array<int,string>|null
     */
    public function subjectsAllowedFor(SchoolClass $class, string $ability): ?array
    {
        $granted = $this->grantedAbilities();

        if (in_array('*', $granted, true) || in_array($ability, $granted, true)) {
            return null;
        }

        if (! in_array($ability . ':own', $granted, true)) {
            return [];
        }

        if (in_array($class->id, $this->ownedClassIds(), true)) {
            return null;                                   // class teacher: every subject of their class
        }

        return $this->taughtSubjects()[$class->id] ?? [];  // subject teacher: only theirs
    }

    /**
     * May the user do $ability — for a specific class when one is given
     * (scope respected), or at all when none is given.
     */
    public function canDo(string $ability, SchoolClass|int|null $class = null): bool
    {
        if ($class === null) {
            return $this->hasAbility($ability);
        }

        $scope = $this->classScope($ability);

        if ($scope === null) {
            return true;
        }

        $id = $class instanceof SchoolClass ? $class->id : (int) $class;

        return in_array($id, $scope, true);
    }

    // ============================================================
    // ✅ Class ownership (class teacher)
    // ============================================================

    /** Classes this user is the CURRENT class teacher of. */
    public function taughtClasses()
    {
        return $this->hasMany(SchoolClass::class, 'class_teacher_id');
    }

    /** @var array<int,array<int,string>>|null  class_id => [subjects] */
    private ?array $taughtSubjectCache = null;

    /**
     * Subjects this user currently teaches, per class: [class_id => ['Math', 'English'], ...].
     * One teacher can teach several subjects in many classes.
     *
     * @return array<int,array<int,string>>
     */
    public function taughtSubjects(): array
    {
        if ($this->taughtSubjectCache === null) {
            $this->taughtSubjectCache = ClassSubjectTeacher::query()
                ->where('user_id', $this->id)
                ->whereNull('ends_on')
                ->get(['class_id', 'subject'])
                ->groupBy('class_id')
                ->map(fn ($rows) => $rows->pluck('subject')->all())
                ->all();
        }

        return $this->taughtSubjectCache;
    }

    /** @var array<int,int>|null */
    private ?array $ownedClassIdCache = null;

    /** @return array<int,int> ids of the classes this user is class teacher of */
    public function ownedClassIds(): array
    {
        return $this->ownedClassIdCache ??= $this->taughtClasses()->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** Is this user an admin, or the class teacher of this class? (pure ownership, ignores role abilities) */
    public function canManageClass(SchoolClass|int|null $class): bool
    {
        if ($class === null) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        $id = $class instanceof SchoolClass ? $class->id : (int) $class;

        return in_array($id, $this->ownedClassIds(), true);
    }

    /** First page this user should land on after login. */
    public function homePath(): string
    {
        return match (true) {
            $this->hasAbility('dashboard.view') => '/dashboard',
            $this->hasAbility('fees.collect')   => '/fees',
            $this->hasAbility('teaching.view_all') => '/teaching',
            $this->hasAbility('students.view')  => '/students',
            $this->hasAbility('attendance.view') => '/attendance',
            default                              => '/',
        };
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    // ============================================================
    // ✅ Status Helpers
    // ============================================================
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }
}