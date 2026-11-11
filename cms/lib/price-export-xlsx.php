<?php
declare(strict_types=1);

/**
 * Minimal XLSX writer for CMS exports.
 * Produces Google Sheets / Excel compatible OOXML (shared strings, valid ZIP).
 *
 * @param list<array{name:string,rows:list<list<string|int|null>>}> $sheets
 */
function price_export_xlsx_write(string $path, array $sheets): void
{
    if ($sheets === []) {
        throw new RuntimeException('داده‌ای برای خروجی Excel وجود ندارد');
    }

    $sharedList = [];
    $sharedIndex = [];
    $sheetParts = [];
    $workbookSheetEntries = [];
    $usedSheetNames = [];
    $contentTypeOverrides = [
        '/xl/workbook.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        '/xl/styles.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml',
        '/xl/sharedStrings.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml',
    ];
    $workbookRels = [];

    foreach ($sheets as $index => $sheet) {
        $sheetNumber = $index + 1;
        $partName = '/xl/worksheets/sheet' . $sheetNumber . '.xml';
        $relId = 'rId' . $sheetNumber;
        $sheetName = price_export_xlsx_unique_sheet_name(
            (string) ($sheet['name'] ?? ('Sheet' . $sheetNumber)),
            $usedSheetNames
        );
        $rows = is_array($sheet['rows'] ?? null) ? $sheet['rows'] : [];

        $sheetParts[ltrim($partName, '/')] = price_export_xlsx_build_sheet_xml(
            $rows,
            $sharedList,
            $sharedIndex
        );
        $contentTypeOverrides[$partName] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';
        $workbookSheetEntries[] = '<sheet name="' . price_export_xlsx_xml_attr($sheetName)
            . '" sheetId="' . $sheetNumber . '" r:id="' . $relId . '"/>';
        $workbookRels[] = '<Relationship Id="' . $relId
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'
            . $sheetNumber . '.xml"/>';
    }

    $stylesRelId = 'rId' . (count($sheets) + 1);
    $sharedRelId = 'rId' . (count($sheets) + 2);
    $workbookRels[] = '<Relationship Id="' . $stylesRelId
        . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $workbookRels[] = '<Relationship Id="' . $sharedRelId
        . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

    /** @var array<string, string> $entries */
    $entries = [
        '[Content_Types].xml' => price_export_xlsx_content_types_xml($contentTypeOverrides),
        '_rels/.rels' => price_export_xlsx_root_rels_xml(),
        'xl/workbook.xml' => price_export_xlsx_workbook_xml($workbookSheetEntries),
        'xl/_rels/workbook.xml.rels' => price_export_xlsx_workbook_rels_xml($workbookRels),
        'xl/styles.xml' => price_export_xlsx_styles_xml(),
        'xl/sharedStrings.xml' => price_export_xlsx_shared_strings_xml($sharedList),
    ];
    foreach ($sheetParts as $partName => $xml) {
        $entries[$partName] = $xml;
    }

    price_export_zip_write($path, $entries);
}

/**
 * @param array<string, string> $entries path inside archive => file contents
 */
function price_export_zip_write(string $path, array $entries): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('پوشه موقت برای Excel در دسترس نیست');
    }
    if (is_file($path) && !@unlink($path)) {
        throw new RuntimeException('ایجاد فایل Excel ناموفق بود');
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened === true) {
            foreach ($entries as $name => $data) {
                $name = str_replace('\\', '/', ltrim((string) $name, '/'));
                if ($zip->addFromString($name, (string) $data) === false) {
                    $zip->close();
                    @unlink($path);
                    throw new RuntimeException('نوشتن فایل Excel ناموفق بود');
                }
            }
            if ($zip->close() && is_file($path) && filesize($path) > 0) {
                return;
            }
            @unlink($path);
        }
    }

    price_export_zip_write_stored($path, $entries);
}

/**
 * Stored ZIP (method 0). Central-directory layout matches PKWARE APPNOTE (46-byte headers).
 *
 * @param array<string, string> $entries path inside archive => file contents
 */
function price_export_zip_write_stored(string $path, array $entries): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('پوشه موقت برای Excel در دسترس نیست');
    }

    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('ایجاد فایل Excel ناموفق بود');
    }

    /** @var list<array{name:string,crc:int,size:int,offset:int}> $central */
    $central = [];
    $offset = 0;

    foreach ($entries as $name => $data) {
        $name = str_replace('\\', '/', ltrim((string) $name, '/'));
        $data = (string) $data;
        $crc = crc32($data);
        $size = strlen($data);
        $nameLen = strlen($name);

        // Local file header: 30 bytes + name. Version 10 = stored/no compression.
        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            10,
            0,
            0,
            0,
            0,
            $crc,
            $size,
            $size,
            $nameLen,
            0
        ) . $name;

        if (fwrite($handle, $localHeader) === false || fwrite($handle, $data) === false) {
            fclose($handle);
            @unlink($path);
            throw new RuntimeException('نوشتن فایل Excel ناموفق بود');
        }

        $central[] = [
            'name' => $name,
            'crc' => $crc,
            'size' => $size,
            'offset' => $offset,
        ];
        $offset += strlen($localHeader) + $size;
    }

    $centralOffset = $offset;
    $centralSize = 0;
    foreach ($central as $entry) {
        $name = $entry['name'];
        $nameLen = strlen($name);
        // Central directory file header is 46 bytes: internal attrs=2, external attrs=4, offset=4.
        $centralHeader = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            10,
            10,
            0,
            0,
            0,
            0,
            $entry['crc'],
            $entry['size'],
            $entry['size'],
            $nameLen,
            0,
            0,
            0,
            0,
            0,
            $entry['offset']
        ) . $name;

        if (fwrite($handle, $centralHeader) === false) {
            fclose($handle);
            @unlink($path);
            throw new RuntimeException('نوشتن فایل Excel ناموفق بود');
        }
        $centralSize += strlen($centralHeader);
    }

    $count = count($central);
    $eocd = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $count,
        $count,
        $centralSize,
        $centralOffset,
        0
    );
    if (fwrite($handle, $eocd) === false) {
        fclose($handle);
        @unlink($path);
        throw new RuntimeException('نوشتن فایل Excel ناموفق بود');
    }

    fclose($handle);
}

/**
 * @param array<string, bool> $usedNames
 */
function price_export_xlsx_unique_sheet_name(string $name, array &$usedNames): string
{
    $base = price_export_xlsx_sanitize_sheet_name($name);
    $candidate = $base;
    $n = 2;
    while (isset($usedNames[price_export_xlsx_sheet_name_key($candidate)])) {
        $suffix = ' (' . $n . ')';
        $keep = 31 - price_export_xlsx_strlen($suffix);
        if ($keep < 1) {
            $candidate = price_export_xlsx_sanitize_sheet_name((string) $n);
        } else {
            $candidate = price_export_xlsx_sanitize_sheet_name(
                price_export_xlsx_substr($base, 0, $keep) . $suffix
            );
        }
        $n++;
        if ($n > 999) {
            break;
        }
    }
    $usedNames[price_export_xlsx_sheet_name_key($candidate)] = true;

    return $candidate;
}

function price_export_xlsx_sheet_name_key(string $name): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($name, 'UTF-8');
    }

    return strtolower($name);
}

function price_export_xlsx_strlen(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function price_export_xlsx_substr(string $value, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, $start, $length, 'UTF-8');
    }

    return (string) substr($value, $start, $length);
}

function price_export_xlsx_sanitize_sheet_name(string $name): string
{
    $name = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name));
    $name = str_replace("'", '', $name);
    $name = trim($name);
    if ($name === '') {
        $name = 'Sheet';
    }
    $name = price_export_xlsx_substr($name, 0, 31);
    $name = trim($name);
    if ($name === '') {
        return 'Sheet';
    }

    return $name;
}

/**
 * @param list<list<string|int|null>> $rows
 * @param list<string> $sharedList
 * @param array<string, int> $sharedIndex
 */
function price_export_xlsx_build_sheet_xml(array $rows, array &$sharedList, array &$sharedIndex): string
{
    $rowXml = [];
    $maxCol = 0;
    $maxRow = 0;
    foreach ($rows as $rowIndex => $cells) {
        if (!is_array($cells)) {
            continue;
        }
        $rowNumber = $rowIndex + 1;
        $cellXml = [];
        foreach (array_values($cells) as $colIndex => $value) {
            $ref = price_export_xlsx_cell_ref($colIndex, $rowNumber);
            $sstIndex = price_export_xlsx_shared_string_index(
                price_export_xlsx_cell_text($value),
                $sharedList,
                $sharedIndex
            );
            $cellXml[] = '<c r="' . price_export_xlsx_xml_attr($ref) . '" t="s"><v>'
                . $sstIndex
                . '</v></c>';
            if ($colIndex + 1 > $maxCol) {
                $maxCol = $colIndex + 1;
            }
        }
        $rowXml[] = '<row r="' . $rowNumber . '">' . implode('', $cellXml) . '</row>';
        $maxRow = $rowNumber;
    }

    $dimension = 'A1';
    if ($maxRow > 0 && $maxCol > 0) {
        $dimension = 'A1:' . price_export_xlsx_col_letters($maxCol - 1) . $maxRow;
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="' . price_export_xlsx_xml_attr($dimension) . '"/>'
        . '<sheetData>' . implode('', $rowXml) . '</sheetData>'
        . '</worksheet>';
}

function price_export_xlsx_cell_ref(int $colIndex, int $rowNumber): string
{
    return price_export_xlsx_col_letters($colIndex) . $rowNumber;
}

function price_export_xlsx_col_letters(int $index): string
{
    $index = max(0, $index);
    $letters = '';
    do {
        $letters = chr(65 + ($index % 26)) . $letters;
        $index = intdiv($index, 26) - 1;
    } while ($index >= 0);

    return $letters;
}

/**
 * @param string|int|float|null $value
 */
function price_export_xlsx_cell_text($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_int($value) || is_float($value)) {
        $value = (string) $value;
    } else {
        $value = (string) $value;
    }

    return price_export_xlsx_strip_invalid_xml($value);
}

function price_export_xlsx_strip_invalid_xml(string $value): string
{
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

    return $clean === null ? '' : $clean;
}

/**
 * @param list<string> $sharedList
 * @param array<string, int> $sharedIndex
 */
function price_export_xlsx_shared_string_index(string $text, array &$sharedList, array &$sharedIndex): int
{
    $key = 's:' . $text;
    if (isset($sharedIndex[$key])) {
        return $sharedIndex[$key];
    }
    $idx = count($sharedList);
    $sharedList[] = $text;
    $sharedIndex[$key] = $idx;

    return $idx;
}

/**
 * @param list<string> $sharedList
 */
function price_export_xlsx_shared_strings_xml(array $sharedList): string
{
    $count = count($sharedList);
    $parts = [];
    foreach ($sharedList as $text) {
        $parts[] = '<si><t xml:space="preserve">' . price_export_xlsx_xml_text($text) . '</t></si>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
        . $count . '" uniqueCount="' . $count . '">'
        . implode('', $parts)
        . '</sst>';
}

function price_export_xlsx_xml_text(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function price_export_xlsx_xml_attr(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string, string> $overrides partName => contentType
 */
function price_export_xlsx_content_types_xml(array $overrides): string
{
    $parts = [
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
        '<Default Extension="xml" ContentType="application/xml"/>',
    ];
    foreach ($overrides as $partName => $contentType) {
        $parts[] = '<Override PartName="' . price_export_xlsx_xml_attr($partName)
            . '" ContentType="' . price_export_xlsx_xml_attr($contentType) . '"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . implode('', $parts)
        . '</Types>';
}

function price_export_xlsx_root_rels_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

/**
 * @param list<string> $sheetEntries
 */
function price_export_xlsx_workbook_xml(array $sheetEntries): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<workbookPr/>'
        . '<sheets>' . implode('', $sheetEntries) . '</sheets>'
        . '</workbook>';
}

/**
 * @param list<string> $relationships
 */
function price_export_xlsx_workbook_rels_xml(array $relationships): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', $relationships)
        . '</Relationships>';
}

function price_export_xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
        . '<fills count="2">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function price_export_xlsx_send_download(string $filename, string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('فایل Excel یافت نشد');
    }

    $asciiFallback = preg_replace('/[^\x20-\x7E]+/u', '-', $filename) ?? 'price-sheet.xlsx';
    $asciiFallback = trim((string) preg_replace('/-+/', '-', $asciiFallback), '-.');
    $asciiLower = strtolower($asciiFallback);
    $asciiHasXlsx = strlen($asciiLower) >= 5 && substr($asciiLower, -5) === '.xlsx';
    if ($asciiFallback === '' || $asciiFallback[0] === '.' || !$asciiHasXlsx) {
        $asciiFallback = 'price-sheet.xlsx';
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $asciiFallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: max-age=0, no-cache, must-revalidate');
    header('Pragma: public');

    readfile($path);
}
