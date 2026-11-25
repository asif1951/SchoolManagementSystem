<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The 8-digit ID every person in the system carries — student, teacher, staff, anyone.
 *
 *     26 1 00001
 *     │  │ └──── serial, counts up per year and type (00001 and on; past 99999 it simply gets a sixth digit, so a school
 *     │  │       admitting hundreds of thousands in a year never runs out)
 *     │  └────── type: 1 student · 2 teacher · 3 staff · 4 other accounts
 *     └───────── last two digits of the year the person joined
 *
 * An ID never changes: a student keeps it in every class and every year, and a person who
 * moves from one role to another keeps theirs. Numbers come from a counter table that is
 * locked while it is read, so two people created at the same moment can never get the same ID.
 */
final class PersonId
{
    public const STUDENT = 1;
    public const TEACHER = 2;
    public const STAFF   = 3;
    public const OTHER   = 4;

    public static function isValid(?string $id): bool
    {
        return $id !== null && preg_match('/^\d{8,10}$/', $id) === 1;
    }

    /** teacher accounts are teachers, the public "user" role is "other", every other role is staff */
    public static function typeForRole(?string $role): int
    {
        return match ($role) {
            'teacher' => self::TEACHER,
            'user', null, '' => self::OTHER,
            default => self::STAFF,
        };
    }

    /** Reserve the next ID for a type. */
    public static function next(int $type, ?int $year = null): string
    {
        $year ??= (int) now()->year;

        return DB::transaction(function () use ($type, $year) {
            DB::table('id_counters')->insertOrIgnore(['year' => $year, 'type' => $type, 'last' => 0]);

            $row = DB::table('id_counters')->where('year', $year)->where('type', $type)->lockForUpdate()->first();
            $n   = (int) $row->last;

            do {
                $n++;

                $id = self::format($year, $type, $n);
            } while (self::taken($id));       // skips a number someone typed in by hand

            DB::table('id_counters')->where('year', $year)->where('type', $type)->update(['last' => $n]);

            return $id;
        });
    }

    /**
     * Reserve $count IDs at once: one lock on the counter and three lookups, however many are needed.
     * (next() is the same thing one at a time.) Numbers already taken by hand are skipped.
     *
     * @return array<int,string>
     */
    public static function reserve(int $type, int $count, ?int $year = null): array
    {
        $year ??= (int) now()->year;

        if ($count < 1) {
            return [];
        }

        return DB::transaction(function () use ($type, $year, $count) {
            DB::table('id_counters')->insertOrIgnore(['year' => $year, 'type' => $type, 'last' => 0]);

            $n = (int) DB::table('id_counters')->where('year', $year)->where('type', $type)->lockForUpdate()->value('last');
            $out = [];

            while (count($out) < $count) {
                $candidates = [];

                for ($i = 1, $need = $count - count($out); $i <= $need; $i++) {
                    $candidates[$n + $i] = self::format($year, $type, $n + $i);
                }

                $taken = self::takenAmong(array_values($candidates));

                foreach ($candidates as $number => $id) {
                    $n = $number;

                    if (! isset($taken[$id])) {
                        $out[] = $id;
                    }
                }
            }

            DB::table('id_counters')->where('year', $year)->where('type', $type)->update(['last' => $n]);

            return $out;
        });
    }

    /** @return array<string,true> which of these IDs are already used by someone */
    private static function takenAmong(array $ids): array
    {
        $taken = [];

        foreach ([['users', 'uid'], ['teachers', 'uid'], ['students', 'student_id']] as [$table, $column]) {
            foreach (array_chunk($ids, 5000) as $part) {
                foreach (DB::table($table)->whereIn($column, $part)->pluck($column) as $id) {
                    $taken[(string) $id] = true;
                }
            }
        }

        return $taken;
    }

    /** The ID that next() would give now, without using it up (for showing on a form). */
    public static function peek(int $type, ?int $year = null): string
    {
        $year ??= (int) now()->year;

        $n = (int) DB::table('id_counters')->where('year', $year)->where('type', $type)->value('last');

        do {
            $n++;
            $id = self::format($year, $type, $n);
        } while (self::taken($id));

        return $id;
    }

    /** Record that an ID was used (e.g. typed in), so the counter never hands it out again. */
    public static function claim(string $id): void
    {
        if (! self::isValid($id)) {
            return;
        }

        $year   = 2000 + (int) substr($id, 0, 2);
        $type   = (int) substr($id, 2, 1);
        $serial = (int) substr($id, 3);

        DB::table('id_counters')->insertOrIgnore(['year' => $year, 'type' => $type, 'last' => 0]);
        DB::table('id_counters')->where('year', $year)->where('type', $type)->where('last', '<', $serial)->update(['last' => $serial]);
    }

    public static function format(int $year, int $type, int $serial): string
    {
        return sprintf('%02d%d%05d', $year % 100, $type, $serial);
    }

    private static function taken(string $id): bool
    {
        return DB::table('users')->where('uid', $id)->exists()
            || DB::table('teachers')->where('uid', $id)->exists()
            || DB::table('students')->where('student_id', $id)->exists();
    }
}
