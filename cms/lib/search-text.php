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
