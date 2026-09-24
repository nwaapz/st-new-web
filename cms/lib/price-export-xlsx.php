<?php
declare(strict_types=1);

/**
 * Minimal XLSX writer for CMS exports (pure-PHP ZIP, no ZipArchive required).
 *
 * @param list<array{name:string,rows:list<list<string|int|null>>}> $sheets
 */
function price_export_xlsx_write(string $path, array $sheets): void
{
    if ($sheets === []) {
        throw new RuntimeException('داده‌ای برای خروجی Excel وجود ندارد');
    }

    $sheetParts = [];
    $workbookSheetEntries = [];
    $contentTypeOverrides = [
        '/xl/workbook.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        '/xl/styles.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml',
    ];
    $workbookRels = [];

    foreach ($sheets as $index => $sheet) {
        $sheetNumber = $index + 1;
        $partName = '/xl/worksheets/sheet' . $sheetNumber . '.xml';
        $relId = 'rId' . $sheetNumber;
        $sheetName = price_export_xlsx_sanitize_sheet_name((string) ($sheet['name'] ?? ('Sheet' . $sheetNumber)));
        $rows = is_array($sheet['rows'] ?? null) ? $sheet['rows'] : [];

        $sheetParts[ltrim($partName, '/')] = price_export_xlsx_build_sheet_xml($rows);
        $contentTypeOverrides[$partName] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';
        $workbookSheetEntries[] = '<sheet name="' . price_export_xlsx_xml_attr($sheetName)
            . '" sheetId="' . $sheetNumber . '" r:id="' . $relId . '"/>';
        $workbookRels[] = '<Relationship Id="' . $relId
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'
            . $sheetNumber . '.xml"/>';
    }

    $stylesRelId = 'rId' . (count($sheets) + 1);
    $workbookRels[] = '<Relationship Id="' . $stylesRelId
        . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    /** @var array<string, string> $entries */
    $entries = [
        '[Content_Types].xml' => price_export_xlsx_content_types_xml($contentTypeOverrides),
        '_rels/.rels' => price_export_xlsx_root_rels_xml(),
        'xl/workbook.xml' => price_export_xlsx_workbook_xml($workbookSheetEntries),
        'xl/_rels/workbook.xml.rels' => price_export_xlsx_workbook_rels_xml($workbookRels),
        'xl/styles.xml' => price_export_xlsx_styles_xml(),
    ];
    foreach ($sheetParts as $partName => $xml) {
        $entries[$partName] = $xml;
    }

    price_export_zip_write_stored($path, $entries);
}

/**
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
        $crc = crc32($data) & 0xffffffff;
        $size = strlen($data);
        $nameLen = strlen($name);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
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
        $centralHeader = pack(
            'VvvvvvvVVVvvvvVVV',
            0x02014b50,
            20,
            20,
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
function price_export_xlsx_sanitize_sheet_name(string $name): string
{
    $name = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name));
    if ($name === '') {
        return 'Sheet';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 31);
    }

    return substr($name, 0, 31);
}

/**
 * @param list<list<string|int|null>> $rows
 */
function price_export_xlsx_build_sheet_xml(array $rows): string
{
    $rowXml = [];
    foreach ($rows as $rowIndex => $cells) {
        if (!is_array($cells)) {
            continue;
        }
        $rowNumber = $rowIndex + 1;
        $cellXml = [];
        foreach (array_values($cells) as $colIndex => $value) {
            $ref = price_export_xlsx_cell_ref($colIndex, $rowNumber);
            $cellXml[] = price_export_xlsx_inline_cell($ref, $value);
        }
        $rowXml[] = '<row r="' . $rowNumber . '">' . implode('', $cellXml) . '</row>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
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

function price_export_xlsx_inline_cell(string $ref, mixed $value): string
{
    if ($value === null) {
        $value = '';
    } elseif (is_int($value) || is_float($value)) {
        $value = (string) $value;
    } else {
        $value = (string) $value;
    }

    return '<c r="' . price_export_xlsx_xml_attr($ref) . '" t="inlineStr"><is><t>'
        . price_export_xlsx_xml_text($value)
        . '</t></is></c>';
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
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '</styleSheet>';
}

function price_export_xlsx_send_download(string $filename, string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('فایل Excel یافت نشد');
    }

    $asciiFallback = preg_replace('/[^\x20-\x7E]+/u', '-', $filename) ?? 'price-sheet.xlsx';
    $asciiFallback = trim((string) preg_replace('/-+/', '-', $asciiFallback), '-');
    if ($asciiFallback === '' || !str_ends_with(strtolower($asciiFallback), '.xlsx')) {
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