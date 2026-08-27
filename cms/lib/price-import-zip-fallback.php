<?php
declare(strict_types=1);

/**
 * Pure-PHP ZIP reader for .xlsx on hosts without ext-zip / ZipArchive.
 * Supports stored (0) and deflate (8) entries — enough for standard Excel files.
 */

/** @return array<string, array{offset:int, comp_size:int, uncomp_size:int, method:int}> */
function price_import_zip_index(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('فایل ZIP قابل خواندن نیست');
    }

    $size = filesize($path);
    if ($size === false || $size < 22) {
        fclose($fh);
        throw new RuntimeException('فایل ZIP نامعتبر است');
    }

    $maxComment = min($size, 65557);
    $buffer = '';
    fseek($fh, max(0, $size - $maxComment));
    $buffer = fread($fh, $maxComment);
    fclose($fh);

    if ($buffer === false) {
        throw new RuntimeException('خواندن فایل ZIP ناموفق بود');
    }

    $eocdPos = strrpos($buffer, "\x50\x4b\x05\x06");
    if ($eocdPos === false) {
        throw new RuntimeException('ساختار ZIP نامعتبر است');
    }

    $eocd = substr($buffer, $eocdPos, 22);
    if (strlen($eocd) < 22) {
        throw new RuntimeException('ساختار ZIP نامعتبر است');
    }

    $centralOffset = unpack('V', substr($eocd, 16, 4))[1];
    $centralCount = unpack('v', substr($eocd, 10, 2))[1];

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('فایل ZIP قابل خواندن نیست');
    }

    fseek($fh, $centralOffset);
    $index = [];

    for ($i = 0; $i < $centralCount; $i++) {
        $header = fread($fh, 46);
        if ($header === false || strlen($header) < 46) {
            break;
        }
        if (substr($header, 0, 4) !== "\x50\x4b\x01\x02") {
            break;
        }

        $method = unpack('v', substr($header, 10, 2))[1];
        $compSize = unpack('V', substr($header, 20, 4))[1];
        $uncompSize = unpack('V', substr($header, 24, 4))[1];
        $nameLen = unpack('v', substr($header, 28, 2))[1];
        $extraLen = unpack('v', substr($header, 30, 2))[1];
        $commentLen = unpack('v', substr($header, 32, 2))[1];
        $localOffset = unpack('V', substr($header, 42, 4))[1];

        $name = fread($fh, $nameLen);
        if ($name === false) {
            break;
        }
        if ($extraLen > 0) {
            fseek($fh, ftell($fh) + $extraLen);
        }
        if ($commentLen > 0) {
            fseek($fh, ftell($fh) + $commentLen);
        }

        $index[str_replace('\\', '/', $name)] = [
            'offset' => $localOffset,
            'comp_size' => $compSize,
            'uncomp_size' => $uncompSize,
            'method' => $method,
        ];
    }

    fclose($fh);
    return $index;
}

function price_import_zip_inflate(string $data, int $method, int $uncompSize): string
{
    if ($method === 0) {
        return $data;
    }
    if ($method !== 8) {
        throw new RuntimeException('فشرده‌سازی ZIP پشتیبانی نمی‌شود (method ' . $method . ')');
    }

    if (function_exists('inflate_init')) {
        $ctx = inflate_init(ZLIB_ENCODING_RAW);
        if ($ctx === false) {
            throw new RuntimeException('inflate_init ناموفق بود');
        }
        $out = inflate_add($ctx, $data, ZLIB_FINISH);
        if ($out === false) {
            throw new RuntimeException('باز کردن فایل فشرده ZIP ناموفق بود');
        }
        return $out;
    }

    $out = @gzinflate($data);
    if ($out !== false) {
        return $out;
    }
    $out = @gzuncompress($data);
    if ($out !== false) {
        return $out;
    }

    throw new RuntimeException('باز کردن فایل فشرده ZIP ناموفق بود — zlib/raw inflate در دسترس نیست');
}

function price_import_zip_fallback_extract(string $path, string $innerName)
{
    $innerName = str_replace('\\', '/', $innerName);
    $index = price_import_zip_index($path);
    if (!isset($index[$innerName])) {
        return false;
    }

    $meta = $index[$innerName];
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }

    fseek($fh, $meta['offset']);
    $local = fread($fh, 30);
    if ($local === false || strlen($local) < 30 || substr($local, 0, 4) !== "\x50\x4b\x03\x04") {
        fclose($fh);
        return false;
    }

    $nameLen = unpack('v', substr($local, 26, 2))[1];
    $extraLen = unpack('v', substr($local, 28, 2))[1];
    fread($fh, $nameLen + $extraLen);

    $compressed = fread($fh, $meta['comp_size']);
    fclose($fh);

    if ($compressed === false) {
        return false;
    }

    try {
        return price_import_zip_inflate($compressed, $meta['method'], $meta['uncomp_size']);
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<string> */
function price_import_zip_fallback_list(string $path): array
{
    return array_keys(price_import_zip_index($path));
}

function price_import_zip_get_contents(string $path, string $innerName)
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $content = $zip->getFromName($innerName);
            $zip->close();
            if ($content !== false) {
                return $content;
            }
        }
    }

    return price_import_zip_fallback_extract($path, $innerName);
}

function price_import_xlsx_supported(): bool
{
    if (class_exists('ZipArchive')) {
        return true;
    }
    return function_exists('inflate_init') || function_exists('gzinflate');
}

function price_import_xlsx_support_hint(): string
{
    if (class_exists('ZipArchive')) {
        return 'ZipArchive فعال است.';
    }
    if (function_exists('inflate_init') || function_exists('gzinflate')) {
        return 'ZipArchive غیرفعال است — از خواننده PHP داخلی استفاده می‌شود (بدون نیاز به ریستارت Apache).';
    }
    return 'خواندن xlsx ممکن نیست — فایل CSV آپلود کنید، یا در cPanel → Select PHP Version → Extensions تیک zip را بزنید.';
}
