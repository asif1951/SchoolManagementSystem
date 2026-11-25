<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A part of a paper: Written, CQ, MCQ, Practical, Viva … (editable list). */
class ExamComponentType extends Model
{
    protected $fillable = ['name', 'code', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }
}
