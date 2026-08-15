<?php
declare(strict_types=1);

/**
 * Mechanic CRM (Customer Club) — schema + helpers.
 * Mirrors the branches.php pattern: one row per mechanic/workshop, linked
 * to the shared site_users table via user_id (multi-tenant scoping).
 */

require_once __DIR__ . '/mechanic-catalog.php';
require_once __DIR__ . '/seller-credit.php';
require_once __DIR__ . '/mechanic-broadcasts.php';

function mechanics_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanics (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id INT UNSIGNED NOT NULL,
          workshop_name VARCHAR(191) NOT NULL,
          owner_name VARCHAR(191) NOT NULL,
          city VARCHAR(191) NOT NULL,
          phone VARCHAR(20) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_mechanics_user (user_id),
          UNIQUE KEY uq_mechanics_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    try {
        $pdo->query('SELECT phone FROM mechanics WHERE 1=0');
        $pdo->exec('ALTER TABLE mechanics ADD UNIQUE KEY uq_mechanics_phone (phone)');
    } catch (Throwable $e) {
        // already present, duplicates exist, or no permission
    }

    try {
        $pdo->query('SELECT status FROM mechanics LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE mechanics ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
        } catch (Throwable $e2) {
            // already present or no permission
        }
    }
    try {
        $pdo->query('SELECT status_note FROM mechanics LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE mechanics ADD COLUMN status_note TEXT NULL');
        } catch (Throwable $e2) {
            // already present or no permission
        }
    }
    try {
        $pdo->query('SELECT status_changed_at FROM mechanics LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE mechanics ADD COLUMN status_changed_at DATETIME NULL');
        } catch (Throwable $e2) {
            // already present or no permission
        }
    }
    try {
        $pdo->exec('ALTER TABLE mechanics ADD KEY idx_mechanics_status (status)');
    } catch (Throwable $e) {
        // already present or no permission
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_active_services (
          mechanic_id INT UNSIGNED NOT NULL,
          service_key VARCHAR(64) NOT NULL,
          PRIMARY KEY (mechanic_id, service_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_customers (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          mechanic_id INT UNSIGNED NOT NULL,
          name VARCHAR(191) NOT NULL,
          phone VARCHAR(20) NULL,
          notes TEXT NULL,
          visit_count INT UNSIGNED NOT NULL DEFAULT 0,
          first_visit_at DATE NULL,
          last_visit_at DATE NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_mechanic_customers_mechanic (mechanic_id),
          KEY idx_mechanic_customers_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    try {
        $pdo->exec("UPDATE mechanic_customers SET phone = NULL WHERE phone = ''");
        $pdo->exec('ALTER TABLE mechanic_customers ADD UNIQUE KEY uq_mechanic_customers_phone (mechanic_id, phone)');
    } catch (Throwable $e) {
        // already present, duplicates exist, or no permission
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_vehicles (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          mechanic_id INT UNSIGNED NOT NULL,
          customer_id INT UNSIGNED NOT NULL,
          brand VARCHAR(120) NOT NULL,
          model VARCHAR(120) NOT NULL,
          trim VARCHAR(120) NULL,
          year VARCHAR(10) NULL,
          plate VARCHAR(40) NULL,
          vin VARCHAR(60) NULL,
          current_km INT UNSIGNED NULL,
          last_visit_at DATE NULL,
          notes TEXT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_mechanic_vehicles_mechanic (mechanic_id),
          KEY idx_mechanic_vehicles_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    try {
        $pdo->query('SELECT public_km_token FROM mechanic_vehicles LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE mechanic_vehicles ADD COLUMN public_km_token CHAR(32) NULL');
        } catch (Throwable $e2) {
            // already present or no permission
        }
    }

    try {
        $pdo->query('SELECT km_sms_sent_at FROM mechanic_vehicles LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE mechanic_vehicles ADD COLUMN km_sms_sent_at DATETIME NULL');
        } catch (Throwable $e2) {
            // already present or no permission
        }
    }

    mechanic_vehicles_backfill_km_tokens($pdo);

    try {
        $pdo->exec('ALTER TABLE mechanic_vehicles ADD UNIQUE KEY uq_mechanic_vehicle_km_token (public_km_token)');
    } catch (Throwable $e) {
        // already present or duplicates
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_km_readings (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          vehicle_id INT UNSIGNED NOT NULL,
          km INT UNSIGNED NOT NULL,
          source VARCHAR(16) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_mechanic_km_vehicle (vehicle_id),
          KEY idx_mechanic_km_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    mechanic_km_readings_backfill($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_service_records (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          mechanic_id INT UNSIGNED NOT NULL,
          vehicle_id INT UNSIGNED NOT NULL,
          customer_id INT UNSIGNED NOT NULL,
          service_key VARCHAR(64) NOT NULL,
          service_label VARCHAR(191) NOT NULL,
          performed_at DATE NOT NULL,
          km_at_service INT UNSIGNED NULL,
          labor_cost BIGINT UNSIGNED NULL,
          parts_cost BIGINT UNSIGNED NULL,
          notes TEXT NULL,
          next_due_at DATE NULL,
          next_due_km INT UNSIGNED NULL,
          sms_sent_at DATETIME NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_mechanic_service_mechanic (mechanic_id),
          KEY idx_mechanic_service_vehicle (vehicle_id),
          KEY idx_mechanic_service_customer (customer_id),
          KEY idx_mechanic_service_performed (performed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_service_parts (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          service_record_id INT UNSIGNED NOT NULL,
          part_name VARCHAR(191) NOT NULL,
          part_brand VARCHAR(120) NULL,
          quantity INT UNSIGNED NOT NULL DEFAULT 1,
          PRIMARY KEY (id),
          KEY idx_mechanic_parts_record (service_record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_invoices (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          mechanic_id INT UNSIGNED NOT NULL,
          customer_id INT UNSIGNED NOT NULL,
          vehicle_id INT UNSIGNED NOT NULL,
          public_token CHAR(32) NOT NULL,
          km_at_service INT UNSIGNED NULL,
          performed_at DATE NOT NULL,
          services_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
          parts_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
          total BIGINT UNSIGNED NOT NULL DEFAULT 0,
          pdf_downloaded_at DATETIME NULL,
          sms_sent_at DATETIME NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_mechanic_invoice_token (public_token),
          KEY idx_mechanic_invoice_mechanic (mechanic_id),
          KEY idx_mechanic_invoice_vehicle (vehicle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_invoice_lines (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          invoice_id INT UNSIGNED NOT NULL,
          kind VARCHAR(16) NOT NULL,
          label VARCHAR(191) NOT NULL,
          brand VARCHAR(120) NULL,
          quantity INT UNSIGNED NOT NULL DEFAULT 1,
          unit_price BIGINT UNSIGNED NOT NULL DEFAULT 0,
          line_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
          sort_order INT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          KEY idx_mechanic_invoice_lines (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    try {
        $pdo->query('SELECT sms_sent_at FROM mechanic_invoices LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE mechanic_invoices ADD COLUMN sms_sent_at DATETIME NULL');
        } catch (Throwable $e2) {
            // already present or no permission
        }
    }

    try {
        $pdo->query('SELECT sms_sent_at FROM mechanic_service_records LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE mechanic_service_records ADD COLUMN sms_sent_at DATETIME NULL');
        } catch (Throwable $e2) {
            // already present or no permission
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_sms_log (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          mechanic_id INT UNSIGNED NOT NULL,
          vehicle_id INT UNSIGNED NULL,
          customer_id INT UNSIGNED NULL,
          phone VARCHAR(20) NOT NULL,
          template_key VARCHAR(40) NOT NULL,
          body TEXT NOT NULL,
          status VARCHAR(20) NOT NULL,
          error TEXT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_mechanic_sms_mechanic (mechanic_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    seller_credit_ensure_schema($pdo);
    mechanic_broadcasts_ensure_schema($pdo);

    $ready = true;
}

function mechanics_select_sql(): string
{
    return 'id, user_id, workshop_name, owner_name, city, phone, status, status_note, status_changed_at, created_at, updated_at';
}

function mechanics_normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['pending', 'active', 'suspended', 'rejected'], true)
        ? $status
        : 'active';
}

/**
 * @param array<string, mixed> $row
 * @return array{
 *   id:int, user_id:int, workshop_name:string, owner_name:string, city:string, phone:string,
 *   status:string, status_note:?string, status_changed_at:?string, created_at:?string, updated_at:?string
 * }
 */
function mechanics_row_from_db(array $row): array
{
    $note = isset($row['status_note']) ? trim((string) $row['status_note']) : '';
    return [
        'id' => (int) $row['id'],
        'user_id' => (int) ($row['user_id'] ?? 0),
        'workshop_name' => (string) $row['workshop_name'],
        'owner_name' => (string) $row['owner_name'],
        'city' => (string) $row['city'],
        'phone' => (string) $row['phone'],
        'status' => mechanics_normalize_status((string) ($row['status'] ?? 'active')),
        'status_note' => $note !== '' ? $note : null,
        'status_changed_at' => isset($row['status_changed_at']) && $row['status_changed_at'] !== null && $row['status_changed_at'] !== ''
            ? (string) $row['status_changed_at']
            : null,
        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
    ];
}

/**
 * @return array<string, string>
 */
function mechanics_status_labels(): array
{
    return [
        'pending' => 'در انتظار',
        'active' => 'فعال',
        'suspended' => 'معلق',
        'rejected' => 'رد شده',
    ];
}

/**
 * @return string[] action keys: approve, reject, suspend, resume
 */
function mechanics_status_actions(string $status): array
{
    $status = mechanics_normalize_status($status);
    if ($status === 'pending') {
        return ['approve', 'reject'];
    }
    if ($status === 'rejected') {
        return ['approve'];
    }
    if ($status === 'active') {
        return ['suspend'];
    }
    if ($status === 'suspended') {
        return ['resume'];
    }
    return [];
}

function mechanics_status_from_action(string $action): ?string
{
    $map = [
        'approve' => 'active',
        'reject' => 'rejected',
        'suspend' => 'suspended',
        'resume' => 'active',
    ];
    return $map[$action] ?? null;
}

/**
 * @return array<string, string>
 */
function mechanics_action_labels(): array
{
    return [
        'approve' => 'تأیید',
        'reject' => 'رد',
        'suspend' => 'تعلیق',
        'resume' => 'ازسرگیری',
    ];
}

function mechanics_action_btn_class(string $action): string
{
    if ($action === 'approve' || $action === 'resume') {
        return 'cms-btn';
    }
    return 'cms-btn cms-btn--ghost';
}

function mechanics_status_block_message(string $status, ?string $note = null): string
{
    $status = mechanics_normalize_status($status);
    if ($status === 'pending') {
        return 'ثبت‌نام شما در انتظار تأیید است. پس از تأیید مدیریت می‌توانید وارد دفترچه شوید.';
    }
    if ($status === 'suspended') {
        return 'حساب تعمیرگاه موقتاً معلق شده است. برای پیگیری با استارتک تماس بگیرید.';
    }
    if ($status === 'rejected') {
        $note = $note !== null ? trim($note) : '';
        return $note !== ''
            ? 'ثبت‌نام تعمیرگاه تأیید نشد. ' . $note
            : 'ثبت‌نام تعمیرگاه تأیید نشد.';
    }
    return 'دسترسی به دفترچه مکانیک فعال نیست.';
}

/**
 * @return array{id:int, user_id:int, workshop_name:string, owner_name:string, city:string, phone:string, status:string, status_note:?string, status_changed_at:?string, created_at:?string, updated_at:?string}|null
 */
function mechanics_find_by_user(PDO $pdo, int $userId): ?array
{
    mechanics_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT ' . mechanics_select_sql() . ' FROM mechanics WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? mechanics_row_from_db($row) : null;
}

/**
 * @return array{id:int, user_id:int, workshop_name:string, owner_name:string, city:string, phone:string, status:string, status_note:?string, status_changed_at:?string, created_at:?string, updated_at:?string}|null
 */
function mechanics_find_by_phone(PDO $pdo, string $phone): ?array
{
    mechanics_ensure_schema($pdo);
    $candidates = seller_credit_phone_candidates($phone);
    $norm = seller_credit_normalize_phone($phone);
    if ($norm !== '' && !in_array($norm, $candidates, true)) {
        $candidates[] = $norm;
    }
    if ($phone !== '' && !in_array($phone, $candidates, true)) {
        $candidates[] = $phone;
    }
    if ($candidates === []) {
        return null;
    }
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $stmt = $pdo->prepare(
        'SELECT ' . mechanics_select_sql() . " FROM mechanics WHERE phone IN ($placeholders) LIMIT 1"
    );
    $stmt->execute(array_values($candidates));
    $row = $stmt->fetch();
    return $row ? mechanics_row_from_db($row) : null;
}

function mechanics_find_by_id(PDO $pdo, int $mechanicId): ?array
{
    mechanics_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT ' . mechanics_select_sql() . ' FROM mechanics WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$mechanicId]);
    $row = $stmt->fetch();
    return $row ? mechanics_row_from_db($row) : null;
}

/**
 * @param string[] $activeServices service catalog keys
 */
function mechanics_create(
    PDO $pdo,
    int $userId,
    string $phone,
    string $workshopName,
    string $ownerName,
    string $city,
    array $activeServices
): int {
    mechanics_ensure_schema($pdo);
    $phone = seller_credit_normalize_phone($phone);
    if ($phone === '') {
        throw new RuntimeException('شماره موبایل معتبر نیست');
    }
    $existing = mechanics_find_by_phone($pdo, $phone);
    if ($existing !== null) {
        throw new RuntimeException('این شماره موبایل قبلاً برای یک تعمیرگاه ثبت شده است');
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO mechanics (user_id, workshop_name, owner_name, city, phone, status, status_changed_at)
             VALUES (?, ?, ?, ?, ?, \'pending\', NOW())'
        );
        $ins->execute([$userId, $workshopName, $ownerName, $city, $phone]);
        $mechanicId = (int) $pdo->lastInsertId();

        mechanics_set_active_services($pdo, $mechanicId, $activeServices);

        $pdo->commit();
        return $mechanicId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate') !== false || strpos($msg, '1062') !== false) {
            throw new RuntimeException('این شماره موبایل قبلاً برای یک تعمیرگاه ثبت شده است');
        }
        throw $e;
    }
}

function mechanics_update_profile(PDO $pdo, int $mechanicId, string $workshopName, string $ownerName, string $city): void
{
    mechanics_ensure_schema($pdo);
    $pdo->prepare(
        'UPDATE mechanics SET workshop_name = ?, owner_name = ?, city = ? WHERE id = ?'
    )->execute([$workshopName, $ownerName, $city, $mechanicId]);
}

/**
 * @return string[]
 */
function mechanics_active_services(PDO $pdo, int $mechanicId): array
{
    mechanics_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT service_key FROM mechanic_active_services WHERE mechanic_id = ?');
    $stmt->execute([$mechanicId]);
    $keys = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $keys[] = (string) $row['service_key'];
    }
    return $keys;
}

/**
 * @param string[] $serviceKeys
 */
function mechanics_set_active_services(PDO $pdo, int $mechanicId, array $serviceKeys): void
{
    mechanics_ensure_schema($pdo);
    $catalog = mechanic_catalog_services();
    $clean = [];
    foreach ($serviceKeys as $key) {
        $key = trim((string) $key);
        if ($key !== '' && isset($catalog[$key]) && !in_array($key, $clean, true)) {
            $clean[] = $key;
        }
    }

    $pdo->prepare('DELETE FROM mechanic_active_services WHERE mechanic_id = ?')->execute([$mechanicId]);
    if ($clean === []) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO mechanic_active_services (mechanic_id, service_key) VALUES (?, ?)');
    foreach ($clean as $key) {
        $ins->execute([$mechanicId, $key]);
    }
}

function mechanic_customer_label(?string $name, ?string $phone): string
{
    $name = trim((string) $name);
    if ($name !== '') {
        return $name;
    }
    $phone = trim((string) $phone);
    if ($phone !== '') {
        return $phone;
    }
    return 'مشتری';
}

/** Append the workshop phone so every client SMS includes a contact number. */
function mechanic_sms_with_shop_phone(string $text, string $phone): string
{
    $text = rtrim(str_replace("\r\n", "\n", $text));
    $phone = trim($phone);
    if ($text === '' || $phone === '') {
        return $text;
    }
    if (strpos($text, $phone) !== false) {
        return $text;
    }
    return $text . "\nتماس: " . $phone;
}

/**
 * Test mode skips the 09:00–21:00 club SMS window (local XAMPP, CMS toggle, or config).
 */
function mechanic_sms_test_mode(): bool
{
    if (function_exists('cms_setting_get') && cms_setting_get('sms_test_mode', '0') === '1') {
        return true;
    }
    if (function_exists('cms_config')) {
        $flag = cms_config()['sms_test_mode'] ?? false;
        if ($flag === true || $flag === 1 || $flag === '1') {
            return true;
        }
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
}

/**
 * Customer-club outbound SMS is allowed 09:00–21:00 Asia/Tehran.
 * Test mode is exempt.
 *
 * @return array{ok:bool, error:?string, test_mode:bool}
 */
function mechanic_sms_send_window(): array
{
    if (mechanic_sms_test_mode()) {
        return [
            'ok' => true,
            'error' => null,
            'test_mode' => true,
        ];
    }
    $error = 'ارسال پیامک باشگاه مشتریان فقط از ساعت ۹ صبح تا ۹ شب امکان‌پذیر است.';
    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    } catch (Throwable $e) {
        $now = new DateTimeImmutable('now');
    }
    $minutes = ((int) $now->format('G')) * 60 + (int) $now->format('i');
    $ok = $minutes >= 9 * 60 && $minutes < 21 * 60;
    return [
        'ok' => $ok,
        'error' => $ok ? null : $error,
        'test_mode' => false,
    ];
}

function mechanic_sms_require_send_window(): void
{
    $window = mechanic_sms_send_window();
    if (!$window['ok']) {
        throw new RuntimeException((string) $window['error']);
    }
}

function mechanic_km_new_token(): string
{
    return bin2hex(random_bytes(16));
}

function mechanic_km_public_url(string $token): string
{
    if (!function_exists('mechanic_invoice_site_base')) {
        require_once __DIR__ . '/mechanic-invoices.php';
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = mechanic_invoice_site_base();
    return $scheme . '://' . $host . $base . '/k/' . $token;
}

function mechanic_vehicles_backfill_km_tokens(PDO $pdo): void
{
    try {
        $stmt = $pdo->query(
            "SELECT id FROM mechanic_vehicles WHERE public_km_token IS NULL OR public_km_token = ''"
        );
    } catch (Throwable $e) {
        return;
    }
    if (!$stmt) {
        return;
    }
    $upd = $pdo->prepare('UPDATE mechanic_vehicles SET public_km_token = ? WHERE id = ?');
    foreach ($stmt->fetchAll() ?: [] as $row) {
        for ($i = 0; $i < 6; $i++) {
            try {
                $upd->execute([mechanic_km_new_token(), (int) $row['id']]);
                break;
            } catch (Throwable $e) {
                // token collision
            }
        }
    }
}

function mechanic_km_readings_backfill(PDO $pdo): void
{
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM mechanic_km_readings')->fetchColumn();
    } catch (Throwable $e) {
        return;
    }
    if ($count > 0) {
        return;
    }
    try {
        $pdo->exec(
            "INSERT INTO mechanic_km_readings (vehicle_id, km, source, created_at)
             SELECT r.vehicle_id, r.km_at_service, 'service',
                    COALESCE(CONCAT(r.performed_at, ' 12:00:00'), r.created_at)
             FROM mechanic_service_records r
             WHERE r.km_at_service IS NOT NULL"
        );
        $pdo->exec(
            "INSERT INTO mechanic_km_readings (vehicle_id, km, source, created_at)
             SELECT v.id, v.current_km, 'mechanic', v.updated_at
             FROM mechanic_vehicles v
             WHERE v.current_km IS NOT NULL
               AND NOT EXISTS (
                 SELECT 1 FROM mechanic_km_readings k WHERE k.vehicle_id = v.id
               )"
        );
    } catch (Throwable $e) {
        // service table may not exist yet on first install
    }
}

function mechanic_vehicle_ensure_km_token(PDO $pdo, array &$row): string
{
    $token = trim((string) ($row['public_km_token'] ?? ''));
    if (preg_match('/^[a-fA-F0-9]{32}$/', $token)) {
        return strtolower($token);
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        return '';
    }
    for ($i = 0; $i < 6; $i++) {
        $token = mechanic_km_new_token();
        try {
            $pdo->prepare('UPDATE mechanic_vehicles SET public_km_token = ? WHERE id = ?')->execute([$token, $id]);
            $row['public_km_token'] = $token;
            return $token;
        } catch (Throwable $e) {
            // collision
        }
    }
    return '';
}

function mechanic_vehicle_find_by_km_token(PDO $pdo, string $token): ?array
{
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT v.*, m.workshop_name, m.city, m.phone AS workshop_phone,
                c.name AS customer_name, c.phone AS customer_phone
         FROM mechanic_vehicles v
         INNER JOIN mechanics m ON m.id = v.mechanic_id
         INNER JOIN mechanic_customers c ON c.id = v.customer_id
         WHERE v.public_km_token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mechanic_vehicle_km_payload(PDO $pdo, array &$row): array
{
    $token = mechanic_vehicle_ensure_km_token($pdo, $row);
    $sentAt = $row['km_sms_sent_at'] ?? null;
    $sentStr = $sentAt !== null && $sentAt !== '' ? (string) $sentAt : null;
    $stats = mechanic_vehicle_mileage_stats($pdo, (int) $row['id']);
    $update = mechanic_vehicle_last_km_update($pdo, (int) $row['id']);
    return [
        'public_km_url' => $token !== '' ? mechanic_km_public_url($token) : '',
        'km_sms_sent_at' => $sentStr,
        'km_sms_cooldown' => mechanic_km_sms_cooldown_active($sentStr),
        'avg_km_per_month' => $stats['ready'] ? $stats['avg_km_per_month'] : null,
        'mileage_ready' => $stats['ready'],
        'last_km' => mechanic_vehicle_last_km($pdo, (int) $row['id'], $row),
        'km_updated_at' => $update['at'],
        'km_updated_source' => $update['source'],
    ];
}

function mechanic_customer_assert_phone_free(PDO $pdo, int $mechanicId, string $phone, int $exceptId = 0): void
{
    $norm = seller_credit_normalize_phone($phone);
    if ($norm === '' || !preg_match('/^09\d{9}$/', $norm)) {
        return;
    }
    $candidates = seller_credit_phone_candidates($norm);
    if ($candidates === []) {
        $candidates = [$norm];
    }
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $sql = "SELECT id FROM mechanic_customers
            WHERE mechanic_id = ? AND phone IN ($placeholders)";
    $params = array_merge([$mechanicId], array_values($candidates));
    if ($exceptId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        throw new RuntimeException('این شماره قبلاً برای مشتری دیگری ثبت شده است.');
    }
}

function mechanic_km_too_low_error(int $lastKm): string
{
    if (!function_exists('cms_to_persian_digits')) {
        require_once __DIR__ . '/jalali.php';
    }
    $n = cms_to_persian_digits((string) $lastKm);
    return "کیلومتر نمی‌تواند کمتر از آخرین مقدار ثبت‌شده باشد ({$n}).";
}

function mechanic_vehicle_last_km(PDO $pdo, int $vehicleId, ?array $vehicleRow = null): ?int
{
    $last = null;
    if ($vehicleRow !== null && array_key_exists('current_km', $vehicleRow) && $vehicleRow['current_km'] !== null) {
        $last = (int) $vehicleRow['current_km'];
    } else {
        $stmt = $pdo->prepare('SELECT current_km FROM mechanic_vehicles WHERE id = ? LIMIT 1');
        $stmt->execute([$vehicleId]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            $last = (int) $val;
        }
    }
    try {
        $svc = $pdo->prepare(
            'SELECT MAX(km_at_service) FROM mechanic_service_records WHERE vehicle_id = ?'
        );
        $svc->execute([$vehicleId]);
        $svcKm = $svc->fetchColumn();
        if ($svcKm !== false && $svcKm !== null) {
            $last = $last === null ? (int) $svcKm : max($last, (int) $svcKm);
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $rd = $pdo->prepare('SELECT MAX(km) FROM mechanic_km_readings WHERE vehicle_id = ?');
        $rd->execute([$vehicleId]);
        $rdKm = $rd->fetchColumn();
        if ($rdKm !== false && $rdKm !== null) {
            $last = $last === null ? (int) $rdKm : max($last, (int) $rdKm);
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $last;
}

/**
 * Latest odometer update. Prefers a client (owner) reading when present.
 *
 * @return array{at:?string, source:?string}
 */
function mechanic_vehicle_last_km_update(PDO $pdo, int $vehicleId): array
{
    $empty = ['at' => null, 'source' => null];
    try {
        $stmt = $pdo->prepare(
            'SELECT km, source, created_at FROM mechanic_km_readings
             WHERE vehicle_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$vehicleId]);
        $row = $stmt->fetch();
        if (!$row) {
            return $empty;
        }
        return [
            'at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'source' => (string) ($row['source'] ?? ''),
        ];
    } catch (Throwable $e) {
        return $empty;
    }
}

function mechanic_km_assert_not_lower(PDO $pdo, int $vehicleId, int $km, ?array $vehicleRow = null): void
{
    $last = mechanic_vehicle_last_km($pdo, $vehicleId, $vehicleRow);
    if ($last !== null && $km < $last) {
        throw new RuntimeException(mechanic_km_too_low_error($last));
    }
}

function mechanic_km_record_reading(PDO $pdo, int $vehicleId, int $km, string $source, ?string $createdAt = null): void
{
    $source = in_array($source, ['owner', 'service', 'mechanic'], true) ? $source : 'mechanic';
    if ($createdAt !== null && $createdAt !== '') {
        $pdo->prepare(
            'INSERT INTO mechanic_km_readings (vehicle_id, km, source, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$vehicleId, $km, $source, $createdAt]);
    } else {
        $pdo->prepare(
            'INSERT INTO mechanic_km_readings (vehicle_id, km, source, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$vehicleId, $km, $source]);
    }
}

function mechanic_km_sms_cooldown_active(?string $sentAt): bool
{
    if ($sentAt === null || trim($sentAt) === '') {
        return false;
    }
    try {
        $sent = new DateTimeImmutable($sentAt);
        $until = $sent->modify('+30 days');
        return $until > new DateTimeImmutable('now');
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Average monthly mileage after 3+ readings spanning at least 90 days.
 *
 * @return array{
 *   ready:bool,
 *   avg_km_per_month:?float,
 *   avg_km_per_day:?float,
 *   sample_count:int,
 *   span_days:?int
 * }
 */
function mechanic_vehicle_mileage_stats(PDO $pdo, int $vehicleId): array
{
    $empty = [
        'ready' => false,
        'avg_km_per_month' => null,
        'avg_km_per_day' => null,
        'sample_count' => 0,
        'span_days' => null,
    ];
    try {
        $stmt = $pdo->prepare(
            'SELECT km, created_at FROM mechanic_km_readings
             WHERE vehicle_id = ? AND source IN (\'owner\', \'service\', \'mechanic\')
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$vehicleId]);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return $empty;
    }
    $empty['sample_count'] = count($rows);
    if (count($rows) < 3) {
        return $empty;
    }
    $firstKm = (int) $rows[0]['km'];
    $lastKm = (int) $rows[count($rows) - 1]['km'];
    try {
        $firstAt = new DateTimeImmutable((string) $rows[0]['created_at']);
        $lastAt = new DateTimeImmutable((string) $rows[count($rows) - 1]['created_at']);
    } catch (Throwable $e) {
        return $empty;
    }
    $spanDays = (int) $firstAt->diff($lastAt)->format('%a');
    $empty['span_days'] = $spanDays;
    if ($spanDays < 90 || $lastKm < $firstKm) {
        return $empty;
    }
    $avgPerDay = ($lastKm - $firstKm) / $spanDays;
    $avgPerMonth = $avgPerDay * 30.44;
    if ($avgPerMonth > 15000 || $avgPerDay <= 0) {
        return $empty;
    }
    return [
        'ready' => true,
        'avg_km_per_month' => round($avgPerMonth, 1),
        'avg_km_per_day' => $avgPerDay,
        'sample_count' => count($rows),
        'span_days' => $spanDays,
    ];
}

function mechanic_public_km_number_label(?int $n): string
{
    if ($n === null) {
        return '—';
    }
    if (!function_exists('cms_to_persian_digits')) {
        require_once __DIR__ . '/jalali.php';
    }
    return cms_to_persian_digits(number_format($n, 0, '.', '٬'));
}

/**
 * Latest service of each type for the public KM page.
 *
 * @return list<array<string, mixed>>
 */
function mechanic_public_km_services(PDO $pdo, int $vehicleId, ?int $currentKm): array
{
    if ($vehicleId <= 0) {
        return [];
    }
    if (!function_exists('cms_to_persian_digits')) {
        require_once __DIR__ . '/jalali.php';
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT r.service_key, r.service_label, r.km_at_service, r.next_due_at, r.next_due_km, r.performed_at
             FROM mechanic_service_records r
             WHERE r.vehicle_id = ?
               AND r.id IN (
                 SELECT MAX(id) FROM mechanic_service_records
                 WHERE vehicle_id = ? GROUP BY service_key
               )
             ORDER BY r.performed_at DESC, r.id DESC'
        );
        $stmt->execute([$vehicleId, $vehicleId]);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $stats = mechanic_vehicle_mileage_stats($pdo, $vehicleId);
    $avgDay = $stats['ready'] ? $stats['avg_km_per_day'] : null;

    $items = [];
    foreach ($rows as $row) {
        $kmAt = $row['km_at_service'] !== null ? (int) $row['km_at_service'] : null;
        $nextDueKm = $row['next_due_km'] !== null ? (int) $row['next_due_km'] : null;
        $nextDueAt = $row['next_due_at'] !== null && (string) $row['next_due_at'] !== ''
            ? (string) $row['next_due_at']
            : null;

        if ($kmAt === null && $nextDueKm === null && $nextDueAt === null) {
            continue;
        }

        $kmDriven = null;
        if ($kmAt !== null && $currentKm !== null) {
            $kmDriven = max(0, $currentKm - $kmAt);
        }

        $result = mechanic_reminder_status($nextDueKm, $currentKm, $nextDueAt, $avgDay);
        $kmRemaining = $result['km_remaining'];
        $kmRemainingLabel = '—';
        if ($nextDueKm !== null && $currentKm !== null) {
            if ($kmRemaining !== null && $kmRemaining <= 0) {
                $kmRemainingLabel = 'گذشته';
            } elseif ($kmRemaining !== null) {
                $kmRemainingLabel = mechanic_public_km_number_label($kmRemaining);
            }
        }

        $predictedLabel = '—';
        if (!empty($result['predicted_due_at'])) {
            $predictedLabel = cms_to_persian_digits(
                cms_jalali_format_date((string) $result['predicted_due_at'])
            );
        }

        $status = (string) ($result['status'] ?? 'none');
        if ($status === 'none') {
            $status = 'green';
        }

        $items[] = [
            'service_key' => (string) ($row['service_key'] ?? ''),
            'service_label' => (string) ($row['service_label'] ?? ''),
            'km_at_service' => $kmAt,
            'km_at_service_label' => $kmAt !== null ? mechanic_public_km_number_label($kmAt) : '—',
            'km_driven' => $kmDriven,
            'km_driven_label' => $kmDriven !== null ? mechanic_public_km_number_label($kmDriven) : '—',
            'km_remaining' => $kmRemaining,
            'km_remaining_label' => $kmRemainingLabel,
            'predicted_due_at_label' => $predictedLabel,
            'status' => $status,
        ];
    }

    return $items;
}

function mechanic_km_cron_secret(): string
{
    $config = function_exists('cms_config') ? cms_config() : [];
    $fromFile = trim((string) ($config['km_cron_key'] ?? ''));
    if ($fromFile !== '') {
        return $fromFile;
    }
    $stored = cms_setting_get('mechanic_km_cron_key');
    if ($stored !== '') {
        return $stored;
    }
    $generated = bin2hex(random_bytes(16));
    cms_setting_set('mechanic_km_cron_key', $generated);
    return $generated;
}

/**
 * Ready-made client SMS templates.
 *
 * @param array<string, mixed> $vars
 */
function mechanic_sms_template(string $key, array $vars): string
{
    $owner = $vars['owner'] ?? 'مشتری گرامی';
    $workshop = $vars['workshop'] ?? '';
    $city = $vars['city'] ?? '';
    $vehicle = $vars['vehicle'] ?? 'خودروی شما';
    $service = $vars['service'] ?? '';
    $url = $vars['url'] ?? '';
    $phone = trim((string) ($vars['phone'] ?? ''));
    $lastKm = trim((string) ($vars['last_km'] ?? ''));

    switch ($key) {
        case 'thanks':
            $text = "{$owner}،\nاز اعتماد شما به {$workshop} در {$city} سپاسگزاریم.\n{$vehicle} با موفقیت سرویس شد.";
            break;
        case 'recall':
            $text = "{$owner}،\nزمان سرویس دوره‌ای {$vehicle} ({$service}) نزدیک شده است.\nجهت بررسی و سرویس خودرو با {$workshop} تماس بگیرید.";
            break;
        case 'invoice':
            $link = $url !== '' ? $url : '';
            $text = "{$owner}،\nفاکتور سرویس {$vehicle} در {$workshop} آماده است.\nبرای دانلود روی لینک بزنید:\n{$link}";
            break;
        case 'km_update':
            $lastLine = $lastKm !== '' ? "آخرین کیلومتر ثبت‌شده: {$lastKm}\nمقدار جدید نمی‌تواند کمتر باشد.\n" : '';
            $text = "{$owner}،\nلطفاً کیلومتر فعلی {$vehicle} را به‌روز کنید.\n{$lastLine}{$url}";
            break;
        default:
            $text = '';
    }

    return mechanic_sms_with_shop_phone($text, $phone);
}

/**
 * @return array<string, string>
 */
function mechanics_sms_template_labels(): array
{
    return [
        'thanks' => 'تشکر',
        'recall' => 'فراخوان سرویس',
        'invoice' => 'فاکتور',
        'km_update' => 'به‌روزرسانی کیلومتر',
        'broadcast' => 'پیام گروهی',
        'custom' => 'سفارشی',
    ];
}

function mechanics_sms_template_label(string $key): string
{
    $labels = mechanics_sms_template_labels();
    return $labels[$key] ?? $key;
}

/**
 * @return array<string, string>
 */
function mechanics_sms_status_labels(): array
{
    return [
        'sent' => 'ارسال شد',
        'failed' => 'ناموفق',
        'skipped' => 'ارسال نشد',
        'pending' => 'در انتظار',
    ];
}

function mechanics_sms_status_label(string $status): string
{
    $labels = mechanics_sms_status_labels();
    return $labels[$status] ?? $status;
}

/**
 * Active reminders for a mechanic: the latest service record per
 * (vehicle, service_key) that still has a next_due_at/next_due_km set,
 * with computed green/yellow/red status (spec section 9).
 *
 * @return list<array<string, mixed>>
 */
function mechanic_active_reminders(PDO $pdo, int $mechanicId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.id, r.vehicle_id, r.customer_id, r.service_key, r.service_label,
                r.performed_at, r.next_due_at, r.next_due_km, r.sms_sent_at,
                v.brand, v.model, v.plate, v.current_km,
                c.name AS customer_name, c.phone AS customer_phone
         FROM mechanic_service_records r
         INNER JOIN mechanic_vehicles v ON v.id = r.vehicle_id
         INNER JOIN mechanic_customers c ON c.id = r.customer_id
         WHERE r.mechanic_id = ?
           AND r.id IN (
             SELECT MAX(id) FROM mechanic_service_records
             WHERE mechanic_id = ? GROUP BY vehicle_id, service_key
           )'
    );
    $stmt->execute([$mechanicId, $mechanicId]);

    $items = [];
    $statsByVehicle = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $currentKm = $row['current_km'] !== null ? (int) $row['current_km'] : null;
        $nextDueKm = $row['next_due_km'] !== null ? (int) $row['next_due_km'] : null;
        $vehicleId = (int) $row['vehicle_id'];
        if (!isset($statsByVehicle[$vehicleId])) {
            $statsByVehicle[$vehicleId] = mechanic_vehicle_mileage_stats($pdo, $vehicleId);
        }
        $stats = $statsByVehicle[$vehicleId];
        $avgDay = $stats['ready'] ? $stats['avg_km_per_day'] : null;
        $result = mechanic_reminder_status($nextDueKm, $currentKm, $row['next_due_at'], $avgDay);
        if ($result['status'] === 'none') {
            $result['status'] = 'green';
        }
        $items[] = [
            'record_id' => (int) $row['id'],
            'vehicle_id' => $vehicleId,
            'customer_id' => (int) $row['customer_id'],
            'customer_name' => mechanic_customer_label(
                $row['customer_name'] !== null ? (string) $row['customer_name'] : '',
                $row['customer_phone'] !== null ? (string) $row['customer_phone'] : ''
            ),
            'customer_phone' => $row['customer_phone'] !== null ? (string) $row['customer_phone'] : '',
            'vehicle_label' => trim((string) $row['brand'] . ' ' . (string) $row['model']),
            'plate' => $row['plate'] !== null ? (string) $row['plate'] : '',
            'service_key' => (string) $row['service_key'],
            'service_label' => (string) $row['service_label'],
            'next_due_at' => $row['next_due_at'],
            'next_due_km' => $nextDueKm,
            'sms_sent_at' => $row['sms_sent_at'] ?? null,
            'status' => $result['status'],
            'km_remaining' => $result['km_remaining'],
            'days_remaining' => $result['days_remaining'],
            'predicted_due_at' => $result['predicted_due_at'],
            'avg_km_per_month' => $stats['ready'] ? $stats['avg_km_per_month'] : null,
        ];
    }

    $rank = ['red' => 0, 'yellow' => 1, 'green' => 2];
    usort($items, function ($a, $b) use ($rank) {
        $rankDiff = ($rank[$a['status']] ?? 3) - ($rank[$b['status']] ?? 3);
        if ($rankDiff !== 0) {
            return $rankDiff;
        }
        $aDays = $a['days_remaining'] ?? PHP_INT_MAX;
        $bDays = $b['days_remaining'] ?? PHP_INT_MAX;
        return $aDays <=> $bDays;
    });

    return $items;
}

/**
 * Public auth-user payload fragment, merged alongside branches_auth_user_payload().
 *
 * @return array{is_mechanic: bool, mechanic: array{id:int,workshop_name:string,owner_name:string,city:string,phone:string,status:string,status_note:?string,credit:array}|null}
 */
function mechanics_auth_user_payload(PDO $pdo, int $userId): array
{
    $mechanic = mechanics_find_by_user($pdo, $userId);
    if ($mechanic === null) {
        return [
            'is_mechanic' => false,
            'mechanic' => null,
        ];
    }

    $status = mechanics_normalize_status((string) ($mechanic['status'] ?? 'active'));
    $credit = seller_credit_public_payload($pdo, $mechanic['id'], $mechanic['phone']);

    return [
        'is_mechanic' => $status === 'active',
        'mechanic' => [
            'id' => $mechanic['id'],
            'workshop_name' => $mechanic['workshop_name'],
            'owner_name' => $mechanic['owner_name'],
            'city' => $mechanic['city'],
            'phone' => $mechanic['phone'],
            'status' => $status,
            'status_note' => $mechanic['status_note'],
            'credit' => $credit,
        ],
    ];
}

function mechanics_set_status(PDO $pdo, int $mechanicId, string $status, string $note = ''): void
{
    mechanics_ensure_schema($pdo);
    $to = mechanics_normalize_status($status);
    $current = mechanics_find_by_id($pdo, $mechanicId);
    if ($current === null) {
        throw new RuntimeException('تعمیرگاه یافت نشد');
    }
    $from = mechanics_normalize_status((string) ($current['status'] ?? 'active'));
    if ($from === $to) {
        return;
    }
    $allowed = [
        'pending' => ['active', 'rejected'],
        'rejected' => ['active'],
        'active' => ['suspended'],
        'suspended' => ['active'],
    ];
    if (!in_array($to, $allowed[$from] ?? [], true)) {
        throw new RuntimeException('این تغییر وضعیت مجاز نیست');
    }
    $note = trim($note);
    $pdo->prepare(
        'UPDATE mechanics SET status = ?, status_note = ?, status_changed_at = NOW() WHERE id = ?'
    )->execute([$to, $note !== '' ? $note : null, $mechanicId]);
}

function mechanics_apply_status_action(PDO $pdo, int $mechanicId, string $action, string $note = ''): string
{
    $to = mechanics_status_from_action($action);
    if ($to === null) {
        throw new RuntimeException('عملیات نامعتبر است');
    }
    if ($action === 'reject' && trim($note) === '') {
        throw new RuntimeException('برای رد ثبت‌نام، دلیل را بنویسید');
    }
    mechanics_set_status($pdo, $mechanicId, $to, $note);
    $labels = [
        'approve' => 'تعمیرگاه تأیید شد',
        'reject' => 'ثبت‌نام رد شد',
        'suspend' => 'حساب معلق شد',
        'resume' => 'حساب ازسرگیری شد',
    ];
    return $labels[$action] ?? 'وضعیت به‌روز شد';
}

/**
 * @return array<string, string>
 */
function mechanics_heat_labels(): array
{
    return [
        'hot' => 'داغ',
        'warm' => 'گرم',
        'cold' => 'سرد',
        'dormant' => 'راکد',
    ];
}

function mechanics_heat_from_last_service(?string $lastServiceAt): string
{
    if ($lastServiceAt === null || trim($lastServiceAt) === '') {
        return 'dormant';
    }
    $ts = strtotime($lastServiceAt);
    if ($ts === false) {
        return 'dormant';
    }
    $days = (int) floor((time() - $ts) / 86400);
    if ($days <= 7) {
        return 'hot';
    }
    if ($days <= 30) {
        return 'warm';
    }
    if ($days <= 90) {
        return 'cold';
    }
    return 'dormant';
}

function mechanics_activity_score(int $services30d, int $customerCount, ?string $lastServiceAt): int
{
    $days = 9999;
    if ($lastServiceAt !== null && trim($lastServiceAt) !== '') {
        $ts = strtotime($lastServiceAt);
        if ($ts !== false) {
            $days = (int) floor((time() - $ts) / 86400);
        }
    }
    $recency = 0;
    if ($days <= 7) {
        $recency = 40;
    } elseif ($days <= 30) {
        $recency = 25;
    } elseif ($days <= 90) {
        $recency = 10;
    }
    $volume = (int) min(35, (int) round(35 * min(20, max(0, $services30d)) / 20));
    $book = (int) min(25, (int) round(25 * min(40, max(0, $customerCount)) / 40));
    return $recency + $volume + $book;
}

/**
 * @return array{total:int, pending:int, active_week:int, dormant_30:int}
 */
function mechanics_admin_kpis(PDO $pdo): array
{
    mechanics_ensure_schema($pdo);
    $total = (int) $pdo->query('SELECT COUNT(*) FROM mechanics')->fetchColumn();
    $pending = (int) $pdo->query(
        "SELECT COUNT(*) FROM mechanics WHERE status = 'pending'"
    )->fetchColumn();
    $activeWeek = (int) $pdo->query(
        "SELECT COUNT(DISTINCT mechanic_id) FROM mechanic_service_records
         WHERE performed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
    )->fetchColumn();
    $dormant30 = (int) $pdo->query(
        "SELECT COUNT(*) FROM mechanics m
         WHERE m.status = 'active'
           AND NOT EXISTS (
             SELECT 1 FROM mechanic_service_records r
             WHERE r.mechanic_id = m.id
               AND r.performed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           )"
    )->fetchColumn();
    return [
        'total' => $total,
        'pending' => $pending,
        'active_week' => $activeWeek,
        'dormant_30' => $dormant30,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function mechanics_admin_list(PDO $pdo, string $filter = 'all', string $q = ''): array
{
    mechanics_ensure_schema($pdo);
    $sql = 'SELECT m.*,
        (SELECT COUNT(*) FROM mechanic_customers c WHERE c.mechanic_id = m.id) AS customer_count,
        (SELECT COUNT(*) FROM mechanic_vehicles v WHERE v.mechanic_id = m.id) AS vehicle_count,
        (SELECT COUNT(*) FROM mechanic_service_records r WHERE r.mechanic_id = m.id) AS service_count,
        (SELECT COUNT(*) FROM mechanic_service_records r
          WHERE r.mechanic_id = m.id AND r.performed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS services_7d,
        (SELECT COUNT(*) FROM mechanic_service_records r
          WHERE r.mechanic_id = m.id AND r.performed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS services_30d,
        (SELECT MAX(r.performed_at) FROM mechanic_service_records r WHERE r.mechanic_id = m.id) AS last_service_at
      FROM mechanics m';
    $params = [];
    $where = [];
    if (in_array($filter, ['pending', 'active', 'suspended', 'rejected'], true)) {
        $where[] = 'm.status = ?';
        $params[] = $filter;
    }
    $q = trim($q);
    if ($q !== '') {
        $where[] = '(m.workshop_name LIKE ? OR m.owner_name LIKE ? OR m.city LIKE ? OR m.phone LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY FIELD(m.status, 'pending', 'active', 'suspended', 'rejected'), m.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $item = mechanics_row_from_db($row);
        $last = isset($row['last_service_at']) && $row['last_service_at'] !== null && $row['last_service_at'] !== ''
            ? (string) $row['last_service_at']
            : null;
        $item['customer_count'] = (int) $row['customer_count'];
        $item['vehicle_count'] = (int) $row['vehicle_count'];
        $item['service_count'] = (int) $row['service_count'];
        $item['services_7d'] = (int) $row['services_7d'];
        $item['services_30d'] = (int) $row['services_30d'];
        $item['last_service_at'] = $last;
        $item['heat'] = mechanics_heat_from_last_service($last);
        $item['activity_score'] = mechanics_activity_score(
            (int) $row['services_30d'],
            (int) $row['customer_count'],
            $last
        );
        if ($filter === 'dormant' && ($item['heat'] !== 'dormant' || $item['status'] !== 'active')) {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * @return array{
 *   mechanic: array<string, mixed>,
 *   credit: array<string, int>,
 *   activity: array{heat:string, score:int, services_7d:int, services_30d:int, last_service_at:?string, customer_count:int, vehicle_count:int, service_count:int},
 *   customers: list<array<string, mixed>>,
 *   vehicles: list<array<string, mixed>>,
 *   services: list<array<string, mixed>>,
 *   invoices: list<array<string, mixed>>,
 *   sms: list<array<string, mixed>>,
 *   sms_sent_count:int,
 *   sms_total_count:int,
 *   sms_page:int,
 *   sms_pages:int
 * }|null
 */
function mechanics_admin_detail(PDO $pdo, int $mechanicId, int $smsPage = 1): ?array
{
    mechanics_ensure_schema($pdo);
    $mechanic = mechanics_find_by_id($pdo, $mechanicId);
    if ($mechanic === null) {
        return null;
    }

    $smsPageSize = 20;
    $smsPage = max(1, $smsPage);

    $counts = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM mechanic_customers WHERE mechanic_id = ?) AS customer_count,
            (SELECT COUNT(*) FROM mechanic_vehicles WHERE mechanic_id = ?) AS vehicle_count,
            (SELECT COUNT(*) FROM mechanic_service_records WHERE mechanic_id = ?) AS service_count,
            (SELECT COUNT(*) FROM mechanic_service_records
              WHERE mechanic_id = ? AND performed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS services_7d,
            (SELECT COUNT(*) FROM mechanic_service_records
              WHERE mechanic_id = ? AND performed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS services_30d,
            (SELECT MAX(performed_at) FROM mechanic_service_records WHERE mechanic_id = ?) AS last_service_at,
            (SELECT COUNT(*) FROM mechanic_sms_log WHERE mechanic_id = ?) AS sms_total_count,
            (SELECT COUNT(*) FROM mechanic_sms_log WHERE mechanic_id = ? AND status = \'sent\') AS sms_sent_count'
    );
    $counts->execute([
        $mechanicId,
        $mechanicId,
        $mechanicId,
        $mechanicId,
        $mechanicId,
        $mechanicId,
        $mechanicId,
        $mechanicId,
    ]);
    $c = $counts->fetch() ?: [];
    $last = isset($c['last_service_at']) && $c['last_service_at'] !== null && $c['last_service_at'] !== ''
        ? (string) $c['last_service_at']
        : null;
    $customerCount = (int) ($c['customer_count'] ?? 0);
    $services30d = (int) ($c['services_30d'] ?? 0);
    $smsTotalCount = (int) ($c['sms_total_count'] ?? 0);
    $smsSentCount = (int) ($c['sms_sent_count'] ?? 0);
    $smsPages = max(1, (int) ceil($smsTotalCount / $smsPageSize));
    if ($smsPage > $smsPages) {
        $smsPage = $smsPages;
    }
    $smsOffset = ($smsPage - 1) * $smsPageSize;

    $custStmt = $pdo->prepare(
        'SELECT id, name, phone, visit_count, last_visit_at, first_visit_at, notes
         FROM mechanic_customers WHERE mechanic_id = ?
         ORDER BY last_visit_at DESC, name ASC'
    );
    $custStmt->execute([$mechanicId]);
    $customers = [];
    foreach ($custStmt->fetchAll() as $row) {
        $customers[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
            'visit_count' => (int) $row['visit_count'],
            'last_visit_at' => $row['last_visit_at'] !== null ? (string) $row['last_visit_at'] : null,
            'first_visit_at' => $row['first_visit_at'] !== null ? (string) $row['first_visit_at'] : null,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
        ];
    }

    $vehStmt = $pdo->prepare(
        'SELECT v.id, v.customer_id, v.brand, v.model, v.trim, v.year, v.plate, v.vin,
                v.current_km, v.last_visit_at, c.name AS customer_name
         FROM mechanic_vehicles v
         LEFT JOIN mechanic_customers c ON c.id = v.customer_id
         WHERE v.mechanic_id = ?
         ORDER BY v.last_visit_at DESC, v.id DESC'
    );
    $vehStmt->execute([$mechanicId]);
    $vehicles = [];
    foreach ($vehStmt->fetchAll() as $row) {
        $vehicles[] = [
            'id' => (int) $row['id'],
            'customer_id' => (int) $row['customer_id'],
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'brand' => (string) $row['brand'],
            'model' => (string) $row['model'],
            'trim' => $row['trim'] !== null ? (string) $row['trim'] : null,
            'year' => $row['year'] !== null ? (string) $row['year'] : null,
            'plate' => $row['plate'] !== null ? (string) $row['plate'] : null,
            'vin' => $row['vin'] !== null ? (string) $row['vin'] : null,
            'current_km' => $row['current_km'] !== null ? (int) $row['current_km'] : null,
            'last_visit_at' => $row['last_visit_at'] !== null ? (string) $row['last_visit_at'] : null,
        ];
    }

    $svcStmt = $pdo->prepare(
        'SELECT r.id, r.service_key, r.service_label, r.performed_at, r.km_at_service,
                r.labor_cost, r.parts_cost, r.next_due_at, r.next_due_km, r.notes, r.sms_sent_at,
                c.name AS customer_name, v.brand, v.model, v.plate
         FROM mechanic_service_records r
         LEFT JOIN mechanic_customers c ON c.id = r.customer_id
         LEFT JOIN mechanic_vehicles v ON v.id = r.vehicle_id
         WHERE r.mechanic_id = ?
         ORDER BY r.performed_at DESC, r.id DESC
         LIMIT 200'
    );
    $svcStmt->execute([$mechanicId]);
    $services = [];
    foreach ($svcStmt->fetchAll() as $row) {
        $services[] = [
            'id' => (int) $row['id'],
            'service_key' => (string) $row['service_key'],
            'service_label' => (string) $row['service_label'],
            'performed_at' => (string) $row['performed_at'],
            'km_at_service' => $row['km_at_service'] !== null ? (int) $row['km_at_service'] : null,
            'labor_cost' => $row['labor_cost'] !== null ? (int) $row['labor_cost'] : null,
            'parts_cost' => $row['parts_cost'] !== null ? (int) $row['parts_cost'] : null,
            'next_due_at' => $row['next_due_at'] !== null ? (string) $row['next_due_at'] : null,
            'next_due_km' => $row['next_due_km'] !== null ? (int) $row['next_due_km'] : null,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'sms_sent_at' => $row['sms_sent_at'] !== null ? (string) $row['sms_sent_at'] : null,
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'vehicle_label' => trim((string) ($row['brand'] ?? '') . ' ' . (string) ($row['model'] ?? '')),
            'plate' => $row['plate'] !== null ? (string) $row['plate'] : null,
        ];
    }

    $invStmt = $pdo->prepare(
        'SELECT i.id, i.total, i.services_total, i.parts_total, i.performed_at, i.created_at,
                i.sms_sent_at, i.public_token, c.name AS customer_name, v.brand, v.model, v.plate
         FROM mechanic_invoices i
         LEFT JOIN mechanic_customers c ON c.id = i.customer_id
         LEFT JOIN mechanic_vehicles v ON v.id = i.vehicle_id
         WHERE i.mechanic_id = ?
         ORDER BY i.created_at DESC
         LIMIT 100'
    );
    $invStmt->execute([$mechanicId]);
    $invoices = [];
    foreach ($invStmt->fetchAll() as $row) {
        $invoices[] = [
            'id' => (int) $row['id'],
            'total' => (int) $row['total'],
            'services_total' => (int) $row['services_total'],
            'parts_total' => (int) $row['parts_total'],
            'performed_at' => (string) $row['performed_at'],
            'created_at' => (string) $row['created_at'],
            'sms_sent_at' => $row['sms_sent_at'] !== null ? (string) $row['sms_sent_at'] : null,
            'public_token' => (string) $row['public_token'],
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'vehicle_label' => trim((string) ($row['brand'] ?? '') . ' ' . (string) ($row['model'] ?? '')),
            'plate' => $row['plate'] !== null ? (string) $row['plate'] : null,
        ];
    }

    $smsStmt = $pdo->prepare(
        'SELECT id, phone, template_key, body, status, error, created_at
         FROM mechanic_sms_log WHERE mechanic_id = ?
         ORDER BY created_at DESC
         LIMIT ' . (int) $smsPageSize . ' OFFSET ' . (int) $smsOffset
    );
    $smsStmt->execute([$mechanicId]);
    $sms = [];
    foreach ($smsStmt->fetchAll() as $row) {
        $sms[] = [
            'id' => (int) $row['id'],
            'phone' => (string) $row['phone'],
            'template_key' => (string) $row['template_key'],
            'body' => (string) $row['body'],
            'status' => (string) $row['status'],
            'error' => $row['error'] !== null ? (string) $row['error'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    return [
        'mechanic' => $mechanic,
        'credit' => seller_credit_public_payload($pdo, $mechanicId, $mechanic['phone']),
        'activity' => [
            'heat' => mechanics_heat_from_last_service($last),
            'score' => mechanics_activity_score($services30d, $customerCount, $last),
            'services_7d' => (int) ($c['services_7d'] ?? 0),
            'services_30d' => $services30d,
            'last_service_at' => $last,
            'customer_count' => $customerCount,
            'vehicle_count' => (int) ($c['vehicle_count'] ?? 0),
            'service_count' => (int) ($c['service_count'] ?? 0),
        ],
        'customers' => $customers,
        'vehicles' => $vehicles,
        'services' => $services,
        'invoices' => $invoices,
        'sms' => $sms,
        'sms_sent_count' => $smsSentCount,
        'sms_total_count' => $smsTotalCount,
        'sms_page' => $smsPage,
        'sms_pages' => $smsPages,
    ];
}
