<?php

namespace App\Support;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Streaming .xlsx / .csv reading and writing for files of any size: one row is in memory at a time, so a million
 * students use the same few megabytes as a hundred. (Spreadsheet is the small in-memory version used for the
 * template and tiny files.)
 *
 * Reading stays defensive: the type is recognised by the file's bytes, a spreadsheet that carries a DOCTYPE is
 * refused, a ZIP part that expands more than 200× or past the size cap is refused, and nothing is executed.
 * Writing neutralizes formulas (a cell starting with = + - @) and strips characters XML cannot carry.
 */
final class SheetStream
{
    /** the largest upload accepted (bytes); the file is streamed, so this is a disk limit, not a memory one */
    public const MAX_BYTES = 1024 * 1024 * 1024;

    private const MAX_SHEET_BYTES = 8 * 1024 * 1024 * 1024;
    private const MAX_STRINGS_BYTES = 400 * 1024 * 1024;
    private const MAX_RATIO = 200;
    private const MAX_ENTRIES = 300;

    /** Excel's own limit of rows on one sheet */
    public const SHEET_ROWS = 1048576;

    // ------------------------------------------------------------------
    // reading
    // ------------------------------------------------------------------

    /** 'xlsx' or 'csv', decided by the bytes, never by the file name */
    public static function kind(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('The file is missing.');
        }

        if (filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException('The file is larger than ' . (self::MAX_BYTES / 1048576) . ' MB.');
        }

        $head = (string) @file_get_contents($path, false, null, 0, 4);

        return in_array($head, ['PK' . chr(3) . chr(4), 'PK' . chr(5) . chr(6)], true) ? 'xlsx' : 'csv';
    }

    /**
     * Rows of the first sheet, one at a time: row number (1 = the header) => cell text. Blank rows are skipped.
     *
     * @return Generator<int,array<int,string>>
     *
     * @throws RuntimeException  with a message fit to show the user
     */
    public static function rows(string $path): Generator
    {
        return self::kind($path) === 'xlsx' ? self::xlsxRows($path) : self::csvRows($path);
    }

    private static function csvRows(string $path): Generator
    {
        $fh = fopen($path, 'rb');

        if (! $fh) {
            throw new RuntimeException('The file could not be opened.');
        }

        try {
            $head = (string) fread($fh, 65536);

            if (str_contains(substr($head, 0, 8192), chr(0))) {
                throw new RuntimeException('This is not a spreadsheet or a CSV text file.');
            }

            $bom = str_starts_with($head, "\xEF\xBB\xBF");
            // valid UTF-8 stays as it is; anything else is what older Excel saves (Windows-1252). A multibyte letter cut
            // by the 64 KB boundary is tolerated by trimming up to 3 trailing bytes before the check.
            $utf8 = false;
            for ($cut = 0; $cut < 4 && ! $utf8; $cut++) {
                $utf8 = mb_check_encoding($cut ? substr($head, 0, -$cut) : $head, 'UTF-8');
            }

            $first = strtok(ltrim($head, "\xEF\xBB\xBF"), "\n") ?: '';
            $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), chr(9) => substr_count($first, chr(9))];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);

            rewind($fh);

            if ($bom) {
                fread($fh, 3);
            }

            $n = 0;

            while (($r = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
                $n++;

                if ($r === [null]) {
                    continue;
                }

                yield $n => array_map(fn ($c) => trim($utf8 ? (string) $c : mb_convert_encoding((string) $c, 'UTF-8', 'Windows-1252')), $r);
            }
        } finally {
            fclose($fh);
        }
    }

    private static function xlsxRows(string $path): Generator
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This Excel file could not be opened.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('This Excel file has an unusual structure and was refused.');
            }

            $sheetPath = self::firstSheetPath($zip);
            self::guard($zip, $sheetPath, self::MAX_SHEET_BYTES);

            $hasStrings = $zip->locateName('xl/sharedStrings.xml') !== false;

            if ($hasStrings) {
                self::guard($zip, 'xl/sharedStrings.xml', self::MAX_STRINGS_BYTES);
            }
        } finally {
            $zip->close();
        }

        $strings = $hasStrings ? self::sharedStrings('zip://' . $path . '#xl/sharedStrings.xml') : [];

        $reader = new XMLReader();

        if (! $reader->open('zip://' . $path . '#' . $sheetPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('This spreadsheet could not be read.');
        }

        try {
            $n = 0;

            // go to the first <row>; refuse a DOCTYPE on the way
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new RuntimeException('This spreadsheet contains unsafe content and was refused.');
                }

                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                    break;
                }
            }

            while ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                $n++;
                $number = (int) ($reader->getAttribute('r') ?: $n);
                $cells = [];

                $node = $reader->expand();

                foreach ($node->childNodes as $c) {
                    if ($c->nodeName !== 'c') {
                        continue;
                    }

                    $col = self::columnIndex((string) $c->getAttribute('r'), count($cells));
                    $type = (string) $c->getAttribute('t');
                    $v = '';

                    foreach ($c->childNodes as $child) {
                        if ($child->nodeName === 'v') {
                            $v = $child->textContent;
                        } elseif ($child->nodeName === 'is') {
                            $v = self::inlineText($child);
                        }
                    }

                    $cells[$col] = trim(match ($type) {
                        's'     => $strings[(int) $v] ?? '',
                        'b'     => $v === '1' ? 'TRUE' : 'FALSE',
                        default => $v,
                    });
                }

                $reader->next('row');

                if (! $cells) {
                    continue;
                }

                $line = [];
                for ($i = 0, $w = max(array_keys($cells)) + 1; $i < $w; $i++) {
                    $line[] = $cells[$i] ?? '';
                }

                if (implode('', $line) === '') {
                    continue;
                }

                yield $number => $line;
            }
        } finally {
            $reader->close();
        }
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $read = function (string $name) use ($zip): string {
            if ($zip->statName($name) === false) {
                throw new RuntimeException('This does not look like a valid Excel (.xlsx) file.');
            }

            self::guard($zip, $name, 5 * 1024 * 1024);
            $xml = (string) $zip->getFromName($name);

            if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                throw new RuntimeException('This spreadsheet contains unsafe content and was refused.');
            }

            return $xml;
        };

        $rid = null;

        if (preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $read('xl/workbook.xml'), $m)) {
            $rid = $m[1];
        }

        if ($rid !== null && preg_match_all('/<Relationship\b[^>]*>/', $read('xl/_rels/workbook.xml.rels'), $rels)) {
            foreach ($rels[0] as $tag) {
                if (str_contains($tag, 'Id="' . $rid . '"') && preg_match('/Target="([^"]+)"/', $tag, $t)) {
                    $target = ltrim($t[1], '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** refuse a part that is missing, too big, or expands suspiciously (a ZIP bomb) */
    private static function guard(ZipArchive $zip, string $name, int $maxBytes): void
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            throw new RuntimeException('This does not look like a valid Excel (.xlsx) file.');
        }

        if ($stat['size'] > $maxBytes || ($stat['comp_size'] > 0 && $stat['size'] / $stat['comp_size'] > self::MAX_RATIO && $stat['size'] > 1048576)) {
            throw new RuntimeException('The spreadsheet is too large or unusual to read safely.');
        }
    }

    /** @return array<int,string> */
    private static function sharedStrings(string $uri): array
    {
        $reader = new XMLReader();

        if (! $reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }

        $out = [];

        try {
            // go to the first <si>, refusing a DOCTYPE on the way, then take them one after another
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new RuntimeException('This spreadsheet contains unsafe content and was refused.');
                }

                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                    break;
                }
            }

            while ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                $out[] = self::inlineText($reader->expand());
                $reader->next('si');
            }
        } finally {
            $reader->close();
        }

        return $out;
    }

    /** the text of a shared-string / inline-string node: its own <t> and the <t> of rich-text runs, not phonetic hints */
    private static function inlineText(\DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeName === 't') {
                $text .= $child->textContent;
            } elseif ($child->nodeName === 'r') {
                foreach ($child->childNodes as $t) {
                    if ($t->nodeName === 't') {
                        $text .= $t->textContent;
                    }
                }
            }
        }

        return $text;
    }

    /** "C12" → 2 (zero-based column); without a reference the next free column */
    private static function columnIndex(string $ref, int $fallback): int
    {
        if (! preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return $fallback;
        }

        $n = 0;

        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /** a cheap guess of the data rows in a file, for a progress bar (null when it cannot be told without reading it all) */
    public static function estimateRows(string $path): ?int
    {
        try {
            if (self::kind($path) === 'csv') {
                $fh = fopen($path, 'rb');
                $n = 0;

                while (! feof($fh)) {
                    $n += substr_count((string) fread($fh, 1048576), "\n");
                }

                fclose($fh);

                return max(0, $n - 1);
            }

            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return null;
            }

            $sheet = self::firstSheetPath($zip);
            $zip->close();

            // <dimension ref="A1:V1000001"/> sits at the top of the sheet
            $fh = fopen('zip://' . $path . '#' . $sheet, 'rb');
            $head = $fh ? (string) fread($fh, 4096) : '';
            $fh && fclose($fh);

            return preg_match('/<dimension ref="[A-Z]+\d+:[A-Z]+(\d+)"/', $head, $m) ? max(0, (int) $m[1] - 1) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // writing
    // ------------------------------------------------------------------

    /**
     * Write rows to an .xlsx file on disk, streaming. A sheet holds Excel's maximum of 1,048,576 rows; more rows
     * continue on "<name> 2", "<name> 3" … with the heading row repeated. Returns the number of data rows written.
     *
     * @param  iterable<int,array<int,mixed>>  $rows  the first row is the heading
     */
    public static function writeXlsx(iterable $rows, string $path, string $sheetName = 'Sheet1'): int
    {
        $sheets = [];            // [tempfile path, name]
        $handle = null;
        $header = null;
        $inSheet = 0;
        $written = 0;

        $open = function () use (&$sheets, &$handle, &$inSheet, $sheetName) {
            $tmp = tempnam(sys_get_temp_dir(), 'sh');
            $handle = fopen($tmp, 'wb');
            $name = count($sheets) === 0 ? $sheetName : $sheetName . ' ' . (count($sheets) + 1);
            $sheets[] = [$tmp, mb_substr($name, 0, 31)];
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
            $inSheet = 0;
        };
        $close = function () use (&$handle) {
            if ($handle) {
                fwrite($handle, '</sheetData></worksheet>');
                fclose($handle);
                $handle = null;
            }
        };
        $put = function (array $row) use (&$handle, &$inSheet) {
            $inSheet++;
            $xml = '<row r="' . $inSheet . '">';

            foreach (array_values($row) as $c => $value) {
                $text = htmlspecialchars(self::stripControl(Normalize::csvCell($value)), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="' . self::columnName($c) . $inSheet . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }

            fwrite($handle, $xml . '</row>');
        };

        try {
            $open();

            foreach ($rows as $row) {
                if ($header === null) {
                    $header = $row;
                } else {
                    $written++;
                }

                if ($inSheet >= self::SHEET_ROWS) {
                    $close();
                    $open();

                    if ($row !== $header) {
                        $put($header);          // the heading again at the top of the next sheet
                    }
                }

                $put($row);
            }

            $close();

            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The export file could not be created.');
            }

            $types = '';
            $rels = '';
            $list = '';

            foreach ($sheets as $i => [$tmp, $name]) {
                $k = $i + 1;
                $types .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
                $list .= '<sheet name="' . htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
                $zip->addFile($tmp, 'xl/worksheets/sheet' . $k . '.xml');
            }

            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $types . '</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $list . '</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
            $zip->close();           // the sheet files are read and compressed here, one after another

            return $written;
        } finally {
            if ($handle) {
                fclose($handle);
            }

            foreach ($sheets as [$tmp]) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Write rows to a CSV file on disk, streaming (UTF-8 with a byte-order mark so Excel shows Bangla correctly).
     *
     * @param  iterable<int,array<int,mixed>>  $rows  the first row is the heading
     * @return int the number of data rows written
     */
    public static function writeCsv(iterable $rows, string $path): int
    {
        $fh = fopen($path, 'wb');

        if (! $fh) {
            throw new RuntimeException('The export file could not be created.');
        }

        fwrite($fh, "\xEF\xBB\xBF");
        $n = -1;

        try {
            foreach ($rows as $row) {
                fputcsv($fh, array_map(fn ($v) => Normalize::csvCell($v), array_values($row)), ',', '"', '');
                $n++;
            }
        } finally {
            fclose($fh);
        }

        return max(0, $n);
    }

    private static function columnName(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }

        return $name;
    }

    private static function stripControl(string $text): string
    {
        return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text);
    }
}
