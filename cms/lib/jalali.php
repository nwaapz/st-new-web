<?php
declare(strict_types=1);

/**
 * Gregorian ↔ Jalali helpers for invoice dates.
 */

/** @return array{0:int,1:int,2:int} */
function cms_gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
    $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
    return [$jy, $jm, $jd];
}

function cms_jalali_format_from_timestamp(?string $datetime): string
{
    if ($datetime === null || trim($datetime) === '') {
        return '—';
    }
    $ts = strtotime(str_replace('T', ' ', $datetime));
    if ($ts === false) {
        return $datetime;
    }
    [$jy, $jm, $jd] = cms_gregorian_to_jalali(
        (int) date('Y', $ts),
        (int) date('n', $ts),
        (int) date('j', $ts)
    );
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

function cms_jalali_format_date(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return '—';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
        [$jy, $jm, $jd] = cms_gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
    return cms_jalali_format_from_timestamp($date);
}

function cms_to_persian_digits(string $s): string
{
    return strtr($s, [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ]);
}
