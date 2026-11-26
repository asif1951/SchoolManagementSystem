<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The official Bangladesh Bangla calendar (Bangabda, as revised in 2019). Dates are stored as Gregorian everywhere;
 * this only converts for entry and display.
 *
 *  1 Boishakh is 14 April. Boishakh–Ashwin have 31 days, Kartik–Magh 30, Falgun 29 (30 when the 29 February falls
 *  inside it), Chaitra 30. The Bangla year is the Gregorian year − 593 from 14 April, − 594 before it.
 *  resources/js/lib/bangla.js mirrors this class.
 */
final class BanglaDate
{
    public const MONTHS = ['বৈশাখ', 'জ্যৈষ্ঠ', 'আষাঢ়', 'শ্রাবণ', 'ভাদ্র', 'আশ্বিন', 'কার্তিক', 'অগ্রহায়ণ', 'পৌষ', 'মাঘ', 'ফাল্গুন', 'চৈত্র'];

    public const MONTHS_LATIN = ['Boishakh', 'Joishtho', 'Asharh', 'Srabon', 'Bhadro', 'Ashwin', 'Kartik', 'Agrahayan', 'Poush', 'Magh', 'Falgun', 'Chaitra'];

    public const DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** @return array<int,int> days of the twelve months of a Bangla year */
    public static function monthLengths(int $year): array
    {
        // Falgun of Bangla year Y falls in February of Gregorian year Y + 594
        $leap = (bool) date('L', mktime(0, 0, 0, 1, 1, $year + 594));

        return [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, $leap ? 30 : 29, 30];
    }

    /** @return array{year:int, month:int, day:int}  month 1–12 */
    public static function fromGregorian(string $date): array
    {
        $d = Carbon::parse($date)->startOfDay();
        $year = $d->year - ($d->format('m-d') >= '04-14' ? 593 : 594);
        $passed = (int) Carbon::create($year + 593, 4, 14)->diffInDays($d);

        foreach (self::monthLengths($year) as $i => $length) {
            if ($passed < $length) {
                return ['year' => $year, 'month' => $i + 1, 'day' => $passed + 1];
            }

            $passed -= $length;
        }

        return ['year' => $year, 'month' => 12, 'day' => 30];
    }

    /** the Gregorian date (Y-m-d), or null when there is no such Bangla date */
    public static function toGregorian(int $year, int $month, int $day): ?string
    {
        if ($year < 1300 || $year > 1600 || $month < 1 || $month > 12 || $day < 1) {
            return null;
        }

        $lengths = self::monthLengths($year);

        if ($day > $lengths[$month - 1]) {
            return null;
        }

        return Carbon::create($year + 593, 4, 14)->addDays(array_sum(array_slice($lengths, 0, $month - 1)) + $day - 1)->toDateString();
    }

    public static function digits(int|string $n): string
    {
        return strtr((string) $n, self::DIGITS);
    }

    /** "১২ চৈত্র ১৪৩২" */
    public static function format(string $date, bool $withYear = true): string
    {
        $b = self::fromGregorian($date);

        return self::digits($b['day']) . ' ' . self::MONTHS[$b['month'] - 1] . ($withYear ? ' ' . self::digits($b['year']) : '');
    }

    /**
     * Read a typed Bangla date: "১২ চৈত্র ১৪৩২", "12 Chaitra 1432" or "1432-12-12 BS". Returns the Gregorian date.
     */
    public static function parse(string $text): ?string
    {
        $text = trim(str_replace(self::DIGITS, range(0, 9), $text));

        if (preg_match('/^(1[3-5]\d\d)-(\d{1,2})-(\d{1,2})\s*(BS|বঙ্গাব্দ)?$/iu', $text, $m)) {
            return self::toGregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})\s+([^\d\s,]+),?\s+(1[3-5]\d\d)$/u', $text, $m)) {
            // the same letter can be typed composed or decomposed (য়, ড়)
            $same = fn (string $s) => mb_strtolower(strtr($s, ['য়' => "\u{09DF}", 'ড়' => "\u{09DC}", 'ঢ়' => "\u{09DD}"]));
            $name = $same($m[2]);

            foreach (self::MONTHS as $i => $bn) {
                if ($same($bn) === $name || mb_strtolower(self::MONTHS_LATIN[$i]) === $name) {
                    return self::toGregorian((int) $m[3], $i + 1, (int) $m[1]);
                }
            }
        }

        return null;
    }
}
