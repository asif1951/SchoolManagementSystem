<?php

namespace App\Models\Teaching;

use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** The Yearly Teaching Plan of one teacher for one subject of one class section. */
class TeachingPlan extends Model
{
    public const STATUSES = ['draft', 'submitted', 'approved', 'returned'];

    protected $fillable = ['academic_year', 'class_id', 'subject_id', 'teacher_id', 'status', 'submitted_at', 'approved_by', 'approved_at',
        'chapters', 'delayed', 'planned_periods', 'done_pct', 'expected_pct', 'gap', 'health', 'projected_finish', 'last_log_on', 'summary_on'];

    protected $casts = ['class_id' => 'integer', 'subject_id' => 'integer', 'teacher_id' => 'integer', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
        'done_pct' => 'float', 'expected_pct' => 'float', 'gap' => 'float', 'chapters' => 'integer', 'delayed' => 'integer', 'planned_periods' => 'integer',
        'projected_finish' => 'date:Y-m-d', 'last_log_on' => 'date:Y-m-d', 'summary_on' => 'date:Y-m-d'];

    public function items()
    {
        return $this->hasMany(TeachingPlanItem::class, 'plan_id')->orderBy('position')->orderBy('id');
    }

    public function logs()
    {
        return $this->hasMany(TeachingLog::class, 'plan_id');
    }

    public function reviews()
    {
        return $this->hasMany(TeachingReview::class, 'plan_id')->latest('id');
    }

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

    public function auditLabel(): string
    {
        return "Teaching plan #{$this->id} ({$this->academic_year})";
    }
}
