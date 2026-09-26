<?php
declare(strict_types=1);

/**
 * Physical post-dated cheques attached to an order.
 */

require_once __DIR__ . '/jalali.php';
require_once __DIR__ . '/schema-guard.php';

function order_cheques_sync_analytics(PDO $pdo, ?int $chequeId = null): void
{
    if (!function_exists('analytics_orders_sync_cheque_facts')) {
        require_once __DIR__ . '/analytics-orders.php';
    }
    analytics_orders_ensure_schema($pdo);
    if ($chequeId !== null && $chequeId > 0) {
        analytics_orders_upsert_cheque_fact($pdo, $chequeId);
        return;
    }
    analytics_orders_sync_cheque_facts($pdo);
}

function order_cheques_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (cms_schema_guard_done('order-cheques', [__FILE__])) {
        $ready = true;
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

    cms_schema_guard_mark('order-cheques', [__FILE__]);
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
    switch ($result) {
        case 'funded':
            return 'وصول شد';
        case 'bounced':
            return 'برگشت خورد';
        default:
            return 'در انتظار بانک';
    }
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
    if (function_exists('order_cheques_ensure_extended_columns')) {
        // Extended columns are ensured by cheque-workflow when loaded.
    }
    $result = isset($row['bank_result']) ? trim((string) $row['bank_result']) : '';
    if (!in_array($result, ['funded', 'bounced'], true)) {
        $result = '';
    }
    $serial = isset($row['serial']) ? trim((string) $row['serial']) : '';
    $amount = isset($row['amount_text']) ? trim((string) $row['amount_text']) : '';
    $sayadId = isset($row['sayad_id']) ? trim((string) $row['sayad_id']) : '';
    $bankName = isset($row['bank_name']) ? trim((string) $row['bank_name']) : '';
    $issueDate = isset($row['issue_date']) ? trim((string) $row['issue_date']) : '';
    $imagePath = isset($row['image_path']) ? trim((string) $row['image_path']) : '';
    $notes = isset($row['notes']) ? trim((string) $row['notes']) : '';
    $workflowStatus = isset($row['workflow_status']) ? trim((string) $row['workflow_status']) : 'registered';

    $workflowLabels = [];
    if (function_exists('cheque_workflow_status_labels')) {
        $workflowLabels = cheque_workflow_status_labels();
    }
    if (function_exists('cheque_workflow_resolve_status')) {
        $workflowStatus = cheque_workflow_resolve_status($row);
    }

    $imageUrl = null;
    if ($imagePath !== '') {
        $imageUrl = function_exists('cms_upload_url')
            ? cms_upload_url($imagePath)
            : '/uploads/' . ltrim($imagePath, '/');
    }

    return [
        'id' => (int) $row['id'],
        'order_id' => (int) $row['order_id'],
        'received_on' => (string) $row['received_on'],
        'due_on' => (string) $row['due_on'],
        'serial' => $serial !== '' ? $serial : null,
        'sayad_id' => $sayadId !== '' ? $sayadId : null,
        'bank_name' => $bankName !== '' ? $bankName : null,
        'issue_date' => $issueDate !== '' ? $issueDate : null,
        'image_path' => $imagePath !== '' ? $imagePath : null,
        'image_url' => $imageUrl,
        'workflow_status' => $workflowStatus,
        'workflow_status_label' => $workflowLabels[$workflowStatus] ?? $workflowStatus,
        'notes' => $notes !== '' ? $notes : null,
        'verified_by' => isset($row['verified_by']) ? (string) $row['verified_by'] : null,
        'verified_at' => isset($row['verified_at']) && $row['verified_at'] !== null
            ? (string) $row['verified_at']
            : null,
        'amount_text' => $amount !== '' ? $amount : null,
        'bank_result' => $result !== '' ? $result : null,
        'bank_result_label' => order_cheques_result_label($result !== '' ? $result : null),
        'reminder_sent_at' => isset($row['reminder_sent_at']) && $row['reminder_sent_at'] !== null
            ? (string) $row['reminder_sent_at']
            : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function order_cheques_registered_total_toman(array $cheques): int
{
    if (!function_exists('invoices_parse_toman_amount')) {
        require_once __DIR__ . '/invoices.php';
    }
    $total = 0;
    foreach ($cheques as $cheque) {
        $amountText = isset($cheque['amount_text']) ? (string) $cheque['amount_text'] : '';
        $parsed = invoices_parse_toman_amount($amountText);
        if ($parsed !== null && $parsed > 0) {
            $total += $parsed;
        }
    }

    return $total;
}

function order_cheques_remaining_toman(int $orderTotalToman, array $cheques): int
{
    if ($orderTotalToman <= 0) {
        return 0;
    }

    return max(0, $orderTotalToman - order_cheques_registered_total_toman($cheques));
}

function order_cheques_format_toman_label(int $amount): string
{
    if ($amount <= 0) {
        return '—';
    }
    if (function_exists('cms_to_persian_digits')) {
        return cms_to_persian_digits(number_format($amount)) . ' تومان';
    }

    return number_format($amount) . ' تومان';
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
    string $smsText,
    ?int $chequeId = null
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
        orders_admin_notify_sales_client($pdo, $orderId, $notifyType, $eventMessage, $chequeId);
    } elseif (function_exists('sales_push_notify_status_change')) {
        try {
            sales_push_notify_status_change($pdo, $orderId, $notifyType, $eventMessage, $chequeId);
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

    switch ($notifyType) {
        case 'cheque_received':
            return 'چک سفارش ' . $code . $serialBit . ' دریافت شد. سررسید: ' . $due;
        case 'cheque_due_soon':
            return 'یادآوری: سررسید چک سفارش ' . $code . $serialBit . ' دو روز دیگر است (' . $due . ').';
        case 'cheque_funded':
            return 'چک سفارش ' . $code . $serialBit . ' در بانک وصول شد.';
        case 'cheque_bounced':
            return 'چک سفارش ' . $code . $serialBit . ' در بانک برگشت خورد.';
        default:
            return 'به‌روزرسانی چک سفارش ' . $code;
    }
}

/**
 * @param array<string, mixed> $input
 */
function order_cheques_add(PDO $pdo, array $order, array $input): array
{
    order_cheques_ensure_schema($pdo);
    if (function_exists('order_cheques_ensure_extended_columns')) {
        require_once __DIR__ . '/cheque-workflow.php';
        order_cheques_ensure_extended_columns($pdo);
    }
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
    $sayadId = trim((string) ($input['sayad_id'] ?? ''));
    $bankName = trim((string) ($input['bank_name'] ?? ''));
    $issueDateRaw = trim((string) ($input['issue_date'] ?? ''));
    $issueDate = $issueDateRaw !== '' ? order_cheques_normalize_date($issueDateRaw, 'تاریخ صدور') : null;
    $imagePath = trim((string) ($input['image_path'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    if (function_exists('mb_substr')) {
        $serial = mb_substr($serial, 0, 64);
        $amount = mb_substr($amount, 0, 128);
        $sayadId = mb_substr($sayadId, 0, 64);
        $bankName = mb_substr($bankName, 0, 128);
        $imagePath = mb_substr($imagePath, 0, 512);
    } else {
        $serial = substr($serial, 0, 64);
        $amount = substr($amount, 0, 128);
        $sayadId = substr($sayadId, 0, 64);
        $bankName = substr($bankName, 0, 128);
        $imagePath = substr($imagePath, 0, 512);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO order_cheques
         (order_id, received_on, due_on, serial, sayad_id, bank_name, issue_date, image_path,
          workflow_status, notes, amount_text)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $orderId,
        $receivedOn,
        $dueOn,
        $serial !== '' ? $serial : null,
        $sayadId !== '' ? $sayadId : null,
        $bankName !== '' ? $bankName : null,
        $issueDate,
        $imagePath !== '' ? $imagePath : null,
        'registered',
        $notes !== '' ? $notes : null,
        $amount !== '' ? $amount : null,
    ]);
    $chequeId = (int) $pdo->lastInsertId();
    order_cheques_sync_analytics($pdo, $chequeId > 0 ? $chequeId : null);

    $publicCode = (string) ($order['public_code'] ?? '');
    $sms = order_cheques_sms_body('cheque_received', $publicCode, $dueOn, $serial);
    order_cheques_notify($pdo, $order, 'cheque_received', $sms, $sms, $chequeId > 0 ? $chequeId : null);

    return ['message' => 'چک ثبت شد و اطلاع‌رسانی ارسال شد'];
}

/**
 * @param array<string, mixed> $input
 */
function order_cheques_update(PDO $pdo, array $order, array $input): array
{
    order_cheques_ensure_schema($pdo);
    if (function_exists('order_cheques_ensure_extended_columns')) {
        require_once __DIR__ . '/cheque-workflow.php';
        order_cheques_ensure_extended_columns($pdo);
    }
    $orderId = (int) ($order['id'] ?? 0);
    $chequeId = (int) ($input['id'] ?? 0);
    if ($orderId <= 0 || $chequeId <= 0) {
        throw new RuntimeException('سفارش نامعتبر');
    }
    $row = order_cheques_get($pdo, $chequeId, $orderId);
    if ($row === null) {
        throw new RuntimeException('چک یافت نشد');
    }

    $receivedOn = order_cheques_normalize_date(
        (string) ($input['received_on'] ?? date('Y-m-d')),
        'تاریخ دریافت'
    );
    $dueOn = order_cheques_normalize_date((string) ($input['due_on'] ?? ''), 'سررسید چک');
    $serial = trim((string) ($input['serial'] ?? ''));
    $amount = trim((string) ($input['amount_text'] ?? ''));
    $sayadId = trim((string) ($input['sayad_id'] ?? ''));
    $bankName = trim((string) ($input['bank_name'] ?? ''));
    $issueDateRaw = trim((string) ($input['issue_date'] ?? ''));
    $issueDate = $issueDateRaw !== '' ? order_cheques_normalize_date($issueDateRaw, 'تاریخ صدور') : null;
    $imagePath = trim((string) ($input['image_path'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));
    if (function_exists('mb_substr')) {
        $serial = mb_substr($serial, 0, 64);
        $amount = mb_substr($amount, 0, 128);
        $sayadId = mb_substr($sayadId, 0, 64);
        $bankName = mb_substr($bankName, 0, 128);
        $imagePath = mb_substr($imagePath, 0, 512);
    } else {
        $serial = substr($serial, 0, 64);
        $amount = substr($amount, 0, 128);
        $sayadId = substr($sayadId, 0, 64);
        $bankName = substr($bankName, 0, 128);
        $imagePath = substr($imagePath, 0, 512);
    }

    $prevDue = (string) ($row['due_on'] ?? '');
    $sql = 'UPDATE order_cheques SET received_on = ?, due_on = ?, serial = ?, sayad_id = ?,
            bank_name = ?, issue_date = ?, image_path = ?, notes = ?, amount_text = ?';
    $params = [
        $receivedOn,
        $dueOn,
        $serial !== '' ? $serial : null,
        $sayadId !== '' ? $sayadId : null,
        $bankName !== '' ? $bankName : null,
        $issueDate,
        $imagePath !== '' ? $imagePath : null,
        $notes !== '' ? $notes : null,
        $amount !== '' ? $amount : null,
    ];
    if ($dueOn !== $prevDue) {
        $sql .= ', reminder_sent_at = NULL';
    }
    $sql .= ' WHERE id = ? AND order_id = ?';
    $params[] = $chequeId;
    $params[] = $orderId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    order_cheques_sync_analytics($pdo, $chequeId);

    return ['message' => 'چک به‌روز شد'];
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
    order_cheques_sync_analytics($pdo, $chequeId);

    $notifyType = $result === 'funded' ? 'cheque_funded' : 'cheque_bounced';
    $serial = trim((string) ($row['serial'] ?? ''));
    $dueOn = (string) ($row['due_on'] ?? '');
    $publicCode = (string) ($order['public_code'] ?? '');
    $sms = order_cheques_sms_body($notifyType, $publicCode, $dueOn, $serial);
    order_cheques_notify($pdo, $order, $notifyType, $sms, $sms, $chequeId);

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
    $pdo->prepare('DELETE FROM analytics_order_cheque_facts WHERE cheque_id = ?')
        ->execute([$chequeId]);

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

function order_cheques_reminder_log_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cheque_reminder_log (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          cheque_id INT UNSIGNED NOT NULL,
          rule_key VARCHAR(32) NOT NULL,
          sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_cheque_rule (cheque_id, rule_key),
          KEY idx_crl_sent (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

/** @return list<int> */
function order_cheques_reminder_offsets(): array
{
    $default = [7, 3, 1, 0, -1, -3];
    if (!function_exists('cms_setting_get')) {
        return $default;
    }
    $raw = cms_setting_get('cheque_reminder_offsets');
    if ($raw === null || trim($raw) === '') {
        return $default;
    }
    $parts = preg_split('/[,\s]+/', trim($raw)) ?: [];
    $offsets = [];
    foreach ($parts as $part) {
        if ($part === '' || !is_numeric($part)) {
            continue;
        }
        $offsets[] = (int) $part;
    }

    return $offsets !== [] ? array_values(array_unique($offsets)) : $default;
}

function order_cheques_reminder_rule_key(int $offsetDays): string
{
    if ($offsetDays > 0) {
        return 'before_' . $offsetDays . 'd';
    }
    if ($offsetDays === 0) {
        return 'due_day';
    }

    return 'overdue_' . abs($offsetDays) . 'd';
}

function order_cheques_reminder_already_sent(PDO $pdo, int $chequeId, string $ruleKey): bool
{
    order_cheques_reminder_log_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT 1 FROM cheque_reminder_log WHERE cheque_id = ? AND rule_key = ? LIMIT 1'
    );
    $stmt->execute([$chequeId, $ruleKey]);

    return (bool) $stmt->fetchColumn();
}

function order_cheques_reminder_mark_sent(PDO $pdo, int $chequeId, string $ruleKey): void
{
    order_cheques_reminder_log_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO cheque_reminder_log (cheque_id, rule_key) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE sent_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$chequeId, $ruleKey]);
}

/**
 * @return array{sent: int, failed: int, skipped: bool, reason?: string}
 */
function order_cheques_send_due_reminders(PDO $pdo, bool $forceWindow = false): array
{
    order_cheques_ensure_schema($pdo);
    order_cheques_reminder_log_ensure_schema($pdo);
    if (!$forceWindow && !order_cheques_in_reminder_window()) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => true, 'reason' => 'outside_window'];
    }

    $sent = 0;
    $failed = 0;
    foreach (order_cheques_reminder_offsets() as $offsetDays) {
        $ruleKey = order_cheques_reminder_rule_key($offsetDays);
        if ($offsetDays >= 0) {
            $dueCondition = 'c.due_on = DATE_ADD(CURDATE(), INTERVAL ' . (int) $offsetDays . ' DAY)';
        } else {
            $dueCondition = 'c.due_on = DATE_SUB(CURDATE(), INTERVAL ' . abs($offsetDays) . ' DAY)';
        }
        $sql = "SELECT c.*, o.public_code, o.phone, o.branch_phone, o.sales_user_id, o.status, o.id AS order_pk
                FROM order_cheques c
                INNER JOIN orders o ON o.id = c.order_id
                WHERE c.bank_result IS NULL
                  AND {$dueCondition}
                ORDER BY c.id ASC
                LIMIT 80";
        $stmt = $pdo->query($sql);
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        foreach ($rows as $row) {
            $chequeId = (int) ($row['id'] ?? 0);
            if ($chequeId <= 0 || order_cheques_reminder_already_sent($pdo, $chequeId, $ruleKey)) {
                continue;
            }
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
            $notifyType = $offsetDays < 0 ? 'cheque_overdue' : 'cheque_due_soon';
            $sms = order_cheques_sms_body('cheque_due_soon', $order['public_code'], $dueOn, $serial);
            try {
                order_cheques_notify($pdo, $order, $notifyType, $sms, $sms, $chequeId);
                order_cheques_reminder_mark_sent($pdo, $chequeId, $ruleKey);
                $sent++;
            } catch (Throwable $e) {
                error_log('[order_cheques_send_due_reminders] ' . $e->getMessage());
                $failed++;
            }
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'skipped' => false];
}
