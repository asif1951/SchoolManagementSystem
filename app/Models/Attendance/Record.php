<?php

namespace App\Models\Attendance;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Record extends Model
{
    protected $table = 'attendance_records';

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date'];

    public function session()
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
