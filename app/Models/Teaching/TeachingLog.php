<?php

namespace App\Models\Teaching;

use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** What was taught in one class period. */
class TeachingLog extends Model
{
    protected $fillable = ['log_date', 'class_id', 'subject_id', 'teacher_id', 'substitute_id', 'period_id', 'kind', 'plan_id', 'item_id', 'topic_id',
        'held', 'not_held_reason', 'coverage', 'understanding', 'homework', 'next_plan', 'note', 'created_by'];

    protected $casts = ['log_date' => 'date:Y-m-d', 'class_id' => 'integer', 'subject_id' => 'integer', 'teacher_id' => 'integer', 'substitute_id' => 'integer',
        'period_id' => 'integer', 'plan_id' => 'integer', 'item_id' => 'integer', 'topic_id' => 'integer', 'held' => 'boolean', 'homework' => 'boolean', 'coverage' => 'integer'];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function substitute()
    {
        return $this->belongsTo(User::class, 'substitute_id');
    }

    public function item()
    {
        return $this->belongsTo(TeachingPlanItem::class, 'item_id');
    }

    public function topic()
    {
        return $this->belongsTo(SyllabusTopic::class, 'topic_id')->withTrashed();
    }

    public function auditLabel(): string
    {
        return 'Teaching log ' . $this->log_date?->toDateString() . " #{$this->id}";
    }
}
