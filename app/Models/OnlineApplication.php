<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An admission application submitted through the public form. */
class OnlineApplication extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'application_no', 'academic_year', 'class_name', 'name_en', 'name_bn', 'brn', 'dob', 'gender', 'religion', 'father_name', 'mother_name',
        'guardian_name', 'guardian_mobile', 'guardian_relation', 'present_address', 'previous_school', 'status', 'decision_remark', 'decided_by',
        'decided_at', 'profile_id', 'enrollment_id', 'ip', 'submitted_at',
    ];

    protected $casts = ['dob' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    public function profile()
    {
        return $this->belongsTo(StudentProfile::class, 'profile_id')->withTrashed();
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
