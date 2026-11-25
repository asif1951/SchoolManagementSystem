<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A transfer-out and its trail: who asked, when the dues were clear, who approved, when the certificate was issued. */
class StudentTransfer extends Model
{
    public const OPEN = ['requested', 'cleared'];

    protected $fillable = [
        'profile_id', 'enrollment_id', 'status', 'reason', 'destination', 'requested_by', 'requested_at', 'dues_total', 'dues_detail', 'cleared_at',
        'override_reason', 'override_by', 'approved_by', 'approved_at', 'certificate_id', 'issued_at', 'decision_remark', 'decided_by', 'decided_at',
        'reinstated_at', 'reinstated_by', 'reinstate_reason',
    ];

    protected $casts = [
        'dues_total' => 'decimal:2', 'dues_detail' => 'array', 'requested_at' => 'datetime', 'cleared_at' => 'datetime', 'approved_at' => 'datetime',
        'issued_at' => 'datetime', 'decided_at' => 'datetime', 'reinstated_at' => 'datetime',
    ];

    public function profile()
    {
        return $this->belongsTo(StudentProfile::class, 'profile_id')->withTrashed();
    }

    public function enrollment()
    {
        return $this->belongsTo(Student::class, 'enrollment_id')->withTrashed();
    }

    public function certificate()
    {
        return $this->belongsTo(Certificate::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', self::OPEN);
    }
}
