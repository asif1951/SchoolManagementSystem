<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A proposed change to a protected field, waiting for someone who may approve it. */
class StudentChangeRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public $timestamps = false;

    protected $fillable = [
        'profile_id', 'field', 'old_value', 'new_value', 'reason', 'status',
        'requested_by', 'requested_at', 'decided_by', 'decided_at', 'decision_remark',
    ];

    protected $casts = ['requested_at' => 'datetime', 'decided_at' => 'datetime'];

    public function profile()
    {
        return $this->belongsTo(StudentProfile::class, 'profile_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending($q)
    {
        return $q->where('status', self::PENDING);
    }
}
