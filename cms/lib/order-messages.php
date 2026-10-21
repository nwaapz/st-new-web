<?php
declare(strict_types=1);

require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/schema-guard.php';

function order_messages_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (cms_schema_guard_done('order-messages', [__FILE__])) {
        $ready = true;
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_messages (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          order_id INT UNSIGNED NOT NULL,
          actor ENUM(\'admin\',\'sales\',\'client\') NOT NULL,
          admin_user_id INT UNSIGNED NULL,
          sales_user_id INT UNSIGNED NULL,
          client_user_id INT UNSIGNED NULL,
          admin_name VARCHAR(64) NOT NULL DEFAULT \'\',
          sales_name VARCHAR(191) NOT NULL DEFAULT \'\',
          client_name VARCHAR(191) NOT NULL DEFAULT \'\',
          body TEXT NOT NULL,
          admin_read_at TIMESTAMP NULL DEFAULT NULL,
          sales_read_at TIMESTAMP NULL DEFAULT NULL,
          client_read_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_order_messages_order (order_id, id),
          CONSTRAINT fk_order_messages_order
            FOREIGN KEY (order_id) REFERENCES orders (id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    try {
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM order_messages');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cols[(string) $row['Field']] = true;
        }
        if (!isset($cols['client_user_id'])) {
            $pdo->exec('ALTER TABLE order_messages ADD COLUMN client_user_id INT UNSIGNED NULL AFTER sales_user_id');
        }
        if (!isset($cols['client_name'])) {
            $pdo->exec(
                "ALTER TABLE order_messages ADD COLUMN client_name VARCHAR(191) NOT NULL DEFAULT '' AFTER sales_name"
            );
        }
        if (!isset($cols['client_read_at'])) {
            $pdo->exec(
                'ALTER TABLE order_messages ADD COLUMN client_read_at TIMESTAMP NULL DEFAULT NULL AFTER sales_read_at'
            );
        }
        if (!isset($cols['image'])) {
            $pdo->exec(
                "ALTER TABLE order_messages ADD COLUMN image VARCHAR(512) NULL DEFAULT NULL AFTER body"
            );
        }
        $actorType = '';
        $typeStmt = $pdo->query("SHOW COLUMNS FROM order_messages LIKE 'actor'");
        $typeRow = $typeStmt ? $typeStmt->fetch() : false;
        if (is_array($typeRow)) {
            $actorType = strtolower((string) ($typeRow['Type'] ?? ''));
        }
        if ($actorType !== '' && strpos($actorType, 'client') === false) {
            $pdo->exec(
                "ALTER TABLE order_messages MODIFY actor ENUM('admin','sales','client') NOT NULL"
            );
        }
    } catch (Throwable $e) {
        error_log('[order-messages] schema migration: ' . $e->getMessage());
    }

    cms_schema_guard_mark('order-messages', [__FILE__]);
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
         WHERE order_id = ? AND actor IN ('sales', 'client') AND admin_read_at IS NULL"
    )->execute([$orderId]);
}

function order_messages_mark_read_for_client(PDO $pdo, int $orderId): void
{
    order_messages_ensure_schema($pdo);
    $pdo->prepare(
        "UPDATE order_messages
         SET client_read_at = CURRENT_TIMESTAMP
         WHERE order_id = ? AND actor = 'admin' AND client_read_at IS NULL"
    )->execute([$orderId]);
}

function order_messages_upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'حجم تصویر بیش از حد مجاز است';
        case UPLOAD_ERR_PARTIAL:
            return 'آپلود تصویر ناقص بود';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'پوشه موقت سرور در دسترس نیست';
        case UPLOAD_ERR_CANT_WRITE:
            return 'ذخیره تصویر روی سرور ممکن نیست';
        case UPLOAD_ERR_EXTENSION:
            return 'آپلود تصویر توسط سرور مسدود شد';
        default:
            return 'آپلود تصویر ناموفق بود';
    }
}

/**
 * @return array{ext: string, mime: string}|null
 */
function order_messages_resolve_image_type(string $tmpPath, string $clientType, string $originalName): ?array
{
    $map = [
        'image/jpeg' => '.jpg',
        'image/jpg' => '.jpg',
        'image/pjpeg' => '.jpg',
        'image/png' => '.png',
        'image/x-png' => '.png',
        'image/webp' => '.webp',
    ];

    $mime = '';
    if ($tmpPath !== '' && is_file($tmpPath) && class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower($finfo->file($tmpPath) ?: '');
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $clientType = strtolower(trim($clientType));
        if (isset($map[$clientType])) {
            $mime = $clientType === 'image/jpg' ? 'image/jpeg' : $clientType;
        }
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $mime = 'image/jpeg';
                break;
            case 'png':
                $mime = 'image/png';
                break;
            case 'webp':
                $mime = 'image/webp';
                break;
            default:
                $mime = '';
                break;
        }
    }
    if ($mime === 'image/jpg' || $mime === 'image/pjpeg') {
        $mime = 'image/jpeg';
    }
    if ($mime === '' || !isset($map[$mime])) {
        return null;
    }

    return ['ext' => $map[$mime], 'mime' => $mime];
}

function order_messages_handle_image_upload(string $field = 'image'): ?string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $error = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(order_messages_upload_error_message($error));
    }
    $tmp = (string) ($_FILES[$field]['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('فایل آپلود معتبر نیست');
    }

    $resolved = order_messages_resolve_image_type(
        $tmp,
        (string) ($_FILES[$field]['type'] ?? ''),
        (string) ($_FILES[$field]['name'] ?? ''),
    );
    if ($resolved === null) {
        throw new InvalidArgumentException('فقط JPEG/PNG/WebP مجاز است');
    }
    if ((int) ($_FILES[$field]['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('حداکثر حجم تصویر ۵ مگابایت است');
    }

    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'order-chat';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('ساخت پوشه آپلود ممکن نیست');
    }

    $name = 'order-chat-' . bin2hex(random_bytes(8)) . $resolved['ext'];
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('ذخیره تصویر ناموفق بود');
    }

    return '/uploads/order-chat/' . $name;
}

function order_messages_notify_preview(string $body, ?string $image): string
{
    $body = trim($body);
    if ($body !== '') {
        return $body;
    }
    if ($image !== null && trim($image) !== '') {
        return 'تصویر';
    }

    return '';
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
function order_messages_post_admin(
    PDO $pdo,
    int $orderId,
    int $adminUserId,
    string $adminName,
    string $body,
    ?string $image = null,
    bool $notify = true
): array {
    order_messages_ensure_schema($pdo);
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        throw new InvalidArgumentException('سفارش یافت نشد');
    }
    if (!orders_chat_send_allowed((string) $order['status'])) {
        throw new InvalidArgumentException('این سفارش بسته شده — ارسال پیام جدید مجاز نیست');
    }
    $body = trim($body);
    $image = $image !== null ? trim($image) : '';
    if ($body === '' && $image === '') {
        throw new InvalidArgumentException('متن یا تصویر پیام الزامی است');
    }
    if (mb_strlen($body) > 4000) {
        throw new InvalidArgumentException('پیام خیلی طولانی است');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO order_messages
         (order_id, actor, admin_user_id, admin_name, body, image, admin_read_at)
         VALUES (?, \'admin\', ?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([$orderId, $adminUserId, $adminName, $body, $image !== '' ? $image : null]);
    $id = (int) $pdo->lastInsertId();

    $row = $pdo->prepare('SELECT * FROM order_messages WHERE id = ? LIMIT 1');
    $row->execute([$id]);
    $message = $row->fetch();
    if (!$message) {
        throw new RuntimeException('ثبت پیام ناموفق بود');
    }

    if ($notify) {
        order_messages_notify_sales(
            $pdo,
            $orderId,
            $adminName,
            order_messages_notify_preview($body, $image !== '' ? $image : null)
        );
    }

    return order_messages_serialize($message);
}

/**
 * @return array<string, mixed>
 */
function order_messages_post_sales(
    PDO $pdo,
    int $orderId,
    int $salesUserId,
    string $salesName,
    string $body,
    ?string $image = null,
    bool $notify = true
): array {
    order_messages_ensure_schema($pdo);
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        throw new InvalidArgumentException('سفارش یافت نشد');
    }
    if (!orders_chat_send_allowed((string) $order['status'])) {
        throw new InvalidArgumentException('این سفارش بسته شده — ارسال پیام جدید مجاز نیست');
    }
    $body = trim($body);
    $image = $image !== null ? trim($image) : '';
    if ($body === '' && $image === '') {
        throw new InvalidArgumentException('متن یا تصویر پیام الزامی است');
    }
    if (mb_strlen($body) > 4000) {
        throw new InvalidArgumentException('پیام خیلی طولانی است');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO order_messages
         (order_id, actor, sales_user_id, sales_name, body, image, sales_read_at)
         VALUES (?, \'sales\', ?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([$orderId, $salesUserId, $salesName, $body, $image !== '' ? $image : null]);
    $id = (int) $pdo->lastInsertId();

    $row = $pdo->prepare('SELECT * FROM order_messages WHERE id = ? LIMIT 1');
    $row->execute([$id]);
    $message = $row->fetch();
    if (!$message) {
        throw new RuntimeException('ثبت پیام ناموفق بود');
    }

    if ($notify) {
        order_messages_notify_admins(
            $pdo,
            $orderId,
            $salesName,
            order_messages_notify_preview($body, $image !== '' ? $image : null)
        );
    }

    return order_messages_serialize($message);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function order_messages_verify_client_order(PDO $pdo, int $orderId, int $userId): ?array
{
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        return null;
    }
    if ((int) ($order['user_id'] ?? 0) !== $userId) {
        return null;
    }
    if (isset($order['sales_user_id']) && $order['sales_user_id'] !== null && (int) $order['sales_user_id'] > 0) {
        return null;
    }

    return $order;
}

/**
 * @return array<string, mixed>
 */
function order_messages_post_client(
    PDO $pdo,
    int $orderId,
    int $userId,
    string $clientName,
    string $body,
    ?string $image = null,
    bool $notify = true
): array {
    order_messages_ensure_schema($pdo);
    $order = order_messages_verify_client_order($pdo, $orderId, $userId);
    if ($order === null) {
        throw new InvalidArgumentException('سفارش یافت نشد');
    }
    if (!orders_chat_send_allowed((string) $order['status'])) {
        throw new InvalidArgumentException('این سفارش بسته شده — ارسال پیام جدید مجاز نیست');
    }
    $body = trim($body);
    $image = $image !== null ? trim($image) : '';
    if ($body === '' && $image === '') {
        throw new InvalidArgumentException('متن یا تصویر پیام الزامی است');
    }
    if (mb_strlen($body) > 4000) {
        throw new InvalidArgumentException('پیام خیلی طولانی است');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO order_messages
         (order_id, actor, client_user_id, client_name, body, image, client_read_at)
         VALUES (?, \'client\', ?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([$orderId, $userId, $clientName, $body, $image !== '' ? $image : null]);
    $id = (int) $pdo->lastInsertId();

    $row = $pdo->prepare('SELECT * FROM order_messages WHERE id = ? LIMIT 1');
    $row->execute([$id]);
    $message = $row->fetch();
    if (!$message) {
        throw new RuntimeException('ثبت پیام ناموفق بود');
    }

    if ($notify) {
        order_messages_notify_admins(
            $pdo,
            $orderId,
            $clientName,
            order_messages_notify_preview($body, $image !== '' ? $image : null)
        );
    }

    return order_messages_serialize($message);
}

function order_messages_serialize(array $row): array
{
    $actor = (string) ($row['actor'] ?? '');
    if ($actor === 'admin') {
        $senderName = (string) ($row['admin_name'] ?? '');
    } elseif ($actor === 'client') {
        $senderName = (string) ($row['client_name'] ?? '');
    } else {
        $senderName = (string) ($row['sales_name'] ?? '');
    }

    $image = isset($row['image']) ? trim((string) $row['image']) : '';

    return [
        'id' => (int) ($row['id'] ?? 0),
        'order_id' => (int) ($row['order_id'] ?? 0),
        'actor' => $actor,
        'sender_name' => $senderName,
        'body' => (string) ($row['body'] ?? ''),
        'image' => $image !== '' ? $image : null,
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
         WHERE order_id = ? AND actor IN ('sales', 'client') AND admin_read_at IS NULL"
    );
    $stmt->execute([$orderId]);

    return (int) $stmt->fetchColumn();
}

function order_messages_unread_count_for_client(PDO $pdo, int $orderId): int
{
    order_messages_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM order_messages
         WHERE order_id = ? AND actor = 'admin' AND client_read_at IS NULL"
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
        try {
            sales_push_notify_order_message($pdo, $orderId, $senderName, $body);
        } catch (Throwable $e) {
            error_log('[order-messages] sales notify failed: ' . $e->getMessage());
            if (is_file(__DIR__ . '/application-logs.php')) {
                require_once __DIR__ . '/application-logs.php';
                cms_app_log_exception($e, [
                    'category' => 'chat',
                    'event_code' => 'chat_notify_sales_failed',
                    'related_order_id' => $orderId,
                    'summary' => 'اعلان گفتگوی سفارش برای فروشنده ناموفق بود',
                ]);
            }
        }
    }
}

function order_messages_notify_admins(PDO $pdo, int $orderId, string $senderName, string $body): void
{
    if (!function_exists('admin_push_notify_order_message')) {
        require_once __DIR__ . '/admin-push.php';
    }
    if (function_exists('admin_push_notify_order_message')) {
        try {
            admin_push_notify_order_message($pdo, $orderId, $senderName, $body);
        } catch (Throwable $e) {
            error_log('[order-messages] admin notify failed: ' . $e->getMessage());
            if (is_file(__DIR__ . '/application-logs.php')) {
                require_once __DIR__ . '/application-logs.php';
                cms_app_log_exception($e, [
                    'category' => 'chat',
                    'event_code' => 'chat_notify_admin_failed',
                    'related_order_id' => $orderId,
                    'summary' => 'اعلان گفتگوی سفارش برای ادمین ناموفق بود',
                ]);
            }
        }
    }
}
