<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One exam of an academic year (1st terminal, half-yearly, annual …). */
class Exam extends Model
{
    public const STATUSES = ['draft', 'open', 'closed', 'published'];

    protected $fillable = ['academic_year', 'name', 'weight', 'sort_order', 'status', 'starts_on', 'ends_on'];

    protected $casts = [
        'weight'     => 'decimal:2',
        'sort_order' => 'integer',
        'starts_on'  => 'date',
        'ends_on'    => 'date',
    ];

    public function papers()
    {
        return $this->hasMany(ExamPaper::class);
    }

    public function scopeForYear($q, string $year)
    {
        return $q->where('academic_year', $year);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }

    /** Total weight of a year's exams (should be 100 before results are published). */
    public static function weightTotal(string $year): float
    {
        return (float) static::query()->forYear($year)->sum('weight');
    }
}
