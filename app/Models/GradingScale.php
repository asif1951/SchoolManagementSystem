<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An editable grading scale. A percentage earns the row with the highest `min_percent` it reaches.
 * Default scale = the Bangladesh 5-point scale (A+ 5.00 … F 0.00).
 */
class GradingScale extends Model
{
    protected $fillable = ['name', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    public function rows()
    {
        return $this->hasMany(GradingScaleRow::class)->orderByDesc('min_percent');
    }

    public static function default(): self
    {
        return static::query()->where('is_default', true)->first() ?? static::query()->firstOrFail();
    }

    /**
     * @return array{grade:string, point:float, is_fail:bool}
     */
    public function gradeFor(float $percent): array
    {
        $rows = $this->relationLoaded('rows') ? $this->rows : $this->rows()->get();

        $row = $rows->first(fn ($r) => $percent + 0.00001 >= (float) $r->min_percent) ?? $rows->last();

        return [
            'grade'   => $row->grade,
            'point'   => (float) $row->grade_point,
            'is_fail' => (bool) $row->is_fail,
        ];
    }
}
