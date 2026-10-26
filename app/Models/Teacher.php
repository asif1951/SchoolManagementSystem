<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
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