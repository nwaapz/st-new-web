<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-push.php';

function sales_push_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sales_push_tokens (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          sales_user_id INT UNSIGNED NOT NULL,
          fcm_token VARCHAR(512) NOT NULL,
          platform VARCHAR(32) NOT NULL DEFAULT \'android\',
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_sales_token (sales_user_id, fcm_token),
          KEY idx_sales_fcm_token (fcm_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function sales_push_register_token(PDO $pdo, int $salesUserId, string $token, string $platform = 'android'): void
{
    sales_push_ensure_schema($pdo);
    $token = trim($token);
    if ($salesUserId <= 0 || $token === '') {
        throw new InvalidArgumentException('توکن نامعتبر است');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO sales_push_tokens (sales_user_id, fcm_token, platform)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE platform = VALUES(platform), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$salesUserId, $token, $platform !== '' ? $platform : 'android']);
}

function sales_push_unregister_token(PDO $pdo, int $salesUserId, string $token): void
{
    sales_push_ensure_schema($pdo);
    $token = trim($token);
    if ($salesUserId <= 0 || $token === '') {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM sales_push_tokens WHERE sales_user_id = ? AND fcm_token = ?');
    $stmt->execute([$salesUserId, $token]);
}

function sales_push_unregister_all_for_user(PDO $pdo, int $salesUserId): void
{
    sales_push_ensure_schema($pdo);
    if ($salesUserId <= 0) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM sales_push_tokens WHERE sales_user_id = ?');
    $stmt->execute([$salesUserId]);
}

/**
 * @return list<string>
 */
function sales_push_tokens_for_user(PDO $pdo, int $salesUserId): array
{
    sales_push_ensure_schema($pdo);
    if ($salesUserId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT DISTINCT fcm_token FROM sales_push_tokens WHERE sales_user_id = ?');
    $stmt->execute([$salesUserId]);
    $rows = $stmt->fetchAll() ?: [];
    $tokens = [];
    foreach ($rows as $row) {
        $token = trim((string) ($row['fcm_token'] ?? ''));
        if ($token !== '') {
            $tokens[] = $token;
        }
    }
    return $tokens;
}

/**
 * @return array{title: string, body: string}
 */
function sales_push_message_for_status(string $publicCode, string $notifyType, string $message = ''): array
{
    $labels = orders_status_labels();
    $code = $publicCode !== '' ? $publicCode : '—';

    switch ($notifyType) {
        case 'accepted':
            return [
                'title' => 'تأیید انبار',
                'body' => 'سفارش ' . $code . ' تأیید شد. پیش‌فاکتور آماده است.',
            ];
        case 'rejected':
            return [
                'title' => 'رد انبار',
                'body' => 'سفارش ' . $code . ' رد شد.' . ($message !== '' ? ' ' . $message : ''),
            ];
        case 'cancelled':
            return [
                'title' => 'لغو سفارش',
                'body' => 'سفارش ' . $code . ' لغو شد.' . ($message !== '' ? ' ' . $message : ''),
            ];
        case 'warn_payment':
            return [
                'title' => 'نقص مدارک پرداخت',
                'body' => 'سفارش ' . $code . ': ' . ($message !== '' ? $message : 'لطفاً مدارک را اصلاح کنید.'),
            ];
        case 'paid':
            return [
                'title' => 'پرداخت تأیید شد',
                'body' => 'سفارش ' . $code . ' پرداخت شد.',
            ];
        case 'shipped':
            return [
                'title' => 'ارسال مرسوله',
                'body' => 'سفارش ' . $code . ' ارسال شد.',
            ];
        case 'received':
            return [
                'title' => 'تحویل شد',
                'body' => 'سفارش ' . $code . ' دریافت شد — تمام.',
            ];
        case 'not_received':
        case 'returned_to_origin':
        case 'lost':
            return [
                'title' => 'پیگیری مرسوله',
                'body' => 'سفارش ' . $code . ': ' . ($labels[$notifyType] ?? $notifyType),
            ];
        case 'cheque_due_soon':
            return [
                'title' => 'یادآوری سررسید چک',
                'body' => $message !== '' ? $message : ('سررسید چک سفارش ' . $code . ' نزدیک است.'),
            ];
        case 'cheque_overdue':
            return [
                'title' => 'چک سررسید گذشته',
                'body' => $message !== '' ? $message : ('چک سفارش ' . $code . ' سررسید گذشته است.'),
            ];
        case 'cheque_received':
            return [
                'title' => 'دریافت چک',
                'body' => $message !== '' ? $message : ('چک سفارش ' . $code . ' دریافت شد.'),
            ];
        case 'cheque_funded':
            return [
                'title' => 'وصول چک',
                'body' => $message !== '' ? $message : ('چک سفارش ' . $code . ' وصول شد.'),
            ];
        case 'cheque_bounced':
            return [
                'title' => 'برگشت چک',
                'body' => $message !== '' ? $message : ('چک سفارش ' . $code . ' برگشت خورد.'),
            ];
        default:
            return [
                'title' => 'به‌روزرسانی سفارش',
                'body' => 'سفارش ' . $code . ' به‌روز شد.',
            ];
    }
}

function sales_push_notify_status_change(
    PDO $pdo,
    int $orderId,
    string $notifyType,
    string $message = '',
    ?int $chequeId = null
): void {
    if ($orderId <= 0) {
        return;
    }
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        return;
    }
    $salesUserId = isset($order['sales_user_id']) && $order['sales_user_id'] !== null
        ? (int) $order['sales_user_id']
        : 0;
    if ($salesUserId <= 0) {
        return;
    }

    $publicCode = (string) ($order['public_code'] ?? '');
    $copy = sales_push_message_for_status($publicCode, $notifyType, $message);
    $chequeTypes = [
        'cheque_received',
        'cheque_due_soon',
        'cheque_overdue',
        'cheque_funded',
        'cheque_bounced',
    ];
    $data = [
        'order_id' => (string) $orderId,
        'type' => in_array($notifyType, $chequeTypes, true) ? $notifyType : 'order_status',
        'to_status' => $notifyType,
        'public_code' => $publicCode,
    ];
    if ($chequeId !== null && $chequeId > 0) {
        $data['cheque_id'] = (string) $chequeId;
    }

    $tokens = sales_push_tokens_for_user($pdo, $salesUserId);
    foreach ($tokens as $token) {
        admin_push_send_to_token($token, $copy['title'], $copy['body'], $data);
    }

    if (in_array($notifyType, $chequeTypes, true) && function_exists('admin_push_all_tokens')) {
        require_once __DIR__ . '/admin-push.php';
        foreach (admin_push_all_tokens($pdo) as $adminToken) {
            admin_push_send_to_token($adminToken, $copy['title'], $copy['body'], $data);
        }
    }
}

function admin_push_notify_payment_proof(PDO $pdo, int $orderId, string $activityType = 'payment_proof'): void
{
    admin_push_notify_order_activity($pdo, $orderId, $activityType);
}

function orders_admin_notify_sales_client(PDO $pdo, int $orderId, string $notifyType, string $message = '', ?int $chequeId = null): void
{
    if (!function_exists('sales_push_notify_status_change')) {
        $lib = __DIR__ . '/sales-push.php';
        if (is_readable($lib)) {
            require_once $lib;
        }
    }
    if (!function_exists('sales_push_notify_status_change')) {
        return;
    }
    try {
        sales_push_notify_status_change($pdo, $orderId, $notifyType, $message, $chequeId);
    } catch (Throwable $e) {
        error_log('[orders_admin_notify_sales_client] ' . $e->getMessage());
    }
}

function sales_push_notify_order_message(PDO $pdo, int $orderId, string $senderName, string $body): void
{
    if ($orderId <= 0) {
        return;
    }
    if (!function_exists('orders_get_by_id')) {
        require_once __DIR__ . '/orders.php';
    }
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        return;
    }
    $salesUserId = isset($order['sales_user_id']) && $order['sales_user_id'] !== null
        ? (int) $order['sales_user_id']
        : 0;
    if ($salesUserId <= 0) {
        return;
    }

    $publicCode = (string) ($order['public_code'] ?? '');
    $preview = mb_strlen($body) > 120 ? mb_substr($body, 0, 117) . '…' : $body;
    $data = [
        'order_id' => (string) $orderId,
        'type' => 'order_chat',
        'public_code' => $publicCode,
    ];

    $tokens = sales_push_tokens_for_user($pdo, $salesUserId);
    foreach ($tokens as $token) {
        admin_push_send_to_token(
            $token,
            'پیام سفارش ' . $publicCode,
            $senderName . ': ' . $preview,
            $data
        );
    }
}
