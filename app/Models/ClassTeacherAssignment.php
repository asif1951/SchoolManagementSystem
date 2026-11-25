<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stint of one teacher as class teacher of one class.
 * `ends_on` NULL means this is the current assignment.
 */
class ClassTeacherAssignment extends Model
{
    protected $fillable = [
        'class_id',
        'user_id',
        'teacher_name',
        'starts_on',
        'ends_on',
        'assigned_by',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on'   => 'date',
    ];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('ends_on');
    }
}
