<?php

namespace App\Models\Teaching;

use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LessonPlan extends Model
{
    public const TEXTS = ['objectives', 'outcomes', 'method', 'materials', 'assessment', 'homework'];

    protected $fillable = ['owner_id', 'class_id', 'subject_id', 'chapter_id', 'topic_id', 'log_id', 'title', 'lesson_date', 'duration',
        'objectives', 'outcomes', 'method', 'materials', 'assessment', 'homework', 'is_template'];

    protected $casts = ['owner_id' => 'integer', 'class_id' => 'integer', 'subject_id' => 'integer', 'chapter_id' => 'integer', 'topic_id' => 'integer',
        'lesson_date' => 'date:Y-m-d', 'is_template' => 'boolean', 'duration' => 'integer'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function chapter()
    {
        return $this->belongsTo(SyllabusChapter::class, 'chapter_id')->withTrashed();
    }
}
