<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** For an exam + class + subject: one part (e.g. CQ 70 / pass 23). A subject can have several parts. */
class ExamPaper extends Model
{
    protected $fillable = ['exam_id', 'class_id', 'subject_id', 'component_type_id', 'full_marks', 'pass_marks', 'sort_order'];

    protected $casts = [
        'full_marks' => 'decimal:2',
        'pass_marks' => 'decimal:2',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function componentType()
    {
        return $this->belongsTo(ExamComponentType::class, 'component_type_id');
    }
}
