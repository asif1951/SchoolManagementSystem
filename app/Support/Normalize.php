<?php

namespace App\Support;

/**
 * Small, pure helpers that make the duplicate checks fast and consistent:
 * the same normalization is used when saving (to fill the indexed columns) and when searching.
 */
final class Normalize
{
    /** words that are titles or abbreviations, not part of the name's identity */
    private const NOISE = ['md', 'mohd', 'mohammad', 'mohammed', 'muhammad', 'mohamad', 'mst', 'mosammat', 'mr', 'mrs', 'miss', 'ms', 'late'];

    /** "Md. Rahim  Uddin" and "rahim uddin" give the same string. */
    public static function name(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', $name);          // punctuation to spaces; Bangla letters stay
        $words = array_filter(preg_split('/\s+/u', (string) $name) ?: [], fn ($w) => $w !== '' && ! in_array($w, self::NOISE, true));

        return implode(' ', $words);
    }

    /** Last 10 digits of a Bangladeshi mobile number: "+880 1712-345678", "01712345678" and "1712345678" match. */
    public static function mobile(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '880') && strlen($digits) > 10) {
            $digits = substr($digits, 3);
        }

        return strlen($digits) >= 10 ? substr($digits, -10) : $digits;
    }

    /**
     * Any date value as plain Y-m-d. (Laravel keeps a "date" attribute in memory as "2014-05-02 00:00:00";
     * comparing or copying that against a plain date would disagree.)
     */
    public static function date($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $value, $m) ? $m[1] : (string) $value;
    }

    /**
     * A value that is safe to put in a spreadsheet cell. A cell that starts with = + - @ (or a tab / return) is run
     * as a formula by Excel; a leading apostrophe turns it back into plain text. Used on every export and error report.
     */
    /** a text a spreadsheet would run as a formula (refused on import) */
    public static function looksLikeFormula(string $text): bool
    {
        return $text !== '' && strpbrk($text[0], '=+@' . chr(9) . chr(13)) !== false;
    }

    public static function csvCell($value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && strpbrk($text[0], '=+-@' . chr(9) . chr(13)) !== false ? "'" . $text : $text;      // = + - @ tab return
    }

    /**
     * A date typed by a person, as Y-m-d, or null when it is not a real date. Only unambiguous formats are
     * accepted (day first, as is usual in Bangladesh); words such as "tomorrow" or "next tuesday" are not dates.
     */
    public static function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'j-n-Y', 'j.n.Y', 'Y/m/d', 'Y-m-d H:i:s'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $value);
            $errors = \DateTime::getLastErrors();

            if ($date && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** Birth registration number: digits only (spaces and dashes typed in are ignored). */
    public static function brn(?string $brn): ?string
    {
        $digits = preg_replace('/[\s\-]+/', '', (string) $brn);

        return $digits === '' ? null : $digits;
    }

    public static function isValidBrn(?string $brn): bool
    {
        return $brn !== null && preg_match('/^\d{17}$/', $brn) === 1;
    }

    /** 20041234567890123 → 2004•••••••••123 : first 4 and last 3 digits only. */
    public static function maskBrn(?string $brn): ?string
    {
        if ($brn === null || $brn === '') {
            return null;
        }

        $len = strlen($brn);

        return $len <= 7 ? str_repeat('•', $len) : substr($brn, 0, 4) . str_repeat('•', $len - 7) . substr($brn, -3);
    }

    /** Similarity of two normalized names, 0–100 (order-insensitive: "Rahim Uddin" ≈ "Uddin Rahim"). */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $sort = function (string $s): string {
            $w = explode(' ', $s);
            sort($w);

            return implode(' ', $w);
        };

        similar_text($sort($a), $sort($b), $percent);

        return round($percent, 1);
    }
}
