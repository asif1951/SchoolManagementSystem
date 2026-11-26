<?php

namespace App\Models\Teaching;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $table = 'teaching_holidays';

    protected $fillable = ['starts_on', 'ends_on', 'title'];

    protected $casts = ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
}
