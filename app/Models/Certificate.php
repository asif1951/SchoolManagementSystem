<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A certificate in the register. The serial is fixed when it is issued: there is no way to change it, and it is
 * never given to another certificate. The printed page reads the student's approved data only.
 */
class Certificate extends Model
{
    use SoftDeletes;

    public const TYPES = ['transfer' => 'Transfer Certificate', 'testimonial' => 'Testimonial', 'character' => 'Character Certificate', 'other' => 'Other'];
    public const PREFIX = ['transfer' => 'TC', 'testimonial' => 'TM', 'character' => 'CC', 'other' => 'OT'];

    protected $fillable = ['serial', 'type', 'profile_id', 'enrollment_id', 'issue_date', 'issued_by', 'remarks', 'print_count', 'last_printed_at', 'voided_at', 'void_reason'];

    protected $casts = ['issue_date' => 'date', 'last_printed_at' => 'datetime', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        // the serial is part of the certificate's identity
        static::updating(function (Certificate $c) {
            if ($c->isDirty('serial')) {
                throw new \LogicException('A certificate serial number cannot be changed.');
            }
        });
    }

    public function profile()
    {
        return $this->belongsTo(StudentProfile::class, 'profile_id')->withTrashed();
    }

    public function enrollment()
    {
        return $this->belongsTo(Student::class, 'enrollment_id')->withTrashed();
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
