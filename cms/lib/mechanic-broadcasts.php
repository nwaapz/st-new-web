<?php
declare(strict_types=1);

/**
 * Mechanic group SMS campaigns: draft → admin approval → locked send.
 * Per-client credit check never sends a partial (multi-segment) message.
 */

require_once __DIR__ . '/seller-credit.php';

function mechanic_broadcasts_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_broadcasts (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          mechanic_id INT UNSIGNED NOT NULL,
          body TEXT NOT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'draft',
          segments INT UNSIGNED NOT NULL DEFAULT 0,
          recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
          sms_total INT UNSIGNED NOT NULL DEFAULT 0,
          sent_count INT UNSIGNED NOT NULL DEFAULT 0,
          skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
          failed_count INT UNSIGNED NOT NULL DEFAULT 0,
          reject_reason TEXT NULL,
          approved_at DATETIME NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_mb_mechanic (mechanic_id),
          KEY idx_mb_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    try {
        $pdo->query('SELECT reject_reason FROM mechanic_broadcasts LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE mechanic_broadcasts ADD COLUMN reject_reason TEXT NULL');
        } catch (Throwable $e2) {
            // already present or no permission
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_broadcast_exempts (
          broadcast_id INT UNSIGNED NOT NULL,
          customer_id INT UNSIGNED NOT NULL,
          PRIMARY KEY (broadcast_id, customer_id),
          KEY idx_mbe_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_broadcast_recipients (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          broadcast_id INT UNSIGNED NOT NULL,
          customer_id INT UNSIGNED NOT NULL,
          phone VARCHAR(20) NOT NULL,
          status VARCHAR(24) NOT NULL DEFAULT 'pending',
          sms_log_id BIGINT UNSIGNED NULL,
          error TEXT NULL,
          sent_at DATETIME NULL,
          PRIMARY KEY (id),
          KEY idx_mbr_broadcast_status (broadcast_id, status),
          KEY idx_mbr_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

function mechanic_broadcast_is_editable(string $status): bool
{
    return $status === 'draft';
}

function mechanic_broadcast_is_sendable(string $status): bool
{
    return in_array($status, ['approved', 'sending', 'paused'], true);
}

function mechanic_broadcast_phone_where(): string
{
    return "phone IS NOT NULL AND TRIM(phone) <> ''";
}

function mechanic_broadcast_phone_customers_count(PDO $pdo, int $mechanicId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM mechanic_customers
         WHERE mechanic_id = ? AND ' . mechanic_broadcast_phone_where()
    );
    $stmt->execute([$mechanicId]);
    return (int) $stmt->fetchColumn();
}

function mechanic_broadcast_audience_count(PDO $pdo, int $broadcastId, int $mechanicId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM mechanic_customers c
         WHERE c.mechanic_id = ?
           AND ' . str_replace('phone', 'c.phone', mechanic_broadcast_phone_where()) . '
           AND c.id NOT IN (
             SELECT e.customer_id FROM mechanic_broadcast_exempts e WHERE e.broadcast_id = ?
           )'
    );
    $stmt->execute([$mechanicId, $broadcastId]);
    return (int) $stmt->fetchColumn();
}

/**
 * @return list<array{id:int,name:string,phone:string}>
 */
function mechanic_broadcast_exempts_list(PDO $pdo, int $broadcastId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.phone
         FROM mechanic_broadcast_exempts e
         INNER JOIN mechanic_customers c ON c.id = e.customer_id
         WHERE e.broadcast_id = ?
         ORDER BY c.name ASC, c.id ASC'
    );
    $stmt->execute([$broadcastId]);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'phone' => $row['phone'] !== null ? (string) $row['phone'] : '',
        ];
    }
    return $out;
}

function mechanic_broadcast_find(PDO $pdo, int $id, ?int $mechanicId = null): ?array
{
    mechanic_broadcasts_ensure_schema($pdo);
    if ($mechanicId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM mechanic_broadcasts WHERE id = ? AND mechanic_id = ? LIMIT 1');
        $stmt->execute([$id, $mechanicId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM mechanic_broadcasts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

function mechanic_broadcast_recipient_counts(PDO $pdo, int $broadcastId): array
{
    $stmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS n FROM mechanic_broadcast_recipients
         WHERE broadcast_id = ? GROUP BY status'
    );
    $stmt->execute([$broadcastId]);
    $counts = [
        'pending' => 0,
        'sent' => 0,
        'skipped_no_credit' => 0,
        'failed' => 0,
    ];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $key = (string) $row['status'];
        $counts[$key] = (int) $row['n'];
    }
    return $counts;
}

/**
 * @return array<string, mixed>
 */
function mechanic_broadcast_serialize(PDO $pdo, array $row, int $phoneCustomers): array
{
    $id = (int) $row['id'];
    $mechanicId = (int) $row['mechanic_id'];
    $status = (string) $row['status'];
    $rc = mechanic_broadcast_recipient_counts($pdo, $id);
    $hasRecipients = in_array($status, ['approved', 'sending', 'paused', 'completed'], true);
    $audience = $hasRecipients
        ? (int) $row['recipient_count']
        : mechanic_broadcast_audience_count($pdo, $id, $mechanicId);

    return [
        'id' => $id,
        'mechanic_id' => $mechanicId,
        'body' => (string) $row['body'],
        'status' => $status,
        'editable' => mechanic_broadcast_is_editable($status),
        'sendable' => mechanic_broadcast_is_sendable($status),
        'segments' => (int) $row['segments'],
        'recipient_count' => (int) $row['recipient_count'],
        'sms_total' => (int) $row['sms_total'],
        'sent_count' => $hasRecipients ? $rc['sent'] : (int) $row['sent_count'],
        'skipped_count' => $hasRecipients ? $rc['skipped_no_credit'] : (int) $row['skipped_count'],
        'failed_count' => $hasRecipients ? $rc['failed'] : (int) $row['failed_count'],
        'pending_count' => $hasRecipients ? $rc['pending'] : $audience,
        'audience_count' => $audience,
        'phone_customers' => $phoneCustomers,
        'exempts' => mechanic_broadcast_exempts_list($pdo, $id),
        'reject_reason' => isset($row['reject_reason']) && $row['reject_reason'] !== null
            ? (string) $row['reject_reason']
            : '',
        'approved_at' => $row['approved_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function mechanic_broadcast_list(PDO $pdo, int $mechanicId, int $phoneCustomers): array
{
    mechanic_broadcasts_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM mechanic_broadcasts WHERE mechanic_id = ? ORDER BY id DESC LIMIT 50'
    );
    $stmt->execute([$mechanicId]);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = mechanic_broadcast_serialize($pdo, $row, $phoneCustomers);
    }
    return $items;
}

/**
 * @return list<array<string, mixed>>
 */
function mechanic_broadcast_list_all(PDO $pdo, string $statusFilter = ''): array
{
    mechanic_broadcasts_ensure_schema($pdo);
    $sql = 'SELECT b.*, m.workshop_name, m.owner_name, m.phone AS mechanic_phone, m.city
            FROM mechanic_broadcasts b
            INNER JOIN mechanics m ON m.id = b.mechanic_id';
    $params = [];
    if ($statusFilter !== '' && $statusFilter !== 'all') {
        if ($statusFilter === 'approved') {
            $sql .= ' WHERE b.status IN (\'approved\',\'sending\',\'paused\',\'completed\')';
        } else {
            $sql .= ' WHERE b.status = ?';
            $params[] = $statusFilter;
        }
    }
    $sql .= ' ORDER BY b.id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $phoneCustomers = mechanic_broadcast_phone_customers_count($pdo, (int) $row['mechanic_id']);
        $item = mechanic_broadcast_serialize($pdo, $row, $phoneCustomers);
        $item['workshop_name'] = (string) $row['workshop_name'];
        $item['owner_name'] = (string) $row['owner_name'];
        $item['mechanic_phone'] = (string) $row['mechanic_phone'];
        $item['city'] = (string) $row['city'];
        $items[] = $item;
    }
    return $items;
}

function mechanic_broadcast_create(PDO $pdo, int $mechanicId, string $body): array
{
    mechanic_broadcasts_ensure_schema($pdo);
    $ins = $pdo->prepare(
        'INSERT INTO mechanic_broadcasts (mechanic_id, body, status) VALUES (?, ?, \'draft\')'
    );
    $ins->execute([$mechanicId, $body]);
    $row = mechanic_broadcast_find($pdo, (int) $pdo->lastInsertId(), $mechanicId);
    if ($row === null) {
        throw new RuntimeException('ساخت پیام گروهی ناموفق بود');
    }
    return $row;
}

function mechanic_broadcast_save_body(PDO $pdo, array $row, string $body): void
{
    if (!mechanic_broadcast_is_editable((string) $row['status'])) {
        throw new RuntimeException('پس از ارسال برای تأیید ادمین امکان ویرایش متن نیست');
    }
    $pdo->prepare('UPDATE mechanic_broadcasts SET body = ? WHERE id = ?')->execute([
        $body,
        (int) $row['id'],
    ]);
}

/**
 * @param list<int> $customerIds
 */
function mechanic_broadcast_set_exempts(PDO $pdo, array $row, int $mechanicId, array $customerIds): void
{
    if (!mechanic_broadcast_is_editable((string) $row['status'])) {
        throw new RuntimeException('پس از ارسال برای تأیید ادمین امکان تغییر فهرست مستثنا نیست');
    }
    $broadcastId = (int) $row['id'];
    $ids = [];
    foreach ($customerIds as $cid) {
        $cid = (int) $cid;
        if ($cid > 0 && !in_array($cid, $ids, true)) {
            $ids[] = $cid;
        }
    }

    $pdo->prepare('DELETE FROM mechanic_broadcast_exempts WHERE broadcast_id = ?')->execute([$broadcastId]);
    if ($ids === []) {
        return;
    }

    $check = $pdo->prepare(
        'SELECT id FROM mechanic_customers WHERE id = ? AND mechanic_id = ? LIMIT 1'
    );
    $ins = $pdo->prepare(
        'INSERT INTO mechanic_broadcast_exempts (broadcast_id, customer_id) VALUES (?, ?)'
    );
    foreach ($ids as $cid) {
        $check->execute([$cid, $mechanicId]);
        if ($check->fetch()) {
            $ins->execute([$broadcastId, $cid]);
        }
    }
}

function mechanic_broadcast_submit(PDO $pdo, array $row): void
{
    if (!mechanic_broadcast_is_editable((string) $row['status'])) {
        throw new RuntimeException('این پیام قبلاً تأیید شده است');
    }
    $body = trim((string) $row['body']);
    if ($body === '') {
        throw new RuntimeException('متن پیام خالی است');
    }
    $pdo->prepare(
        'UPDATE mechanic_broadcasts SET status = \'pending\' WHERE id = ?'
    )->execute([(int) $row['id']]);
}

function mechanic_broadcast_reject(PDO $pdo, array $row, string $reason): void
{
    $status = (string) $row['status'];
    if ($status !== 'pending') {
        throw new RuntimeException('فقط پیام در انتظار را می‌توان رد کرد');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('دلیل رد را بنویسید');
    }
    $pdo->prepare(
        'UPDATE mechanic_broadcasts SET status = \'rejected\', reject_reason = ? WHERE id = ?'
    )->execute([$reason, (int) $row['id']]);
}

function mechanic_broadcast_approve(PDO $pdo, array $row): void
{
    $status = (string) $row['status'];
    if ($status !== 'pending') {
        throw new RuntimeException('فقط پیام در انتظار را می‌توان تأیید کرد');
    }
    $body = trim((string) $row['body']);
    if ($body === '') {
        throw new RuntimeException('متن پیام خالی است');
    }

    $broadcastId = (int) $row['id'];
    $mechanicId = (int) $row['mechanic_id'];
    $shop = mechanics_find_by_id($pdo, $mechanicId);
    $shopPhone = $shop !== null ? (string) $shop['phone'] : '';
    $body = mechanic_sms_with_shop_phone($body, $shopPhone);
    $segments = seller_credit_sms_segments($body);
    if ($segments < 1) {
        throw new RuntimeException('متن پیام خالی است');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM mechanic_broadcast_recipients WHERE broadcast_id = ?')->execute([$broadcastId]);
        $ins = $pdo->prepare(
            'INSERT INTO mechanic_broadcast_recipients (broadcast_id, customer_id, phone, status)
             SELECT ?, c.id, c.phone, \'pending\'
             FROM mechanic_customers c
             LEFT JOIN mechanic_broadcast_exempts e
               ON e.broadcast_id = ? AND e.customer_id = c.id
             WHERE c.mechanic_id = ?
               AND c.phone IS NOT NULL AND TRIM(c.phone) <> \'\'
               AND e.customer_id IS NULL'
        );
        $ins->execute([$broadcastId, $broadcastId, $mechanicId]);
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM mechanic_broadcast_recipients WHERE broadcast_id = ?'
        );
        $countStmt->execute([$broadcastId]);
        $recipientCount = (int) $countStmt->fetchColumn();
        $smsTotal = $segments * $recipientCount;

        $pdo->prepare(
            'UPDATE mechanic_broadcasts
             SET status = \'approved\', body = ?, segments = ?, recipient_count = ?, sms_total = ?,
                 sent_count = 0, skipped_count = 0, failed_count = 0, approved_at = NOW()
             WHERE id = ?'
        )->execute([$body, $segments, $recipientCount, $smsTotal, $broadcastId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function mechanic_broadcast_sync_counts(PDO $pdo, int $broadcastId): void
{
    $rc = mechanic_broadcast_recipient_counts($pdo, $broadcastId);
    $pdo->prepare(
        'UPDATE mechanic_broadcasts
         SET sent_count = ?, skipped_count = ?, failed_count = ?
         WHERE id = ?'
    )->execute([$rc['sent'], $rc['skipped_no_credit'], $rc['failed'], $broadcastId]);
}

/**
 * Send up to $limit pending recipients. Stops (pauses) when credit cannot
 * cover one full message. Never sends a partial message to a client.
 * Outbound is allowed 09:00–21:00 Asia/Tehran; remaining stay pending.
 *
 * @return array{
 *   ok:bool,
 *   paused:bool,
 *   outside_hours:bool,
 *   completed:bool,
 *   processed:int,
 *   sent:int,
 *   failed:int,
 *   remaining:int,
 *   status:string,
 *   error:?string
 * }
 */
function mechanic_broadcast_send_batch(PDO $pdo, int $broadcastId, int $limit = 20): array
{
    require_once __DIR__ . '/melipayamak.php';
    mechanic_broadcasts_ensure_schema($pdo);
    seller_credit_ensure_schema($pdo);
    $limit = max(1, min(20, $limit));

    $row = mechanic_broadcast_find($pdo, $broadcastId);
    if ($row === null) {
        throw new RuntimeException('پیام گروهی یافت نشد');
    }
    if (!mechanic_broadcast_is_sendable((string) $row['status']) && (string) $row['status'] !== 'completed') {
        throw new RuntimeException('این پیام هنوز تأیید نشده است');
    }
    if ((string) $row['status'] === 'completed') {
        return [
            'ok' => true,
            'paused' => false,
            'outside_hours' => false,
            'completed' => true,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'remaining' => 0,
            'status' => 'completed',
            'error' => null,
        ];
    }

    $pendingCount = static function (PDO $pdo, int $broadcastId): int {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM mechanic_broadcast_recipients WHERE broadcast_id = ? AND status = \'pending\''
        );
        $stmt->execute([$broadcastId]);
        return (int) $stmt->fetchColumn();
    };

    $window = mechanic_sms_send_window();
    if (!$window['ok']) {
        return [
            'ok' => true,
            'paused' => false,
            'outside_hours' => true,
            'completed' => false,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'remaining' => $pendingCount($pdo, $broadcastId),
            'status' => (string) $row['status'],
            'error' => $window['error'],
        ];
    }

    $mechanicId = (int) $row['mechanic_id'];
    $mStmt = $pdo->prepare('SELECT phone FROM mechanics WHERE id = ? LIMIT 1');
    $mStmt->execute([$mechanicId]);
    $mechanic = $mStmt->fetch();
    if (!$mechanic) {
        throw new RuntimeException('تعمیرگاه یافت نشد');
    }
    $mechanicPhone = (string) ($mechanic['phone'] ?? '');
    $body = mechanic_sms_with_shop_phone((string) $row['body'], $mechanicPhone);
    if ($body !== (string) $row['body']) {
        $pdo->prepare('UPDATE mechanic_broadcasts SET body = ? WHERE id = ?')->execute([$body, $broadcastId]);
    }
    $segments = seller_credit_sms_segments($body);
    if ($segments < 1) {
        throw new RuntimeException('متن پیام خالی است');
    }

    $pdo->prepare(
        'UPDATE mechanic_broadcasts SET status = \'sending\', segments = ? WHERE id = ?'
    )->execute([$segments, $broadcastId]);

    $sel = $pdo->prepare(
        'SELECT id, customer_id, phone FROM mechanic_broadcast_recipients
         WHERE broadcast_id = ? AND status = \'pending\'
         ORDER BY id ASC LIMIT ' . $limit
    );
    $sel->execute([$broadcastId]);
    $batch = $sel->fetchAll() ?: [];

    $sent = 0;
    $failed = 0;
    $processed = 0;
    $paused = false;
    $outsideHours = false;
    $pauseError = null;

    $upd = $pdo->prepare(
        'UPDATE mechanic_broadcast_recipients
         SET status = ?, sms_log_id = ?, error = ?, sent_at = ?
         WHERE id = ? AND broadcast_id = ?'
    );
    $log = $pdo->prepare(
        'INSERT INTO mechanic_sms_log (mechanic_id, vehicle_id, customer_id, phone, template_key, body, status, error)
         VALUES (?, NULL, ?, ?, \'broadcast\', ?, ?, ?)'
    );

    foreach ($batch as $rec) {
        $window = mechanic_sms_send_window();
        if (!$window['ok']) {
            $outsideHours = true;
            $pauseError = $window['error'];
            break;
        }
        $canSend = seller_credit_can_send_sms($pdo, $mechanicId, $mechanicPhone, $segments);
        if (!$canSend['ok']) {
            $paused = true;
            $pauseError = $canSend['error'] ?? 'موجودی اعتبار برای یک پیام کامل کافی نیست';
            break;
        }
        $smsCost = (int) ($canSend['total_cost'] ?? 0);
        $processed++;
        $phone = (string) $rec['phone'];
        $result = cms_sms_send($phone, $body);

        $log->execute([
            $mechanicId,
            (int) $rec['customer_id'],
            $phone,
            $body,
            $result['ok'] ? 'sent' : 'failed',
            $result['ok'] ? null : ($result['error'] ?? null),
        ]);
        $smsLogId = (int) $pdo->lastInsertId();

        if (!$result['ok']) {
            $failed++;
            $upd->execute([
                'failed',
                $smsLogId > 0 ? $smsLogId : null,
                $result['error'] ?? 'ارسال ناموفق بود',
                null,
                (int) $rec['id'],
                $broadcastId,
            ]);
            continue;
        }

        if ($smsCost > 0) {
            seller_credit_debit_sms($pdo, $mechanicId, $mechanicPhone, $smsCost, $smsLogId > 0 ? $smsLogId : null);
        }
        $sent++;
        $upd->execute([
            'sent',
            $smsLogId > 0 ? $smsLogId : null,
            null,
            date('Y-m-d H:i:s'),
            (int) $rec['id'],
            $broadcastId,
        ]);
    }

    mechanic_broadcast_sync_counts($pdo, $broadcastId);

    $remaining = $pendingCount($pdo, $broadcastId);

    $newStatus = 'sending';
    if ($remaining === 0) {
        $newStatus = 'completed';
        $paused = false;
        $outsideHours = false;
    } elseif ($paused) {
        $newStatus = 'paused';
    }
    $pdo->prepare('UPDATE mechanic_broadcasts SET status = ? WHERE id = ?')->execute([$newStatus, $broadcastId]);

    return [
        'ok' => true,
        'paused' => $paused,
        'outside_hours' => $outsideHours,
        'completed' => $newStatus === 'completed',
        'processed' => $processed,
        'sent' => $sent,
        'failed' => $failed,
        'remaining' => $remaining,
        'status' => $newStatus,
        'error' => $pauseError,
    ];
}
