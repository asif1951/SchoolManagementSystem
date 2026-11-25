<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How often a subject appears in a class's week, and the most in one day: the input of the automatic routine. */
class RoutinePlan extends Model
{
    protected $fillable = ['class_id', 'subject', 'periods_per_week', 'max_per_day'];

    protected $casts = ['periods_per_week' => 'integer', 'max_per_day' => 'integer'];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
