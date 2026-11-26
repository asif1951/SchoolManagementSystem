<?php

namespace App\Services\Teaching;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** The module's few settings, with their defaults. */
class Settings
{
    public const DEFAULTS = [
        'approval_required' => false,      // a yearly plan must be approved by the Head Teacher
        'edit_window_days'  => 7,          // how far back a teacher may edit the daily log
        'amber_gap'         => 5,          // behind by more than this many percent points: amber
        'red_gap'           => 15,         // behind by more than this: red
        'timezone'          => 'Asia/Dhaka',
    ];

    private const KEY = 'teaching.settings';

    public static function all(): array
    {
        return Cache::remember(self::KEY, 300, function () {
            $row = DB::table('teaching_settings')->orderBy('id')->value('values');

            return array_merge(self::DEFAULTS, $row ? (json_decode($row, true) ?: []) : []);
        });
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function save(array $values): void
    {
        $values = array_merge(self::all(), array_intersect_key($values, self::DEFAULTS));
        $id = DB::table('teaching_settings')->orderBy('id')->value('id');

        $id ? DB::table('teaching_settings')->where('id', $id)->update(['values' => json_encode($values), 'updated_at' => now()])
            : DB::table('teaching_settings')->insert(['values' => json_encode($values), 'created_at' => now(), 'updated_at' => now()]);

        Cache::forget(self::KEY);
    }
}
