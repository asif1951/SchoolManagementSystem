<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\DB;

/** The module's settings: one stored row over the defaults below. Everything here is changed on the Settings page. */
class Settings
{
    /** the four statuses the calculations are built on */
    public const STATUSES = ['present', 'absent', 'late', 'leave'];

    public static function defaults(): array
    {
        return [
            'edit_days'        => 3,             // days after submission in which "correct attendance" is enough
            'entry_days'       => 7,             // how many days back attendance may be taken for the first time
            'default_status'   => 'present',     // what a new daily sheet starts with: present | none
            'allow_late'       => true,
            'allow_leave'      => true,
            'deadline'         => null,          // HH:MM: a daily submission after this is marked late
            'period_same_day'  => false,         // period attendance may only be taken on its own day
            'allow_substitute' => true,          // a teacher of the class may take another teacher's period
            'low_threshold'    => 75,
            'consecutive_days' => 3,
            'default_period'   => 'month',       // day | week | month | half | year
            'date_mode'        => 'english',     // english | bangla | both (printed sheets and reports)
            'leave_types'      => ['Sick', 'Personal', 'Family Emergency', 'Medical', 'Other'],
            'report_periods'   => [],            // [{name, from: MM-DD, to: MM-DD}], used when the calendar has no terms
        ];
    }

    public static function all(): array
    {
        $stored = json_decode((string) DB::table('attendance_settings')->value('values'), true) ?: [];

        return array_merge(self::defaults(), array_intersect_key($stored, self::defaults()));
    }

    public static function get(string $key): mixed
    {
        // read once per request
        $memo = app()->bound('attendance.settings') ? app('attendance.settings') : tap(self::all(), fn ($all) => app()->instance('attendance.settings', $all));

        return $memo[$key] ?? null;
    }

    public static function save(array $changes): void
    {
        $values = array_merge(self::all(), array_intersect_key($changes, self::defaults()));
        $row = ['values' => json_encode($values, JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
        $id = DB::table('attendance_settings')->value('id');
        $id ? DB::table('attendance_settings')->where('id', $id)->update($row) : DB::table('attendance_settings')->insert($row + ['created_at' => now()]);
        app()->forgetInstance('attendance.settings');
    }

    /** the statuses that may be chosen when taking attendance */
    public static function allowed(): array
    {
        return array_values(array_filter(self::STATUSES, fn ($s) => ($s !== 'late' || self::get('allow_late')) && ($s !== 'leave' || self::get('allow_leave'))));
    }
}
