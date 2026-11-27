<?php

namespace App\Models\Attendance;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    protected $table = 'student_leaves';

    protected $guarded = ['id'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'decided_at' => 'datetime'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function appliedBy()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function auditLabel(): string
    {
        return "Leave {$this->starts_on?->toDateString()} – {$this->ends_on?->toDateString()}";
    }
}
