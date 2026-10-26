<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResultInput extends Model
{
    // ✅ ডেটাবেস টেবিলের নাম অপরিবর্তিত রাখা হলো
    protected $table = 'results';

    protected $fillable = [
        'student_id', 'class_id', 'subject', 'exam_type',
        'academic_year', 'marks', 'total_marks','grade', 'gpa', 'entered_by',
        'is_locked', 'locked_at', 'locked_by',
    ];

    protected $casts = [
        'marks' => 'decimal:2',
        'gpa' => 'decimal:2',
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}