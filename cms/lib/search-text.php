<?php
declare(strict_types=1);

/**
 * Normalize user search text for matching (XAMPP/MySQL utf8mb4).
 * Persian/Arabic digits → ASCII, Arabic Yeh/Kaf → Persian, collapse space.
 */
function search_normalize(string $raw): string
{
    $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        'ي' => 'ی',
        'ك' => 'ک',
        'ة' => 'ه',
        '‌' => ' ',
    ];
    $s = strtr(trim($raw), $map);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }
    return trim($s);
}

function search_like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/** SQL expression that normalizes Yeh/Kaf on a column for LIKE matching. */
function search_name_sql(string $column): string
{
    return "REPLACE(REPLACE({$column}, 'ي', 'ی'), 'ك', 'ک')";
}

function search_visual_id_normalize(string $visualId): string
{
    $visualId = search_normalize($visualId);
    if ($visualId === '') {
        return '';
    }
    if (is_numeric($visualId)) {
        return (string) (int) round((float) $visualId);
    }

    return $visualId;
}

/** @return list<string> */
function search_visual_id_lookup_keys(string $raw): array
{
    $visualId = search_visual_id_normalize($raw);
    if ($visualId === '') {
        return [];
    }

    $keys = [$visualId];
    if (ctype_digit($visualId)) {
        $keys[] = 'st-' . $visualId;
        $keys[] = 'kit-' . $visualId;
        $keys[] = 'st_' . $visualId;
        $keys[] = 'kit_' . $visualId;
    }

    foreach (['st-', 'kit-', 'st_', 'kit_'] as $prefix) {
        if (stripos($visualId, $prefix) === 0) {
            $stripped = search_visual_id_normalize(substr($visualId, strlen($prefix)));
            if ($stripped !== '' && $stripped !== $visualId) {
                $keys[] = $stripped;
            }
        }
    }

    if (preg_match('/(\d+)$/', $visualId, $matches)) {
        $suffix = search_visual_id_normalize((string) $matches[1]);
        if ($suffix !== '' && $suffix !== $visualId) {
            $keys[] = $suffix;
        }
    }

    return array_values(array_unique(array_filter(
        $keys,
        static fn (string $key): bool => $key !== ''
    )));
}

/**
 * Build a LIKE clause that matches display-id codes with optional ST-/KIT- prefixes.
 *
 * @return array{0:string,1:list<string>}
 */
function search_visual_id_like_clause(string $column, string $rawQ): array
{
    $keys = search_visual_id_lookup_keys($rawQ);
    if ($keys === []) {
        return ['0=1', []];
    }

    $parts = [];
    $params = [];
    foreach ($keys as $key) {
        $parts[] = 'COALESCE(' . search_name_sql($column) . ", '') LIKE ?";
        $params[] = '%' . search_like_escape($key) . '%';
    }

    return ['(' . implode(' OR ', $parts) . ')', $params];
}
