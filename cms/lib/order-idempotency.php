<?php
declare(strict_types=1);

/**
 * Shared client-order UUID idempotency for shop, sales, admin, and SMS ingest.
 */

class OrdersConflictException extends RuntimeException
{
}

class OrdersIdempotencyForbiddenException extends RuntimeException
{
}

function orders_new_client_order_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function orders_normalize_client_order_uuid(?string $raw): ?string
{
    $raw = strtolower(trim((string) $raw));
    if ($raw === '') {
        return null;
    }
    $hex = preg_replace('/[^a-f0-9]/', '', $raw) ?? '';
    if (strlen($hex) !== 32) {
        return null;
    }

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

/**
 * @param array<string, mixed> $body
 */
function orders_client_order_uuid_from_request(array $body): ?string
{
    $present = array_key_exists('client_order_uuid', $body)
        || array_key_exists('clientOrderUuid', $body);
    if (!$present) {
        return null;
    }
    $raw = $body['client_order_uuid'] ?? $body['clientOrderUuid'] ?? '';
    if (trim((string) $raw) === '') {
        return null;
    }
    $uuid = orders_normalize_client_order_uuid((string) $raw);
    if ($uuid === null) {
        throw new InvalidArgumentException('شناسه سفارش نامعتبر است');
    }

    return $uuid;
}

/**
 * 12-char base36 compact SMS ref derived from a canonical UUID v4.
 * Must stay in sync with SmsProtocol.compactSmsRefFromUuid on both Android apps.
 */
function orders_compact_sms_ref_from_uuid(string $uuid): string
{
    $canonical = orders_normalize_client_order_uuid($uuid);
    if ($canonical === null) {
        return '';
    }
    $hash = hash('sha256', $canonical, true);
    $bytes = substr($hash, 0, 8);
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $arr = [];
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $arr[] = ord($bytes[$i]);
    }
    $digits = [];
    for ($k = 0; $k < 12; $k++) {
        $remainder = 0;
        $next = [];
        foreach ($arr as $d) {
            $acc = $remainder * 256 + $d;
            $q = intdiv($acc, 36);
            $remainder = $acc % 36;
            if ($next !== [] || $q !== 0) {
                $next[] = $q;
            }
        }
        $digits[] = $alphabet[$remainder];
        $arr = $next;
    }

    return strrev(implode('', $digits));
}

/**
 * @param array<string, mixed> $item
 */
function orders_fingerprint_item_line(array $item, bool $preferOriginal = true): string
{
    $pid = isset($item['product_id']) && $item['product_id'] !== null
        ? (int) $item['product_id']
        : 0;
    $vid = strtoupper(trim((string) ($item['visual_id'] ?? '')));
    $qty = (int) ($item['quantity'] ?? 0);
    $unit = function_exists('orders_item_unit_type')
        ? orders_item_unit_type($item)
        : ((isset($item['unit_type']) && (string) $item['unit_type'] === 'pack') ? 'pack' : 'piece');
    if ($preferOriginal && isset($item['original_quantity']) && $item['original_quantity'] !== null) {
        $qty = (int) $item['original_quantity'];
        if (isset($item['original_unit_type']) && trim((string) $item['original_unit_type']) !== '') {
            $unit = (string) $item['original_unit_type'] === 'pack' ? 'pack' : 'piece';
        }
    }

    $flashId = isset($item['flash_deal_id']) && $item['flash_deal_id'] !== null
        ? (int) $item['flash_deal_id']
        : 0;

    return $pid . '|' . $vid . '|' . $unit . '|' . $qty . '|fd' . $flashId;
}

/**
 * @param list<array<string, mixed>>|array<string, array<string, mixed>> $items
 */
function orders_items_fingerprint($items, bool $preferOriginal = true): string
{
    $lines = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $lines[] = orders_fingerprint_item_line($item, $preferOriginal);
    }
    sort($lines, SORT_STRING);

    return hash('sha256', implode("\n", $lines));
}

/**
 * @param array{role:string, user_id:?int, sales_user_id:?int} $owner
 * @param array<string, mixed> $order
 */
function orders_client_uuid_owner_ok(array $order, array $owner): bool
{
    $role = (string) ($owner['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'shop') {
        $userId = (int) ($owner['user_id'] ?? 0);
        return $userId > 0 && (int) ($order['user_id'] ?? 0) === $userId;
    }
    if ($role === 'sales' || $role === 'sms') {
        $salesUserId = (int) ($owner['sales_user_id'] ?? 0);
        return $salesUserId > 0 && (int) ($order['sales_user_id'] ?? 0) === $salesUserId;
    }

    return false;
}

/**
 * @return array<string, mixed>|null
 */
function orders_find_by_client_identity(PDO $pdo, ?string $uuid, ?string $smsRef): ?array
{
    $byUuid = null;
    $byRef = null;
    if ($uuid !== null && $uuid !== '') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE client_order_uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $byUuid = $row;
        }
    }
    if ($smsRef !== null && $smsRef !== '') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE sms_ref = ? LIMIT 1');
        $stmt->execute([$smsRef]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $byRef = $row;
        }
    }
    if ($byUuid !== null && $byRef !== null && (int) $byUuid['id'] !== (int) $byRef['id']) {
        error_log(
            '[orders] identity split uuid_order=' . (int) $byUuid['id']
            . ' sms_ref_order=' . (int) $byRef['id']
        );
        throw new OrdersConflictException('شناسه سفارش با رکورد دیگری تداخل دارد');
    }

    return $byUuid ?? $byRef;
}

/**
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>>|array<string, array<string, mixed>> $incomingItems
 * @param array{role:string, user_id:?int, sales_user_id:?int} $owner
 */
function orders_assert_idempotent_retry(
    PDO $pdo,
    array $order,
    $incomingItems,
    array $owner
): void {
    if (!orders_client_uuid_owner_ok($order, $owner)) {
        error_log(
            '[orders] idempotency owner mismatch order_id=' . (int) ($order['id'] ?? 0)
        );
        throw new OrdersIdempotencyForbiddenException('سفارش یافت نشد');
    }
    $orderId = (int) $order['id'];
    $stored = function_exists('orders_fetch_items')
        ? orders_fetch_items($pdo, $orderId)
        : [];
    $incomingFp = orders_items_fingerprint($incomingItems, false);
    $storedFp = orders_items_fingerprint($stored, true);
    if ($incomingFp !== $storedFp) {
        error_log(
            '[orders] idempotency payload conflict order_id=' . $orderId
        );
        throw new OrdersConflictException(
            'این شناسه سفارش قبلاً با اقلام متفاوت ثبت شده است'
        );
    }
}

function orders_backfill_client_keys(
    PDO $pdo,
    int $orderId,
    ?string $uuid,
    ?string $smsRef
): void {
    $stmt = $pdo->prepare('SELECT client_order_uuid, sms_ref FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return;
    }
    $sets = [];
    $params = [];
    $existingUuid = orders_normalize_client_order_uuid(
        isset($row['client_order_uuid']) ? (string) $row['client_order_uuid'] : null
    );
    $existingRef = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($row['sms_ref'] ?? '')) ?? '');
    if ($uuid !== null && $uuid !== '' && $existingUuid === null) {
        $sets[] = 'client_order_uuid = ?';
        $params[] = $uuid;
    } elseif ($uuid !== null && $uuid !== '' && $existingUuid !== null && $existingUuid !== $uuid) {
        error_log('[orders] refuse uuid overwrite order_id=' . $orderId);
        throw new OrdersConflictException('شناسه سفارش با رکورد دیگری تداخل دارد');
    }
    if ($smsRef !== null && $smsRef !== '' && $existingRef === '') {
        $sets[] = 'sms_ref = ?';
        $params[] = $smsRef;
    } elseif ($smsRef !== null && $smsRef !== '' && $existingRef !== '' && $existingRef !== strtoupper($smsRef)) {
        error_log('[orders] refuse sms_ref overwrite order_id=' . $orderId);
        throw new OrdersConflictException('شناسه سفارش با رکورد دیگری تداخل دارد');
    }
    if ($sets === []) {
        return;
    }
    $params[] = $orderId;
    $pdo->prepare(
        'UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?'
    )->execute($params);
}

/**
 * @param list<array<string, mixed>>|array<string, array<string, mixed>> $incomingItems
 * @param array{role:string, user_id:?int, sales_user_id:?int} $owner
 * @return array{order_id:int, duplicate:bool}|null
 */
function orders_try_existing_identity(
    PDO $pdo,
    ?string $uuid,
    ?string $smsRef,
    $incomingItems,
    array $owner
): ?array {
    if (($uuid === null || $uuid === '') && ($smsRef === null || $smsRef === '')) {
        return null;
    }
    $existing = orders_find_by_client_identity($pdo, $uuid, $smsRef);
    if ($existing === null) {
        return null;
    }
    orders_assert_idempotent_retry($pdo, $existing, $incomingItems, $owner);
    $orderId = (int) $existing['id'];
    orders_backfill_client_keys($pdo, $orderId, $uuid, $smsRef);
    error_log('[orders] duplicate identity order_id=' . $orderId);
    if (is_file(__DIR__ . '/application-logs.php')) {
        require_once __DIR__ . '/application-logs.php';
        cms_app_log([
            'source_app' => 'backend',
            'severity' => 'NOTICE',
            'category' => 'order',
            'event_code' => 'order_duplicate_identity',
            'summary' => 'تلاش ثبت تکراری سفارش شناسایی شد',
            'related_order_id' => $orderId,
            'metadata' => ['has_uuid' => $uuid !== null && $uuid !== '', 'has_sms_ref' => $smsRef !== null && $smsRef !== ''],
        ]);
    }

    return ['order_id' => $orderId, 'duplicate' => true];
}

function orders_is_identity_duplicate_key(Throwable $e): bool
{
    if (!($e instanceof PDOException)) {
        return false;
    }
    $info = $e->errorInfo ?? [];
    $sqlstate = (string) ($info[0] ?? '');
    $driver = (int) ($info[1] ?? 0);
    $msg = strtolower((string) ($info[2] ?? $e->getMessage()));
    if ($sqlstate !== '23000' && $driver !== 1062) {
        return false;
    }
    if (str_contains($msg, 'public_code') || str_contains($msg, 'uq_orders_public_code')) {
        return false;
    }

    return str_contains($msg, 'client_order_uuid')
        || str_contains($msg, 'sms_ref')
        || str_contains($msg, 'uq_orders_client_order_uuid')
        || str_contains($msg, 'uq_orders_sms_ref')
        || $driver === 1062;
}

/**
 * @param array<string, mixed> $order
 */
function orders_serialize_client_order_uuid(array $order): ?string
{
    return orders_normalize_client_order_uuid(
        isset($order['client_order_uuid']) ? (string) $order['client_order_uuid'] : null
    );
}

function orders_api_emit_idempotency_error(Throwable $e): void
{
    if (is_file(__DIR__ . '/application-logs.php')) {
        require_once __DIR__ . '/application-logs.php';
        cms_app_log([
            'source_app' => 'backend',
            'severity' => 'WARNING',
            'category' => 'order',
            'event_code' => $e instanceof OrdersConflictException ? 'order_conflict' : 'order_idempotency_forbidden',
            'summary' => $e instanceof OrdersConflictException
                ? 'تداخل وضعیت یا شناسه سفارش'
                : 'تلاش ثبت سفارش با مالکیت نامعتبر',
            'metadata' => ['error' => $e->getMessage()],
        ]);
    }
    if ($e instanceof OrdersConflictException) {
        api_json(['ok' => false, 'error' => $e->getMessage(), 'conflict' => true], 409);
    }
    if ($e instanceof OrdersIdempotencyForbiddenException) {
        api_json(['ok' => false, 'error' => 'سفارش یافت نشد'], 404);
    }
}
