<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Numbers that only go up. A receipt, certificate or application number is taken from a counter that is locked while
 * it is read, so two people at once never get the same one, and a number is never handed out twice — not even when
 * the record that used it is voided or deleted.
 */
final class Serial
{
    /** the next number for $key (1, 2, 3 …) */
    public static function next(string $key): int
    {
        return DB::transaction(function () use ($key) {
            DB::table('serial_counters')->insertOrIgnore(['key' => $key, 'last' => 0]);

            $last = (int) DB::table('serial_counters')->where('key', $key)->lockForUpdate()->value('last');
            $next = $last + 1;

            DB::table('serial_counters')->where('key', $key)->update(['last' => $next]);

            return $next;
        });
    }

    /** "AD-26-000041" */
    public static function format(string $prefix, ?int $year, int $n, int $width): string
    {
        return $prefix . ($year ? '-' . $year : '') . '-' . str_pad((string) $n, $width, '0', STR_PAD_LEFT);
    }
}
