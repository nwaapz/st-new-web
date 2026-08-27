<?php
declare(strict_types=1);

require_once __DIR__ . '/price-import-zip-fallback.php';

/**
 * Minimal XLSX reader — ZipArchive when available, pure-PHP ZIP fallback otherwise.
 *
 * @return list<list<mixed>> rows as 0-indexed columns A=0 ..
 */
function price_import_xlsx_read_rows(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('فایل یافت نشد');
    }
    if (!price_import_xlsx_supported()) {
        throw new RuntimeException(
            'خواندن xlsx روی این سرور ممکن نیست. فایل را CSV کنید (Save As → CSV UTF-8) یا در cPanel افزونه zip را فعال کنید.'
        );
    }

    $sharedStrings = price_import_xlsx_read_shared_strings_from_path($path);
    $sheetXml = price_import_zip_get_contents($path, 'xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $sheetXml = price_import_xlsx_find_first_sheet_from_path($path);
    }

    if ($sheetXml === false || $sheetXml === '') {
        throw new RuntimeException('برگه اول Excel یافت نشد');
    }

    return price_import_xlsx_parse_sheet($sheetXml, $sharedStrings);
}

/** @return list<string> */
function price_import_xlsx_read_shared_strings_from_path(string $path): array
{
    $xml = price_import_zip_get_contents($path, 'xl/sharedStrings.xml');
    if ($xml === false || trim($xml) === '') {
        return [];
    }

    $doc = simplexml_load_string($xml);
    if ($doc === false) {
        return [];
    }

    $strings = [];
    foreach ($doc->si as $si) {
        if (isset($si->t)) {
            $strings[] = (string) $si->t;
            continue;
        }
        $text = '';
        foreach ($si->r as $run) {
            $text .= (string) ($run->t ?? '');
        }
        $strings[] = $text;
    }

    return $strings;
}

function price_import_xlsx_find_first_sheet_from_path(string $path)
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $content = $zip->getFromIndex($i);
                    $zip->close();
                    if ($content !== false) {
                        return $content;
                    }
                }
            }
            $zip->close();
        }
    }

    foreach (price_import_zip_fallback_list($path) as $name) {
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
            $content = price_import_zip_fallback_extract($path, $name);
            if ($content !== false) {
                return $content;
            }
        }
    }

    return false;
}

/**
 * @param list<string> $sharedStrings
 * @return list<list<mixed>>
 */
function price_import_xlsx_parse_sheet(string $xml, array $sharedStrings): array
{
    $doc = simplexml_load_string($xml);
    if ($doc === false) {
        throw new RuntimeException('XML برگه Excel نامعتبر است');
    }

    $rows = [];
    $maxCol = 0;

    foreach ($doc->sheetData->row as $row) {
        $rowIndex = (int) ($row['r'] ?? 0);
        if ($rowIndex <= 0) {
            continue;
        }
        $cells = [];
        foreach ($row->c as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            if ($ref === '') {
                continue;
            }
            $colIndex = price_import_xlsx_col_index($ref);
            $cells[$colIndex] = price_import_xlsx_cell_value($cell, $sharedStrings);
            if ($colIndex > $maxCol) {
                $maxCol = $colIndex;
            }
        }
        if ($cells === []) {
            continue;
        }
        $line = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $line[$c] = $cells[$c] ?? null;
        }
        $rows[$rowIndex] = $line;
    }

    ksort($rows);
    return array_values($rows);
}

function price_import_xlsx_col_index(string $cellRef): int
{
    if (!preg_match('/^([A-Z]+)/', $cellRef, $m)) {
        return 0;
    }
    $letters = $m[1];
    $index = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

/** @param list<string> $sharedStrings */
function price_import_xlsx_cell_value(SimpleXMLElement $cell, array $sharedStrings)
{
    $type = (string) ($cell['t'] ?? '');
    if ($type === 'inlineStr') {
        return trim((string) ($cell->is->t ?? ''));
    }
    if (!isset($cell->v)) {
        return null;
    }
    $raw = (string) $cell->v;
    if ($type === 's') {
        $idx = (int) $raw;
        return $sharedStrings[$idx] ?? '';
    }
    if ($type === 'b') {
        return $raw === '1';
    }
    if (is_numeric($raw)) {
        return strpos($raw, '.') !== false ? (float) $raw : (int) $raw;
    }
    return $raw;
}
