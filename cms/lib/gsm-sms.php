<?php
declare(strict_types=1);

/**
 * GSM peer-to-peer SMS fallback for sales/admin apps when internet is blocked.
 * Numbers and compact product index are synced while online; the actual SMS
 * traffic happens on-device via the Android SMS gateway.
 */

require_once __DIR__ . '/melipayamak.php';
require_once __DIR__ . '/sales-users.php';

const GSM_SMS_GATEWAY_PHONES_KEY = 'gsm_sms_gateway_phones';

function gsm_sms_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    sales_users_ensure_schema($pdo);

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM sales_users LIKE 'sms_phone'")->fetchAll();
        if (count($cols) === 0) {
            $pdo->exec(
                "ALTER TABLE sales_users
                 ADD COLUMN sms_phone VARCHAR(20) NOT NULL DEFAULT '' AFTER display_name"
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    try {
        $orderCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM orders')->fetchAll() ?: [] as $row) {
            $orderCols[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($orderCols['sms_ref'])) {
            $pdo->exec(
                "ALTER TABLE orders
                 ADD COLUMN sms_ref VARCHAR(16) NULL AFTER public_code"
            );
        }
        if (!isset($orderCols['sms_channel'])) {
            $pdo->exec(
                "ALTER TABLE orders
                 ADD COLUMN sms_channel TINYINT(1) NOT NULL DEFAULT 0 AFTER sms_ref"
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    try {
        $idx = $pdo->query("SHOW INDEX FROM orders WHERE Key_name = 'uq_orders_sms_ref'")->fetchAll();
        if (count($idx) === 0) {
            $pdo->exec('ALTER TABLE orders ADD UNIQUE KEY uq_orders_sms_ref (sms_ref)');
        }
    } catch (Throwable $e) {
        /* ignore — duplicate nulls are allowed on unique nullable columns in MySQL */
    }

    $ready = true;
}

function gsm_sms_normalize_phone(string $phone): string
{
    return cms_sms_normalize_phone($phone);
}

/**
 * @param list<string>|string $raw
 * @return list<string>
 */
function gsm_sms_parse_phones($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[\s,;]+/', (string) $raw) ?: [];
    }
    $out = [];
    $seen = [];
    foreach ($parts as $part) {
        $phone = gsm_sms_normalize_phone((string) $part);
        if ($phone === '' || isset($seen[$phone])) {
            continue;
        }
        if (!preg_match('/^09\d{9}$/', $phone)) {
            continue;
        }
        $seen[$phone] = true;
        $out[] = $phone;
    }
    return $out;
}

/**
 * @return list<string>
 */
function gsm_sms_gateway_phones(): array
{
    return gsm_sms_parse_phones(cms_setting_get(GSM_SMS_GATEWAY_PHONES_KEY, ''));
}

/**
 * @param list<string>|string $phones
 * @return list<string>
 */
function gsm_sms_save_gateway_phones($phones): array
{
    $normalized = gsm_sms_parse_phones($phones);
    cms_setting_set(GSM_SMS_GATEWAY_PHONES_KEY, implode(',', $normalized));
    return $normalized;
}

function gsm_sms_normalize_ref(string $raw): string
{
    $ref = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
    if (strlen($ref) < 2 || strlen($ref) > 16) {
        return '';
    }
    return $ref;
}

/**
 * @return list<array{id:int,username:string,display_name:string,sms_phone:string,branch_id:?int}>
 */
function gsm_sms_sales_directory(PDO $pdo): array
{
    gsm_sms_ensure_schema($pdo);
    $rows = $pdo->query(
        'SELECT id, username, display_name, sms_phone, branch_id
         FROM sales_users
         WHERE published = 1
         ORDER BY display_name ASC, username ASC'
    )->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'display_name' => (string) ($row['display_name'] ?? ''),
            'sms_phone' => gsm_sms_normalize_phone((string) ($row['sms_phone'] ?? '')),
            'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== null
                ? (int) $row['branch_id']
                : null,
        ];
    }
    return $out;
}

/**
 * Compact catalog used by apps to reconstruct SMS order lines offline.
 *
 * @return list<array<string, mixed>>
 */
function gsm_sms_product_index(PDO $pdo): array
{
    $out = [];
    try {
        $rows = $pdo->query(
            'SELECT id, name, slug, pack_size, visual_id, price_text, image
             FROM products
             WHERE published = 1
             ORDER BY id ASC
             LIMIT 4000'
        )->fetchAll() ?: [];
        foreach ($rows as $row) {
            $out[] = gsm_sms_product_index_row($row, false);
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    try {
        $rows = $pdo->query(
            'SELECT id, name, slug, pack_size, visual_id, price_text, image
             FROM product_series
             WHERE published = 1
             ORDER BY id ASC
             LIMIT 500'
        )->fetchAll() ?: [];
        foreach ($rows as $row) {
            $out[] = gsm_sms_product_index_row($row, true);
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function gsm_sms_product_index_row(array $row, bool $series): array
{
    $id = (int) ($row['id'] ?? 0);
    return [
        'id' => $series ? -$id : $id,
        'name' => (string) ($row['name'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''),
        'pack_size' => isset($row['pack_size']) && $row['pack_size'] !== null
            ? (int) $row['pack_size']
            : null,
        'visual_id' => isset($row['visual_id']) && trim((string) $row['visual_id']) !== ''
            ? (string) $row['visual_id']
            : null,
        'price_text' => isset($row['price_text']) && trim((string) $row['price_text']) !== ''
            ? (string) $row['price_text']
            : null,
        'image' => isset($row['image']) && trim((string) $row['image']) !== ''
            ? (string) $row['image']
            : null,
        'is_series' => $series,
    ];
}

/**
 * @return array<string, mixed>
 */
function gsm_sms_config_payload(PDO $pdo, bool $includeDirectory): array
{
    gsm_sms_ensure_schema($pdo);
    $payload = [
        'ok' => true,
        'enabled' => true,
        'gateway_phones' => gsm_sms_gateway_phones(),
        'products' => gsm_sms_product_index($pdo),
    ];
    if ($includeDirectory) {
        $payload['sales_users'] = gsm_sms_sales_directory($pdo);
    }
    return $payload;
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function gsm_sms_ingest_order(PDO $pdo, array $body, ?int $forcedSalesUserId = null): array
{
    gsm_sms_ensure_schema($pdo);
    require_once __DIR__ . '/orders.php';
    require_once __DIR__ . '/branches.php';
    orders_ensure_schema($pdo);
    gsm_sms_ensure_schema($pdo);

    $smsRef = gsm_sms_normalize_ref((string) ($body['sms_ref'] ?? ''));
    if ($smsRef === '') {
        throw new RuntimeException('شناسه پیامک نامعتبر است');
    }

    $existing = $pdo->prepare('SELECT * FROM orders WHERE sms_ref = ? LIMIT 1');
    $existing->execute([$smsRef]);
    $row = $existing->fetch();
    if ($row) {
        $orderId = (int) $row['id'];
        return [
            'ok' => true,
            'deduped' => true,
            'order' => orders_serialize(
                $row,
                orders_fetch_items($pdo, $orderId),
                orders_fetch_events($pdo, $orderId)
            ),
        ];
    }

    $salesUserId = $forcedSalesUserId !== null && $forcedSalesUserId > 0
        ? $forcedSalesUserId
        : (int) ($body['sales_user_id'] ?? 0);
    $fromPhone = gsm_sms_normalize_phone((string) ($body['from_phone'] ?? ($body['phone'] ?? '')));

    $salesUser = null;
    if ($salesUserId > 0) {
        $salesUser = sales_users_get($pdo, $salesUserId);
    }
    if ($salesUser === null && $fromPhone !== '') {
        $byPhone = $pdo->prepare(
            'SELECT id FROM sales_users WHERE sms_phone = ? AND published = 1 LIMIT 1'
        );
        $byPhone->execute([$fromPhone]);
        $foundId = (int) ($byPhone->fetchColumn() ?: 0);
        if ($foundId > 0) {
            $salesUser = sales_users_get($pdo, $foundId);
            $salesUserId = $foundId;
        }
    }
    if ($salesUser === null) {
        throw new RuntimeException('کاربر فروش برای این پیامک یافت نشد');
    }

    $rawItems = $body['items'] ?? null;
    if (!is_array($rawItems) || $rawItems === []) {
        throw new RuntimeException('اقلام سفارش خالی است');
    }
    $expanded = [];
    foreach ($rawItems as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $productId = (int) ($raw['id'] ?? ($raw['product_id'] ?? 0));
        $visualId = trim((string) ($raw['visual_id'] ?? ''));
        if ($productId === 0 && $visualId !== '') {
            $productId = gsm_sms_resolve_product_id($pdo, 0, $visualId);
        }
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '' && $productId !== 0) {
            $name = gsm_sms_product_name($pdo, $productId);
        }
        if ($name === '' && $visualId !== '') {
            $name = 'کد ' . $visualId;
        }
        if ($productId === 0 || $name === '') {
            continue;
        }
        $expanded[] = [
            'id' => $productId,
            'name' => $name,
            'slug' => (string) ($raw['slug'] ?? ''),
            'quantity' => (int) ($raw['quantity'] ?? 1),
            'unit_type' => (string) ($raw['unit_type'] ?? 'piece'),
            'pack_size' => isset($raw['pack_size']) ? (int) $raw['pack_size'] : null,
            'price_text' => $raw['price_text'] ?? null,
            'image' => $raw['image'] ?? null,
            'factory_name' => $raw['factory_name'] ?? null,
            'model_name' => $raw['model_name'] ?? null,
            'category_name' => $raw['category_name'] ?? null,
            'visual_id' => $visualId !== '' ? $visualId : ($raw['visual_id'] ?? null),
        ];
    }
    $normalized = orders_normalize_cart_items($pdo, $expanded);
    if ($normalized === []) {
        throw new RuntimeException('اقلام سفارش نامعتبر است');
    }

    $siteUser = orders_resolve_site_user_for_sales($pdo, $salesUser);
    $phone = $fromPhone !== '' ? $fromPhone : (string) $siteUser['phone'];
    $branchId = isset($salesUser['branch_id']) && $salesUser['branch_id'] !== null
        ? (int) $salesUser['branch_id']
        : 0;
    $branchSnap = orders_branch_snapshot_for_branch_id($pdo, $branchId, $phone);

    $orderId = orders_create_from_normalized(
        $pdo,
        (int) $siteUser['id'],
        $phone,
        $normalized,
        $branchSnap,
        (int) $salesUser['id'],
        'سفارش از طریق پیامک GSM ثبت شد',
        [
            'sms_ref' => $smsRef,
            'sms_channel' => true,
            'event_actor' => 'client',
        ]
    );

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        throw new RuntimeException('خطا در ایجاد سفارش پیامکی');
    }

    return [
        'ok' => true,
        'deduped' => false,
        'order' => orders_serialize(
            $order,
            orders_fetch_items($pdo, $orderId),
            orders_fetch_events($pdo, $orderId)
        ),
    ];
}

function gsm_sms_resolve_product_id(PDO $pdo, int $productId, string $visualId): int
{
    if ($productId !== 0) {
        return $productId;
    }
    $visualId = strtoupper(preg_replace('/[^A-Z0-9]/', '', $visualId) ?? '');
    if ($visualId === '') {
        return 0;
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE UPPER(REPLACE(REPLACE(visual_id, "-", ""), " ", "")) = ? AND published = 1 LIMIT 1');
        $stmt->execute([$visualId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $stmt = $pdo->prepare('SELECT id FROM product_series WHERE UPPER(REPLACE(REPLACE(visual_id, "-", ""), " ", "")) = ? AND published = 1 LIMIT 1');
        $stmt->execute([$visualId]);
        $seriesId = (int) ($stmt->fetchColumn() ?: 0);
        if ($seriesId > 0) {
            return -$seriesId;
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    return 0;
}

function gsm_sms_product_name(PDO $pdo, int $productId): string
{
    if ($productId < 0) {
        try {
            $stmt = $pdo->prepare('SELECT name FROM product_series WHERE id = ? LIMIT 1');
            $stmt->execute([abs($productId)]);
            $name = $stmt->fetchColumn();
            if ($name !== false && trim((string) $name) !== '') {
                return trim((string) $name);
            }
        } catch (Throwable $e) {
            /* ignore */
        }
        return 'سری #' . abs($productId);
    }
    try {
        $stmt = $pdo->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return trim((string) $name);
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    return 'محصول #' . $productId;
}
