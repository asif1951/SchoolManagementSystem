<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected static function booted(): void
    {
        // A staff profile shares the ID of the login account with the same email, if there is one.
        static::creating(function (Teacher $teacher) {
            if (blank($teacher->uid)) {
                $uid = $teacher->email ? \App\Models\User::where('email', $teacher->email)->value('uid') : null;

                if ($uid && static::where('uid', $uid)->exists()) {
                    $uid = null;
                }

                $teacher->uid = $uid ?: \App\Support\PersonId::next(\App\Support\PersonId::TEACHER);
            }
        });
    }

    protected $fillable = [
        'name',
        'photo',
        'designation',
        'department',
        'qualification',
        'experience',
        'bio',
        'phone',
        'email',
        'display_on_website',
        'status',
        'order',
    ];

    protected $casts = [
        'display_on_website' => 'boolean',
        'order'              => 'integer',
    ];

    /**
     * Public website er jonno — sudhu active, visible, ordered.
     */
    public function scopeForWebsite($query)
    {
        return $query->where('status', 'active')
            ->where('display_on_website', true)
            ->orderBy('order', 'asc')
            ->orderBy('name', 'asc');
    }
}