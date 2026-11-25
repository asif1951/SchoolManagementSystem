<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of a curriculum template: "Class 9 → (group Science) → Physics, compulsory".
 * study_group_id NULL = applies to every group of that class level.
 */
class CurriculumSubject extends Model
{
    protected $fillable = ['class_name', 'study_group_id', 'subject_id', 'is_optional', 'sort_order'];

    protected $casts = [
        'is_optional' => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function studyGroup()
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }
}
