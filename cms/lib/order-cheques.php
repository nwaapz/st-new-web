<?php
declare(strict_types=1);

/**
 * Physical post-dated cheques attached to an order.
 */

require_once __DIR__ . '/jalali.php';

function order_cheques_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_cheques (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          order_id INT UNSIGNED NOT NULL,
          received_on DATE NOT NULL,
          due_on DATE NOT NULL,
          serial VARCHAR(64) NULL,
          amount_text VARCHAR(128) NULL,
          bank_result VARCHAR(16) NULL,
          reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_order_cheques_order (order_id),
          KEY idx_order_cheques_due (due_on, bank_result, reminder_sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

/** @return array<string, string> */
function order_cheques_event_labels(): array
{
    return [
        'cheque_received' => 'دریافت چک',
        'cheque_due_soon' => 'یادآوری سررسید چک',
        'cheque_funded' => 'وصول چک',
        'cheque_bounced' => 'برگشت چک',
    ];
}

function order_cheques_result_label(?string $result): string
{
    return match ($result) {
        'funded' => 'وصول شد',
        'bounced' => 'برگشت خورد',
        default => 'در انتظار بانک',
    };
}

function order_cheques_normalize_date(string $raw, string $label = 'تاریخ'): string
{
    $raw = trim($raw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        throw new RuntimeException($label . ' نامعتبر است');
    }
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    if ($dt === false || $dt->format('Y-m-d') !== $raw) {
        throw new RuntimeException($label . ' نامعتبر است');
    }

    return $raw;
}

function order_cheques_format_date(string $ymd): string
{
    $ymd = trim($ymd);
    if ($ymd === '') {
        return '—';
    }
    if (function_exists('cms_jalali_format_date')) {
        return cms_to_persian_digits(cms_jalali_format_date($ymd));
    }

    return $ymd;
}

/**
 * @return list<array<string, mixed>>
 */
function order_cheques_fetch(PDO $pdo, int $orderId): array
{
    order_cheques_ensure_schema($pdo);
    if ($orderId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM order_cheques WHERE order_id = ? ORDER BY due_on ASC, id ASC'
    );
    $stmt->execute([$orderId]);
    $rows = $stmt->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = order_cheques_serialize_row($row);
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function order_cheques_serialize_row(array $row): array
{
    $result = isset($row['bank_result']) ? trim((string) $row['bank_result']) : '';
    if (!in_array($result, ['funded', 'bounced'], true)) {
        $result = '';
    }
    $serial = isset($row['serial']) ? trim((string) $row['serial']) : '';
    $amount = isset($row['amount_text']) ? trim((string) $row['amount_text']) : '';

    return [
        'id' => (int) $row['id'],
        'order_id' => (int) $row['order_id'],
        'received_on' => (string) $row['received_on'],
        'due_on' => (string) $row['due_on'],
        'serial' => $serial !== '' ? $serial : null,
        'amount_text' => $amount !== '' ? $amount : null,
        'bank_result' => $result !== '' ? $result : null,
        'bank_result_label' => order_cheques_result_label($result !== '' ? $result : null),
        'reminder_sent_at' => isset($row['reminder_sent_at']) && $row['reminder_sent_at'] !== null
            ? (string) $row['reminder_sent_at']
            : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function order_cheques_all_funded(array $cheques): bool
{
    if ($cheques === []) {
        return false;
    }
    foreach ($cheques as $cheque) {
        if (($cheque['bank_result'] ?? null) !== 'funded') {
            return false;
        }
    }

    return true;
}

function order_cheques_get(PDO $pdo, int $chequeId, int $orderId = 0): ?array
{
    order_cheques_ensure_schema($pdo);
    if ($chequeId <= 0) {
        return null;
    }
    if ($orderId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM order_cheques WHERE id = ? AND order_id = ? LIMIT 1');
        $stmt->execute([$chequeId, $orderId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM order_cheques WHERE id = ? LIMIT 1');
        $stmt->execute([$chequeId]);
    }
    $row = $stmt->fetch();

    return $row ?: null;
}

function order_cheques_contact_phone(array $order): string
{
    $phone = trim((string) ($order['phone'] ?? ''));
    if ($phone === '') {
        $phone = trim((string) ($order['branch_phone'] ?? ''));
    }

    return $phone;
}

function order_cheques_notify(
    PDO $pdo,
    array $order,
    string $notifyType,
    string $eventMessage,
    string $smsText
): void {
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }
    $status = (string) ($order['status'] ?? 'submitted');
    try {
        orders_add_event($pdo, $orderId, $status, $notifyType, 'admin', $eventMessage);
    } catch (Throwable $e) {
        error_log('[order_cheques_notify] event: ' . $e->getMessage());
    }

    if (!function_exists('orders_admin_notify_sales_client')) {
        $pushLib = __DIR__ . '/sales-push.php';
        if (is_readable($pushLib)) {
            require_once $pushLib;
        }
    }
    if (function_exists('orders_admin_notify_sales_client')) {
        orders_admin_notify_sales_client($pdo, $orderId, $notifyType, $eventMessage);
    } elseif (function_exists('sales_push_notify_status_change')) {
        try {
            sales_push_notify_status_change($pdo, $orderId, $notifyType, $eventMessage);
        } catch (Throwable $e) {
            error_log('[order_cheques_notify] push: ' . $e->getMessage());
        }
    }

    $phone = order_cheques_contact_phone($order);
    if ($phone === '' || $smsText === '') {
        return;
    }
    if (!function_exists('cms_sms_send')) {
        $smsLib = __DIR__ . '/melipayamak.php';
        if (is_readable($smsLib)) {
            require_once $smsLib;
        }
    }
    if (!function_exists('cms_sms_send')) {
        return;
    }
    try {
        cms_sms_send($phone, $smsText);
    } catch (Throwable $e) {
        error_log('[order_cheques_notify] sms: ' . $e->getMessage());
    }
}

function order_cheques_sms_body(string $notifyType, string $publicCode, string $dueOn, string $serial = ''): string
{
    $code = $publicCode !== '' ? $publicCode : '—';
    $due = order_cheques_format_date($dueOn);
    $serialBit = $serial !== '' ? ' (شماره ' . $serial . ')' : '';

    return match ($notifyType) {
        'cheque_received' => 'چک سفارش ' . $code . $serialBit . ' دریافت شد. سررسید: ' . $due,
        'cheque_due_soon' => 'یادآوری: سررسید چک سفارش ' . $code . $serialBit . ' دو روز دیگر است (' . $due . ').',
        'cheque_funded' => 'چک سفارش ' . $code . $serialBit . ' در بانک وصول شد.',
        'cheque_bounced' => 'چک سفارش ' . $code . $serialBit . ' در بانک برگشت خورد.',
        default => 'به‌روزرسانی چک سفارش ' . $code,
    };
}

/**
 * @param array<string, mixed> $input
 */
function order_cheques_add(PDO $pdo, array $order, array $input): array
{
    order_cheques_ensure_schema($pdo);
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('سفارش نامعتبر');
    }
    $receivedOn = order_cheques_normalize_date(
        (string) ($input['received_on'] ?? date('Y-m-d')),
        'تاریخ دریافت'
    );
    $dueOn = order_cheques_normalize_date((string) ($input['due_on'] ?? ''), 'سررسید چک');
    $serial = trim((string) ($input['serial'] ?? ''));
    $amount = trim((string) ($input['amount_text'] ?? ''));
    if (function_exists('mb_substr')) {
        $serial = mb_substr($serial, 0, 64);
        $amount = mb_substr($amount, 0, 128);
    } else {
        $serial = substr($serial, 0, 64);
        $amount = substr($amount, 0, 128);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO order_cheques (order_id, received_on, due_on, serial, amount_text)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $orderId,
        $receivedOn,
        $dueOn,
        $serial !== '' ? $serial : null,
        $amount !== '' ? $amount : null,
    ]);

    $publicCode = (string) ($order['public_code'] ?? '');
    $sms = order_cheques_sms_body('cheque_received', $publicCode, $dueOn, $serial);
    order_cheques_notify($pdo, $order, 'cheque_received', $sms, $sms);

    return ['message' => 'چک ثبت شد و اطلاع‌رسانی ارسال شد'];
}

function order_cheques_set_result(PDO $pdo, array $order, int $chequeId, string $result): array
{
    order_cheques_ensure_schema($pdo);
    $orderId = (int) ($order['id'] ?? 0);
    if (!in_array($result, ['funded', 'bounced'], true)) {
        throw new RuntimeException('نتیجه بانک نامعتبر است');
    }
    $row = order_cheques_get($pdo, $chequeId, $orderId);
    if ($row === null) {
        throw new RuntimeException('چک یافت نشد');
    }

    $stmt = $pdo->prepare('UPDATE order_cheques SET bank_result = ? WHERE id = ? AND order_id = ?');
    $stmt->execute([$result, $chequeId, $orderId]);

    $notifyType = $result === 'funded' ? 'cheque_funded' : 'cheque_bounced';
    $serial = trim((string) ($row['serial'] ?? ''));
    $dueOn = (string) ($row['due_on'] ?? '');
    $publicCode = (string) ($order['public_code'] ?? '');
    $sms = order_cheques_sms_body($notifyType, $publicCode, $dueOn, $serial);
    order_cheques_notify($pdo, $order, $notifyType, $sms, $sms);

    $label = order_cheques_result_label($result);

    return ['message' => 'نتیجه چک ثبت شد: ' . $label];
}

function order_cheques_delete(PDO $pdo, array $order, int $chequeId): array
{
    order_cheques_ensure_schema($pdo);
    $orderId = (int) ($order['id'] ?? 0);
    $row = order_cheques_get($pdo, $chequeId, $orderId);
    if ($row === null) {
        throw new RuntimeException('چک یافت نشد');
    }
    $existing = isset($row['bank_result']) ? trim((string) $row['bank_result']) : '';
    if ($existing !== '') {
        throw new RuntimeException('چک دارای نتیجه بانک را نمی‌توان حذف کرد');
    }
    $stmt = $pdo->prepare('DELETE FROM order_cheques WHERE id = ? AND order_id = ?');
    $stmt->execute([$chequeId, $orderId]);

    return ['message' => 'چک حذف شد'];
}

function order_cheques_cron_secret(): string
{
    $config = function_exists('cms_config') ? cms_config() : [];
    $fromFile = trim((string) ($config['cheque_cron_key'] ?? ''));
    if ($fromFile !== '') {
        return $fromFile;
    }
    $km = trim((string) ($config['km_cron_key'] ?? ''));
    if ($km !== '') {
        return $km;
    }
    if (function_exists('cms_setting_get')) {
        $kmStored = cms_setting_get('mechanic_km_cron_key');
        if ($kmStored !== '') {
            return $kmStored;
        }
        $stored = cms_setting_get('cheque_cron_key');
        if ($stored !== '') {
            return $stored;
        }
    }
    $generated = bin2hex(random_bytes(16));
    if (function_exists('cms_setting_set')) {
        cms_setting_set('cheque_cron_key', $generated);
    }

    return $generated;
}

function order_cheques_in_reminder_window(?DateTimeImmutable $now = null): bool
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $minutes = ((int) $now->format('G')) * 60 + (int) $now->format('i');

    return $minutes >= (21 * 60 + 30) && $minutes < (22 * 60 + 30);
}

/**
 * @return array{sent: int, failed: int, skipped: bool, reason?: string}
 */
function order_cheques_send_due_reminders(PDO $pdo, bool $forceWindow = false): array
{
    order_cheques_ensure_schema($pdo);
    if (!$forceWindow && !order_cheques_in_reminder_window()) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'reason' => 'outside_window'];
    }

    $stmt = $pdo->query(
        "SELECT c.*, o.public_code, o.phone, o.branch_phone, o.sales_user_id, o.status, o.id AS order_pk
         FROM order_cheques c
         INNER JOIN orders o ON o.id = c.order_id
         WHERE c.bank_result IS NULL
           AND c.reminder_sent_at IS NULL
           AND c.due_on = DATE_ADD(CURDATE(), INTERVAL 2 DAY)
         ORDER BY c.id ASC
         LIMIT 80"
    );
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    $sent = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $order = [
            'id' => (int) ($row['order_pk'] ?? $row['order_id']),
            'public_code' => (string) ($row['public_code'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'branch_phone' => (string) ($row['branch_phone'] ?? ''),
            'sales_user_id' => $row['sales_user_id'] ?? null,
            'status' => (string) ($row['status'] ?? 'submitted'),
        ];
        $serial = trim((string) ($row['serial'] ?? ''));
        $dueOn = (string) ($row['due_on'] ?? '');
        $sms = order_cheques_sms_body('cheque_due_soon', $order['public_code'], $dueOn, $serial);
        try {
            order_cheques_notify($pdo, $order, 'cheque_due_soon', $sms, $sms);
            $mark = $pdo->prepare('UPDATE order_cheques SET reminder_sent_at = NOW() WHERE id = ?');
            $mark->execute([(int) $row['id']]);
            $sent++;
        } catch (Throwable $e) {
            error_log('[order_cheques_send_due_reminders] ' . $e->getMessage());
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'skipped' => false];
}
