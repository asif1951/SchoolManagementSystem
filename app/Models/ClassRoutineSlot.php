<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassRoutineSlot extends Model
{
    protected $fillable = ['class_routine_id', 'day', 'period_id', 'subject_id', 'label', 'teacher_id'];

    protected $casts = ['day' => 'integer'];

    public function routine()
    {
        return $this->belongsTo(ClassRoutine::class, 'class_routine_id');
    }

    public function period()
    {
        return $this->belongsTo(RoutinePeriod::class, 'period_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
