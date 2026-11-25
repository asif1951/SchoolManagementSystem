<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One period (or a break) of the bell schedule: "3rd, 11:20 – 12:00". shift_id NULL = the set every shift without its own uses. */
class RoutinePeriod extends Model
{
    protected $fillable = ['shift_id', 'class_id', 'name', 'starts_at', 'ends_at', 'is_break', 'sort_order'];

    protected $casts = ['is_break' => 'boolean', 'sort_order' => 'integer'];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /** "10:00" — the stored time without seconds */
    public function getStartAttribute(): string
    {
        return substr((string) $this->starts_at, 0, 5);
    }

    public function getEndAttribute(): string
    {
        return substr((string) $this->ends_at, 0, 5);
    }

    /** "10:00 AM" for people, from "13:10" */
    public static function clock(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)) + [0, 0]);

        return sprintf('%d:%02d %s', $h % 12 === 0 ? 12 : $h % 12, $m, $h < 12 ? 'AM' : 'PM');
    }
}
