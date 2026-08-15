<?php
declare(strict_types=1);

/**
 * Warranty serial registration + lookup (StarTech "Dr-Tech" portal rebuild).
 * One unified table replaces the legacy seller/Mcode/old_serials split.
 */

function warranty_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS warranty_serials (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          serial VARCHAR(64) NOT NULL,
          kind ENUM('mcode','old_serial') NOT NULL,
          status ENUM('pending','registered') NOT NULL DEFAULT 'pending',
          phone VARCHAR(20) NULL,
          user_id INT UNSIGNED NULL,
          city VARCHAR(191) NULL,
          km VARCHAR(32) NULL,
          car_plate VARCHAR(32) NULL,
          registered_at DATETIME NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_warranty_serial (serial),
          KEY idx_warranty_phone (phone),
          KEY idx_warranty_user (user_id),
          KEY idx_warranty_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

/**
 * Persian/Arabic digits → English, trim, uppercase-safe (kept as-typed otherwise).
 */
function warranty_normalize_serial(string $raw): string
{
    $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];
    return trim(strtr(trim($raw), $map));
}

/**
 * @return 'mcode'|'old_serial'|'timing_belt'|'invalid'
 */
function warranty_detect_kind(string $serial): string
{
    $first = substr($serial, 0, 1);
    if ($first === '') {
        return 'invalid';
    }
    if (strtoupper($first) === 'M') {
        return 'mcode';
    }
    if (ctype_alpha($first)) {
        return 'old_serial';
    }
    if (ctype_digit($first)) {
        return 'timing_belt';
    }
    return 'invalid';
}

function warranty_mask_phone(?string $phone): string
{
    $phone = (string) $phone;
    if ($phone === '' || strlen($phone) < 6) {
        return '***';
    }
    return substr($phone, 0, 4) . '***' . substr($phone, -2);
}

/**
 * @return array<string, mixed>
 */
function warranty_serialize(array $row, bool $maskPhoneNumber = false): array
{
    $regAt = $row['registered_at'] ?? null;
    return [
        'id' => (int) $row['id'],
        'serial' => (string) $row['serial'],
        'kind' => (string) $row['kind'],
        'status' => (string) $row['status'],
        'phone' => $maskPhoneNumber ? warranty_mask_phone($row['phone'] ?? null) : (string) ($row['phone'] ?? ''),
        'city' => (string) ($row['city'] ?? ''),
        'km' => (string) ($row['km'] ?? ''),
        'car_plate' => (string) ($row['car_plate'] ?? ''),
        'registered_at' => $regAt,
        'registered_date' => function_exists('cms_jalali_format_from_timestamp')
            ? cms_to_persian_digits(cms_jalali_format_from_timestamp($regAt))
            : ($regAt ?? '—'),
    ];
}

/**
 * Human label for a warranty kind — used in lists/PDF.
 */
function warranty_kind_label(string $kind): string
{
    if ($kind === 'mcode') {
        return 'فروشگاهی (M)';
    }
    if ($kind === 'seller') {
        return 'فروشگاهی (S)';
    }
    if ($kind === 'old_serial') {
        return 'سریال';
    }
    return $kind;
}
