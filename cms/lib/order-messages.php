<?php
declare(strict_types=1);

require_once __DIR__ . '/orders.php';

function order_messages_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_messages (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          order_id INT UNSIGNED NOT NULL,
          actor ENUM(\'admin\',\'sales\') NOT NULL,
          admin_user_id INT UNSIGNED NULL,
          sales_user_id INT UNSIGNED NULL,
          admin_name VARCHAR(64) NOT NULL DEFAULT \'\',
          sales_name VARCHAR(191) NOT NULL DEFAULT \'\',
          body TEXT NOT NULL,
          admin_read_at TIMESTAMP NULL DEFAULT NULL,
          sales_read_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_order_messages_order (order_id, id),
          CONSTRAINT fk_order_messages_order
            FOREIGN KEY (order_id) REFERENCES orders (id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

/**
 * @return list<array<string, mixed>>
 */
function order_messages_fetch(PDO $pdo, int $orderId, int $sinceId = 0): array
{
    order_messages_ensure_schema($pdo);
    if ($orderId <= 0) {
        return [];
    }

    if ($sinceId > 0) {
        $stmt = $pdo->prepare(
            'SELECT * FROM order_messages
             WHERE order_id = ? AND id > ?
             ORDER BY id ASC'
        );
        $stmt->execute([$orderId, $sinceId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM order_messages
             WHERE order_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$orderId]);
    }

    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = order_messages_serialize($row);
    }

    return $items;
}

/**
 * Long-poll helper (WebSocket alternative on PHP hosting).
 *
 * @return list<array<string, mixed>>
 */
function order_messages_wait(PDO $pdo, int $orderId, int $sinceId, int $timeoutSec = 25): array
{
    $timeoutSec = max(1, min($timeoutSec, 30));
    $deadline = time() + $timeoutSec;
    while (time() < $deadline) {
        $items = order_messages_fetch($pdo, $orderId, $sinceId);
        if ($items !== []) {
            return $items;
        }
        usleep(500000);
    }

    return [];
}

function order_messages_mark_read_for_admin(PDO $pdo, int $orderId): void
{
    order_messages_ensure_schema($pdo);
    $pdo->prepare(
        "UPDATE order_messages
         SET admin_read_at = CURRENT_TIMESTAMP
         WHERE order_id = ? AND actor = 'sales' AND admin_read_at IS NULL"
    )->execute([$orderId]);
}

function order_messages_mark_read_for_sales(PDO $pdo, int $orderId): void
{
    order_messages_ensure_schema($pdo);
    $pdo->prepare(
        "UPDATE order_messages
         SET sales_read_at = CURRENT_TIMESTAMP
         WHERE order_id = ? AND actor = 'admin' AND sales_read_at IS NULL"
    )->execute([$orderId]);
}

/**
 * @return array<string, mixed>
 */
function order_messages_post_admin(PDO $pdo, int $orderId, int $adminUserId, string $adminName, string $body): array
{
    order_messages_ensure_schema($pdo);
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        throw new InvalidArgumentException('سفارش یافت نشد');
    }
    if (!orders_chat_send_allowed((string) $order['status'])) {
        throw new InvalidArgumentException('این سفارش بسته شده — ارسال پیام جدید مجاز نیست');
    }
    $body = trim($body);
    if ($body === '') {
        throw new InvalidArgumentException('متن پیام الزامی است');
    }
    if (mb_strlen($body) > 4000) {
        throw new InvalidArgumentException('پیام خیلی طولانی است');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO order_messages
         (order_id, actor, admin_user_id, admin_name, body, admin_read_at)
         VALUES (?, \'admin\', ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([$orderId, $adminUserId, $adminName, $body]);
    $id = (int) $pdo->lastInsertId();

    $row = $pdo->prepare('SELECT * FROM order_messages WHERE id = ? LIMIT 1');
    $row->execute([$id]);
    $message = $row->fetch();
    if (!$message) {
        throw new RuntimeException('ثبت پیام ناموفق بود');
    }

    order_messages_notify_sales($pdo, $orderId, $adminName, $body);

    return order_messages_serialize($message);
}

/**
 * @return array<string, mixed>
 */
function order_messages_post_sales(PDO $pdo, int $orderId, int $salesUserId, string $salesName, string $body): array
{
    order_messages_ensure_schema($pdo);
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        throw new InvalidArgumentException('سفارش یافت نشد');
    }
    if (!orders_chat_send_allowed((string) $order['status'])) {
        throw new InvalidArgumentException('این سفارش بسته شده — ارسال پیام جدید مجاز نیست');
    }
    $body = trim($body);
    if ($body === '') {
        throw new InvalidArgumentException('متن پیام الزامی است');
    }
    if (mb_strlen($body) > 4000) {
        throw new InvalidArgumentException('پیام خیلی طولانی است');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO order_messages
         (order_id, actor, sales_user_id, sales_name, body, sales_read_at)
         VALUES (?, \'sales\', ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([$orderId, $salesUserId, $salesName, $body]);
    $id = (int) $pdo->lastInsertId();

    $row = $pdo->prepare('SELECT * FROM order_messages WHERE id = ? LIMIT 1');
    $row->execute([$id]);
    $message = $row->fetch();
    if (!$message) {
        throw new RuntimeException('ثبت پیام ناموفق بود');
    }

    order_messages_notify_admins($pdo, $orderId, $salesName, $body);

    return order_messages_serialize($message);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function order_messages_serialize(array $row): array
{
    $actor = (string) ($row['actor'] ?? '');
    $senderName = $actor === 'admin'
        ? (string) ($row['admin_name'] ?? '')
        : (string) ($row['sales_name'] ?? '');

    return [
        'id' => (int) ($row['id'] ?? 0),
        'order_id' => (int) ($row['order_id'] ?? 0),
        'actor' => $actor,
        'sender_name' => $senderName,
        'body' => (string) ($row['body'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function order_messages_verify_sales_order(PDO $pdo, int $orderId, int $salesUserId): ?array
{
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        return null;
    }
    $owner = isset($order['sales_user_id']) && $order['sales_user_id'] !== null
        ? (int) $order['sales_user_id']
        : 0;
    if ($owner !== $salesUserId) {
        return null;
    }

    return $order;
}

function order_messages_unread_count_for_admin(PDO $pdo, int $orderId): int
{
    order_messages_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM order_messages
         WHERE order_id = ? AND actor = 'sales' AND admin_read_at IS NULL"
    );
    $stmt->execute([$orderId]);

    return (int) $stmt->fetchColumn();
}

function order_messages_unread_count_for_sales(PDO $pdo, int $orderId): int
{
    order_messages_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM order_messages
         WHERE order_id = ? AND actor = 'admin' AND sales_read_at IS NULL"
    );
    $stmt->execute([$orderId]);

    return (int) $stmt->fetchColumn();
}

function order_messages_notify_sales(PDO $pdo, int $orderId, string $senderName, string $body): void
{
    if (!function_exists('sales_push_notify_order_message')) {
        require_once __DIR__ . '/sales-push.php';
    }
    if (function_exists('sales_push_notify_order_message')) {
        sales_push_notify_order_message($pdo, $orderId, $senderName, $body);
    }
}

function order_messages_notify_admins(PDO $pdo, int $orderId, string $senderName, string $body): void
{
    if (!function_exists('admin_push_notify_order_message')) {
        require_once __DIR__ . '/admin-push.php';
    }
    if (function_exists('admin_push_notify_order_message')) {
        admin_push_notify_order_message($pdo, $orderId, $senderName, $body);
    }
}
