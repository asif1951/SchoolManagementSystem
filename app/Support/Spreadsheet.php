<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reading and writing .xlsx and .csv without a third-party library.
 *
 * Reading is defensive: the file is recognised by its bytes, sizes are capped, a spreadsheet that carries a
 * DOCTYPE (the doorway to "billion laughs" and external-entity tricks) is refused, and nothing is ever executed.
 * Writing neutralizes spreadsheet formulas (a cell starting with = + - @) so an exported name cannot run as code.
 */
final class Spreadsheet
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;
    private const MAX_PART_BYTES = 25 * 1024 * 1024;
    private const MAX_ENTRIES = 300;

    /**
     * Rows of the first sheet as arrays of cell text, in order. The first row is the header.
     *
     * @return array<int,array<int,string>>
     *
     * @throws RuntimeException  with a message fit to show the user
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException('The file is missing or larger than ' . (self::MAX_BYTES / 1048576) . ' MB.');
        }

        $head = (string) @file_get_contents($path, false, null, 0, 4);

        // an .xlsx is a ZIP; anything else is read as delimited text
        $rows = in_array($head, ['PK' . chr(3) . chr(4), 'PK' . chr(5) . chr(6)], true) ? self::readXlsx($path) : self::readCsv($path);

        if (count($rows) > self::MAX_ROWS + 1) {
            throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.');
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // CSV
    // ------------------------------------------------------------------

    private static function readCsv(string $path): array
    {
        $text = (string) file_get_contents($path);

        if (str_contains(substr($text, 0, 8192), chr(0))) {
            throw new RuntimeException('This is not a spreadsheet or a CSV text file.');
        }

        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);                              // UTF-8 byte-order mark
        // valid UTF-8 stays as it is; anything else is what older Excel saves (Windows-1252)
        if (! mb_check_encoding((string) $text, 'UTF-8')) {
            $text = mb_convert_encoding((string) $text, 'UTF-8', 'Windows-1252');
        }

        // the delimiter the first line uses: comma, semicolon or tab
        $first = strtok($text, "\n") ?: '';
        $delimiter = collect([',' => substr_count($first, ','), ';' => substr_count($first, ';'), chr(9) => substr_count($first, chr(9))])->sortDesc()->keys()->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $rows = [];
        while (($r = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($r === [null]) {
                continue;                                                                // a blank line
            }

            $rows[] = array_map(fn ($c) => trim((string) $c), $r);

            if (count($rows) > self::MAX_ROWS + 2) {
                break;
            }
        }

        fclose($stream);

        return $rows;
    }

    // ------------------------------------------------------------------
    // XLSX
    // ------------------------------------------------------------------

    private static function readXlsx(string $path): array
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
            $strings = self::sharedStrings($zip);
            $xml = self::part($zip, $sheetPath);
            $sheet = self::xml($xml);
        } finally {
            $zip->close();
        }

        $rows = [];

        foreach ($sheet->sheetData->row ?? [] as $row) {
            $cells = [];

            foreach ($row->c as $c) {
                $col = self::columnIndex((string) $c['r']);
                $type = (string) $c['t'];

                $value = match ($type) {
                    's'         => $strings[(int) $c->v] ?? '',
                    'inlineStr' => self::text($c->is),
                    'b'         => (string) $c->v === '1' ? 'TRUE' : 'FALSE',
                    default     => (string) $c->v,
                };

                $cells[$col] = trim((string) $value);
            }

            if ($cells) {
                $width = max(array_keys($cells)) + 1;
                $line = [];
                for ($i = 0; $i < $width; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                $rows[] = $line;
            }

            if (count($rows) > self::MAX_ROWS + 2) {
                break;
            }
        }

        return $rows;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = self::xml(self::part($zip, 'xl/workbook.xml'));
        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $first = $workbook->xpath('//m:sheets/m:sheet')[0] ?? null;

        if ($first) {
            $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $rels = self::xml(self::part($zip, 'xl/_rels/workbook.xml.rels'));

            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $out = [];
        foreach (self::xml(self::part($zip, 'xl/sharedStrings.xml'))->si as $si) {
            $out[] = self::text($si);
        }

        return $out;
    }

    /** all the text of a shared-string / inline-string node, rich-text runs joined */
    private static function text(?SimpleXMLElement $node): string
    {
        if (! $node) {
            return '';
        }

        $text = isset($node->t) ? (string) $node->t : '';

        foreach ($node->r ?? [] as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    private static function part(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            throw new RuntimeException('This does not look like a valid Excel (.xlsx) file.');
        }

        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new RuntimeException('The spreadsheet is too large to read safely.');
        }

        return (string) $zip->getFromName($name);
    }

    private static function xml(string $xml): SimpleXMLElement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('This spreadsheet contains unsafe content and was refused.');
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            throw new RuntimeException('This spreadsheet could not be read.');
        }

        return $doc;
    }

    /** "C12" → 2 (zero-based column) */
    private static function columnIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
        $n = 0;

        foreach (str_split($m[1] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /** an Excel date serial (days since 1899-12-30) as Y-m-d; anything else is returned unchanged */
    public static function serialToDate(string $value): string
    {
        if (preg_match('/^\d{4,6}(\.\d+)?$/', $value) && (float) $value > 1 && (float) $value < 80000) {
            return gmdate('Y-m-d', (int) round(((float) $value - 25569) * 86400));
        }

        return $value;
    }

    // ------------------------------------------------------------------
    // writing
    // ------------------------------------------------------------------

    /**
     * An .xlsx file (as bytes) with $rows on one sheet. Text cells are made formula-safe.
     *
     * @param  array<int,array<int,mixed>>  $rows
     */
    public static function xlsx(array $rows, string $sheetName = 'Sheet1'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xl');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars(mb_substr($sheetName, 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');

        $body = '';
        foreach (array_values($rows) as $r => $row) {
            $body .= '<row r="' . ($r + 1) . '">';

            foreach (array_values($row) as $c => $value) {
                $ref = self::columnName($c) . ($r + 1);
                $text = htmlspecialchars(self::stripControl(Normalize::csvCell($value)), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $body .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }

            $body .= '</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $body . '</sheetData></worksheet>');

        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** CSV text (UTF-8 with a byte-order mark, so Excel shows Bangla correctly). Cells are formula-safe. */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => Normalize::csvCell($v), array_values($row)), ',', '"', '');
        }

        rewind($out);
        $text = stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF" . $text;
    }

    private static function columnName(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }

        return $name;
    }

    /** XML 1.0 cannot carry most control characters */
    private static function stripControl(string $text): string
    {
        return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text);
    }
}
