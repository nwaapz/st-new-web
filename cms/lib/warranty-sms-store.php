<?php
declare(strict_types=1);

/**
 * Warranty serial lookup + registration against startech_sms (old_serials / new_serials).
 * Mirrors SMS.php behavior for the website /warranty portal.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/warranty.php';

const WARRANTY_SMS_SCORE_DEFAULT = 10.0;
const WARRANTY_SMS_SELLER_CATEGORY = 'seller_to_end_user';

function warranty_sms_pdo(): PDO
{
    $pdo = cms_sms_pdo();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('اتصال دیتابیس گارانتی تنظیم نشده است');
    }
    return $pdo;
}

function warranty_sms_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '98') === 0 && strlen($digits) >= 12) {
        $digits = '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

/**
 * @return string[]
 */
function warranty_sms_phone_candidates(string $phone): array
{
    $norm = warranty_sms_normalize_phone($phone);
    if ($norm === '') {
        return [];
    }
    $out = [$norm];
    if (strpos($norm, '0') === 0 && strlen($norm) === 11) {
        $out[] = substr($norm, 1);
        $out[] = '98' . substr($norm, 1);
    }
    return array_values(array_unique($out));
}

/**
 * @return string[]
 */
function warranty_sms_serial_candidates(string $serial): array
{
    $serial = strtoupper(trim($serial));
    if ($serial === '') {
        return [];
    }
    $out = [$serial];
    if (preg_match('/^([A-Z]+)(\d+)$/', $serial, $m)) {
        $prefix = $m[1];
        $num = (int) $m[2];
        $raw = (string) $num;
        $out[] = $prefix . $raw;
        for ($w = strlen($raw); $w <= 8; $w++) {
            $out[] = $prefix . str_pad($raw, $w, '0', STR_PAD_LEFT);
        }
    }
    return array_values(array_unique($out));
}

function warranty_sms_is_mprefix(string $serial): bool
{
    return strtoupper(substr(trim($serial), 0, 1)) === 'M';
}

function warranty_sms_is_s_to_m_range(string $serial): bool
{
    $serial = strtoupper(trim($serial));
    if (!preg_match('/^S(\d+)$/', $serial, $m)) {
        return false;
    }
    $num = (int) $m[1];
    return $num >= 440000 && $num <= 450000;
}

function warranty_sms_is_seller_category(array $row): bool
{
    return trim((string) ($row['category'] ?? '')) === WARRANTY_SMS_SELLER_CATEGORY;
}

/**
 * @param array<string, mixed>|null $row
 */
function warranty_sms_is_seller_serial(string $serial, ?array $row = null): bool
{
    if (warranty_sms_is_mprefix($serial) || warranty_sms_is_s_to_m_range($serial)) {
        return true;
    }
    return is_array($row) && warranty_sms_is_seller_category($row);
}

function warranty_sms_has_column(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $col]);
        $cache[$key] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * @return string[]
 */
function warranty_sms_tables(PDO $pdo): array
{
    static $cache = [];
    $dbKey = spl_object_hash($pdo);
    if (isset($cache[$dbKey])) {
        return $cache[$dbKey];
    }
    $tables = [];
    foreach (['old_serials', 'new_serials'] as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            if ($stmt && $stmt->fetch()) {
                $tables[] = $table;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    $cache[$dbKey] = $tables;
    return $tables;
}

/**
 * @return array<string, mixed>|null row with _table key
 */
function warranty_sms_find_row(PDO $pdo, string $serial): ?array
{
    $candidates = warranty_sms_serial_candidates($serial);
    if ($candidates === []) {
        return null;
    }
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    foreach (warranty_sms_tables($pdo) as $table) {
        $sql = "SELECT * FROM `$table` WHERE UPPER(serial) IN ($placeholders) LIMIT 1";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->execute($candidates);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $row['_table'] = $table;
            return $row;
        }
    }
    return null;
}

function warranty_sms_phone_is_bound(?string $phone): bool
{
    $phone = trim((string) $phone);
    return $phone !== '' && $phone !== '0';
}

/**
 * @param array<string, mixed> $row
 */
function warranty_sms_registration_incomplete(array $row): bool
{
    $serial = (string) ($row['serial'] ?? '');
    $city = trim((string) ($row['city'] ?? ''));
    if (warranty_sms_is_seller_serial($serial, $row)) {
        return $city === '';
    }
    $km = $row['km'] ?? null;
    $kmMissing = ($km === null || $km === '' || (int) $km === 0);
    return $city === '' || $kmMissing;
}

/**
 * @param array<string, mixed> $row
 * @return 'seller'|'client'
 */
function warranty_sms_pipeline(array $row): string
{
    $serial = (string) ($row['serial'] ?? '');
    return warranty_sms_is_seller_serial($serial, $row) ? 'seller' : 'client';
}

/**
 * @param array<string, mixed> $row
 * @return 'mcode'|'old_serial'|'seller'
 */
function warranty_sms_row_kind(array $row): string
{
    if (warranty_sms_is_seller_serial((string) ($row['serial'] ?? ''), $row)) {
        return warranty_sms_is_mprefix((string) ($row['serial'] ?? '')) ? 'mcode' : 'seller';
    }
    return 'old_serial';
}

/**
 * @return array{
 *   status: 'not_found'|'registrable'|'registered',
 *   pipeline?: 'seller'|'client',
 *   kind?: string,
 *   phone_masked?: string,
 *   serial?: string
 * }
 */
function warranty_sms_check_status(PDO $pdo, string $serial, ?string $userPhone = null): array
{
    $row = warranty_sms_find_row($pdo, $serial);
    if ($row === null) {
        return ['status' => 'not_found'];
    }

    $dbSerial = (string) $row['serial'];
    $pipeline = warranty_sms_pipeline($row);
    $kind = warranty_sms_row_kind($row);
    $bound = warranty_sms_phone_is_bound($row['phone'] ?? null);
    $incomplete = warranty_sms_registration_incomplete($row);
    $userNorm = $userPhone !== null ? warranty_sms_normalize_phone($userPhone) : '';
    $rowNorm = warranty_sms_normalize_phone((string) ($row['phone'] ?? ''));

    if (!$bound) {
        return [
            'status' => 'registrable',
            'pipeline' => $pipeline,
            'kind' => $kind,
            'serial' => $dbSerial,
        ];
    }

    if ($incomplete && $userNorm !== '' && $rowNorm !== '' && $userNorm === $rowNorm) {
        return [
            'status' => 'registrable',
            'pipeline' => $pipeline,
            'kind' => $kind,
            'serial' => $dbSerial,
        ];
    }

    return [
        'status' => 'registered',
        'kind' => $kind,
        'phone_masked' => warranty_mask_phone((string) ($row['phone'] ?? '')),
        'serial' => $dbSerial,
    ];
}

/**
 * @param array{city:string, km?:string, car_plate?:string} $fields
 * @return array<string, mixed> updated row
 */
function warranty_sms_register(PDO $pdo, string $serial, string $phone, array $fields): array
{
    $row = warranty_sms_find_row($pdo, $serial);
    if ($row === null) {
        throw new RuntimeException('کد سریال در سیستم یافت نشد');
    }

    $dbSerial = (string) $row['serial'];
    $table = (string) ($row['_table'] ?? 'old_serials');
    $phoneNorm = warranty_sms_normalize_phone($phone);
    if ($phoneNorm === '') {
        throw new RuntimeException('شماره موبایل معتبر نیست');
    }

    $bound = warranty_sms_phone_is_bound($row['phone'] ?? null);
    $rowNorm = warranty_sms_normalize_phone((string) ($row['phone'] ?? ''));
    $incomplete = warranty_sms_registration_incomplete($row);

    if ($bound) {
        if (!$incomplete) {
            throw new RuntimeException('این سریال قبلاً ثبت شده است');
        }
        if ($rowNorm !== $phoneNorm) {
            throw new RuntimeException('این سریال قبلاً ثبت شده است');
        }
    }

    $city = trim((string) ($fields['city'] ?? ''));
    $km = trim((string) ($fields['km'] ?? ''));
    $carPlate = trim((string) ($fields['car_plate'] ?? ''));
    $isSeller = warranty_sms_is_seller_serial($dbSerial, $row);

    if ($city === '') {
        throw new RuntimeException('شهر الزامی است');
    }
    if (!$isSeller) {
        if ($km === '') {
            throw new RuntimeException('کیلومتراژ الزامی است');
        }
        if ($carPlate === '') {
            throw new RuntimeException('پلاک خودرو الزامی است');
        }
    }

    $currentTime = date('Y-m-d');
    $syncMs = (int) round(microtime(true) * 1000);
    $hasSync = warranty_sms_has_column($pdo, $table, 'sync_updated_ms');
    $hasScore = warranty_sms_has_column($pdo, $table, 'score');
    $hasCategory = warranty_sms_has_column($pdo, $table, 'category');
    $hasCarPlate = warranty_sms_has_column($pdo, $table, 'car_plate');

    $sets = ['phone = ?', 'time = ?', 'city = ?'];
    $params = [$phoneNorm, $currentTime, $city];

    if ($isSeller) {
        if ($hasScore) {
            $sets[] = 'score = GREATEST(IFNULL(score, 0), ?)';
            $params[] = WARRANTY_SMS_SCORE_DEFAULT;
        }
        if ($hasCategory) {
            $sets[] = 'category = ?';
            $params[] = WARRANTY_SMS_SELLER_CATEGORY;
        }
    } else {
        $sets[] = 'km = ?';
        $params[] = (int) $km;
        if ($hasCarPlate && $carPlate !== '') {
            $sets[] = 'car_plate = ?';
            $params[] = $carPlate;
        }
    }

    if ($hasSync) {
        $sets[] = 'sync_updated_ms = ?';
        $params[] = $syncMs;
    }

    $params[] = $dbSerial;
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE serial = ?';
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute($params)) {
        throw new RuntimeException('خطا در ثبت گارانتی');
    }
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('این سریال قبلاً ثبت شده است');
    }

    $updated = warranty_sms_find_row($pdo, $dbSerial);
    if ($updated === null) {
        throw new RuntimeException('خطا در ثبت گارانتی');
    }
    return $updated;
}

/**
 * @return list<array<string, mixed>>
 */
function warranty_sms_list_by_phone(PDO $pdo, string $phone): array
{
    $candidates = warranty_sms_phone_candidates($phone);
    if ($candidates === []) {
        return [];
    }

    $items = [];
    $seen = [];
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));

    foreach (warranty_sms_tables($pdo) as $table) {
        $orderBy = warranty_sms_has_column($pdo, $table, 'sync_updated_ms')
            ? 'ORDER BY COALESCE(NULLIF(sync_updated_ms, 0), 0) DESC, time DESC, id DESC'
            : 'ORDER BY time DESC, id DESC';
        $sql = "SELECT * FROM `$table`
                WHERE phone IN ($placeholders) AND phone != '' AND phone != '0'
                $orderBy";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->execute($candidates);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row) || warranty_sms_registration_incomplete($row)) {
                continue;
            }
            $serialKey = strtoupper(trim((string) ($row['serial'] ?? '')));
            if ($serialKey === '' || isset($seen[$serialKey])) {
                continue;
            }
            $seen[$serialKey] = true;
            $row['_table'] = $table;
            $items[] = warranty_sms_serialize_row($row);
        }
    }

    usort($items, static function (array $a, array $b): int {
        $ta = $a['registered_at'] ?? '';
        $tb = $b['registered_at'] ?? '';
        return strcmp((string) $tb, (string) $ta);
    });

    return $items;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function warranty_sms_serialize_row(array $row): array
{
    $regAt = $row['time'] ?? $row['registered_at'] ?? null;
    $regAtStr = $regAt !== null && $regAt !== '' ? (string) $regAt : null;
    $kind = warranty_sms_row_kind($row);
    $km = warranty_sms_is_seller_serial((string) ($row['serial'] ?? ''), $row)
        ? ''
        : (string) ($row['km'] ?? '');

    return [
        'id' => (int) ($row['id'] ?? 0),
        'serial' => (string) ($row['serial'] ?? ''),
        'kind' => $kind,
        'status' => 'registered',
        'phone' => (string) ($row['phone'] ?? ''),
        'city' => (string) ($row['city'] ?? ''),
        'km' => $km,
        'car_plate' => (string) ($row['car_plate'] ?? ''),
        'registered_at' => $regAtStr,
        'registered_date' => function_exists('cms_jalali_format_from_timestamp') && $regAtStr !== null
            ? cms_to_persian_digits(cms_jalali_format_from_timestamp($regAtStr))
            : ($regAtStr ?? '—'),
    ];
}

/**
 * Find one registered row owned by phone.
 *
 * @return array<string, mixed>|null
 */
function warranty_sms_find_registered_by_phone(PDO $pdo, string $serial, string $phone): ?array
{
    $row = warranty_sms_find_row($pdo, $serial);
    if ($row === null || warranty_sms_registration_incomplete($row)) {
        return null;
    }
    $phoneNorm = warranty_sms_normalize_phone($phone);
    $rowNorm = warranty_sms_normalize_phone((string) ($row['phone'] ?? ''));
    if ($phoneNorm === '' || $rowNorm !== $phoneNorm) {
        return null;
    }
    return warranty_sms_serialize_row($row);
}
