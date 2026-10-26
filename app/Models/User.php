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
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_TEACHER], true);
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