<?php

namespace App\Models\Teaching;

use Illuminate\Database\Eloquent\Model;

class TeachingPlanItem extends Model
{
    protected $fillable = ['plan_id', 'chapter_id', 'position', 'starts_on', 'ends_on', 'planned_periods', 'progress', 'completed_on'];

    protected $casts = ['plan_id' => 'integer', 'chapter_id' => 'integer', 'position' => 'integer', 'planned_periods' => 'integer', 'progress' => 'float',
        'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'completed_on' => 'date:Y-m-d'];

    public function plan()
    {
        return $this->belongsTo(TeachingPlan::class, 'plan_id');
    }

    public function chapter()
    {
        return $this->belongsTo(SyllabusChapter::class, 'chapter_id')->withTrashed();
    }

    /** upcoming | running | completed | delayed (derived, never stored: it changes with the date) */
    public function statusOn(string $today): string
    {
        if ($this->progress >= 100) {
            return 'completed';
        }

        if ($this->ends_on && $this->ends_on->toDateString() < $today) {
            return 'delayed';
        }

        return ($this->starts_on && $this->starts_on->toDateString() <= $today) || $this->progress > 0 ? 'running' : 'upcoming';
    }
}
