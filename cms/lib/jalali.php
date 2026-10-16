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

function cms_from_persian_digits(string $s): string
{
    return strtr($s, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

/**
 * Inverse of cms_gregorian_to_jalali (same jdf-family algorithm).
 *
 * @return array{0:int,1:int,2:int}
 */
function cms_jalali_to_gregorian(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4)
        + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28;
    $salA = [0, 31, $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 1;
    while ($gm < 13 && $gd > $salA[$gm]) {
        $gd -= $salA[$gm];
        $gm++;
    }

    return [$gy, $gm, $gd];
}

function cms_jalali_display_timezone(): string
{
    return 'Asia/Tehran';
}

/**
 * Parse a Jalali calendar date from UI input (Persian or Latin digits).
 *
 * @return array{0:int,1:int,2:int}|null [jy, jm, jd]
 */
function cms_jalali_parse_date(?string $input): ?array
{
    if ($input === null) {
        return null;
    }
    $raw = trim(cms_from_persian_digits($input));
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^(\d{3,4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $raw, $m)) {
        return null;
    }
    $jy = (int) $m[1];
    $jm = (int) $m[2];
    $jd = (int) $m[3];
    if ($jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
        return null;
    }
    $maxDay = $jm <= 6 ? 31 : ($jm <= 11 ? 30 : 30);
    if ($jd > $maxDay) {
        return null;
    }

    return [$jy, $jm, $jd];
}

/**
 * Format a UTC datetime string as Jalali date+time in Asia/Tehran (24-hour).
 * Example: 1405/07/23 - 14:35:22  (Latin digits; apply cms_to_persian_digits in UI).
 */
function cms_jalali_format_datetime(?string $utcDatetime, string $tz = 'Asia/Tehran'): string
{
    if ($utcDatetime === null || trim($utcDatetime) === '') {
        return '—';
    }
    try {
        $utc = new DateTimeImmutable(str_replace('T', ' ', trim($utcDatetime)), new DateTimeZone('UTC'));
        $local = $utc->setTimezone(new DateTimeZone($tz !== '' ? $tz : cms_jalali_display_timezone()));
    } catch (Throwable $e) {
        return $utcDatetime;
    }
    [$jy, $jm, $jd] = cms_gregorian_to_jalali(
        (int) $local->format('Y'),
        (int) $local->format('n'),
        (int) $local->format('j')
    );

    return sprintf('%04d/%02d/%02d - %s', $jy, $jm, $jd, $local->format('H:i:s'));
}

/**
 * Convert Jalali start/end date strings (Tehran calendar days) into UTC DATETIME bounds.
 *
 * @return array{start:?string,end:?string} UTC 'Y-m-d H:i:s' inclusive bounds
 */
function cms_jalali_range_to_utc(?string $jalaliStart, ?string $jalaliEnd, string $tz = 'Asia/Tehran'): array
{
    $zone = new DateTimeZone($tz !== '' ? $tz : cms_jalali_display_timezone());
    $start = null;
    $end = null;

    $parsedStart = cms_jalali_parse_date($jalaliStart);
    if ($parsedStart !== null) {
        [$gy, $gm, $gd] = cms_jalali_to_gregorian($parsedStart[0], $parsedStart[1], $parsedStart[2]);
        $local = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $gy, $gm, $gd), $zone);
        $start = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    $parsedEnd = cms_jalali_parse_date($jalaliEnd);
    if ($parsedEnd !== null) {
        [$gy, $gm, $gd] = cms_jalali_to_gregorian($parsedEnd[0], $parsedEnd[1], $parsedEnd[2]);
        $local = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $gy, $gm, $gd), $zone);
        $endExclusive = $local->modify('+1 day');
        $end = $endExclusive->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    return ['start' => $start, 'end' => $end];
}
