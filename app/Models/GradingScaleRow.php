<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradingScaleRow extends Model
{
    protected $fillable = ['grading_scale_id', 'min_percent', 'grade', 'grade_point', 'is_fail'];

    protected $casts = [
        'min_percent' => 'decimal:2',
        'grade_point' => 'decimal:2',
        'is_fail'     => 'boolean',
    ];
}
