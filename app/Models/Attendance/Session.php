<?php

namespace App\Models\Attendance;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    protected $table = 'attendance_sessions';

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'submitted_at' => 'datetime', 'substitute' => 'boolean', 'late_submission' => 'boolean'];

    public function records()
    {
        return $this->hasMany(Record::class, 'session_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function takenBy()
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function auditLabel(): string
    {
        return 'Attendance ' . $this->date?->toDateString() . ($this->period_name ? " · {$this->period_name}" : '');
    }
}
