<?php
declare(strict_types=1);

require_once __DIR__ . '/schema-guard.php';
require_once __DIR__ . '/sales-users.php';

function sales_direct_messages_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (cms_schema_guard_done('sales-direct-messages', [__FILE__])) {
        $ready = true;
        return;
    }

    sales_users_ensure_schema($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sales_direct_messages (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          sales_user_id INT UNSIGNED NOT NULL,
          actor ENUM(\'admin\',\'sales\') NOT NULL,
          admin_user_id INT UNSIGNED NULL,
          sender_name VARCHAR(191) NOT NULL DEFAULT \'\',
          body TEXT NOT NULL,
          image VARCHAR(512) NULL,
          via_sms TINYINT(1) NOT NULL DEFAULT 0,
          admin_read_at TIMESTAMP NULL DEFAULT NULL,
          sales_read_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_sales_direct_messages_user (sales_user_id, id),
          CONSTRAINT fk_sales_direct_messages_user
            FOREIGN KEY (sales_user_id) REFERENCES sales_users (id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    cms_schema_guard_mark('sales-direct-messages', [__FILE__]);
    $ready = true;
}

/**
 * @return array<string, mixed>
 */
function sales_direct_messages_serialize(array $row): array
{
    $image = trim((string) ($row['image'] ?? ''));
    $body = (string) ($row['body'] ?? '');
    return [
        'id' => (int) ($row['id'] ?? 0),
        'sales_user_id' => (int) ($row['sales_user_id'] ?? 0),
        'actor' => (string) ($row['actor'] ?? ''),
        'sender_name' => (string) ($row['sender_name'] ?? ''),
        'body' => $body,
        'image' => $image !== '' ? $image : null,
        'via_sms' => !empty($row['via_sms']),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function sales_direct_messages_fetch(PDO $pdo, int $salesUserId, int $sinceId = 0): array
{
    sales_direct_messages_ensure_schema($pdo);
    if ($salesUserId <= 0) {
        return [];
    }

    if ($sinceId > 0) {
        $stmt = $pdo->prepare(
            'SELECT * FROM sales_direct_messages
             WHERE sales_user_id = ? AND id > ?
             ORDER BY id ASC'
        );
        $stmt->execute([$salesUserId, $sinceId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM sales_direct_messages
             WHERE sales_user_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$salesUserId]);
    }

    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = sales_direct_messages_serialize($row);
    }
    return $items;
}

/**
 * @return list<array<string, mixed>>
 */
function sales_direct_messages_wait(PDO $pdo, int $salesUserId, int $sinceId, int $timeoutSec = 25): array
{
    $timeoutSec = max(1, min($timeoutSec, 30));
    $deadline = time() + $timeoutSec;
    while (time() < $deadline) {
        $items = sales_direct_messages_fetch($pdo, $salesUserId, $sinceId);
        if ($items !== []) {
            return $items;
        }
        usleep(500000);
    }
    return [];
}

function sales_direct_messages_mark_read_for_sales(PDO $pdo, int $salesUserId): void
{
    sales_direct_messages_ensure_schema($pdo);
    $pdo->prepare(
        "UPDATE sales_direct_messages
         SET sales_read_at = CURRENT_TIMESTAMP
         WHERE sales_user_id = ? AND actor = 'admin' AND sales_read_at IS NULL"
    )->execute([$salesUserId]);
}

function sales_direct_messages_mark_read_for_admin(PDO $pdo, int $salesUserId): void
{
    sales_direct_messages_ensure_schema($pdo);
    $pdo->prepare(
        "UPDATE sales_direct_messages
         SET admin_read_at = CURRENT_TIMESTAMP
         WHERE sales_user_id = ? AND actor = 'sales' AND admin_read_at IS NULL"
    )->execute([$salesUserId]);
}

function sales_direct_messages_unread_count_for_sales(PDO $pdo, int $salesUserId): int
{
    sales_direct_messages_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM sales_direct_messages
         WHERE sales_user_id = ? AND actor = 'admin' AND sales_read_at IS NULL"
    );
    $stmt->execute([$salesUserId]);
    return (int) $stmt->fetchColumn();
}

function sales_direct_messages_unread_count_for_admin(PDO $pdo, int $salesUserId): int
{
    sales_direct_messages_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM sales_direct_messages
         WHERE sales_user_id = ? AND actor = 'sales' AND admin_read_at IS NULL"
    );
    $stmt->execute([$salesUserId]);
    return (int) $stmt->fetchColumn();
}

function sales_direct_messages_unread_total_for_admin(PDO $pdo): int
{
    sales_direct_messages_ensure_schema($pdo);
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM sales_direct_messages
         WHERE actor = 'sales' AND admin_read_at IS NULL"
    );
    return (int) $stmt->fetchColumn();
}

/**
 * @return list<array<string, mixed>>
 */
function sales_direct_messages_threads_for_admin(PDO $pdo): array
{
    sales_direct_messages_ensure_schema($pdo);
    $rows = $pdo->query(
        "SELECT u.id, u.username, u.display_name, u.published, u.last_seen_at,
                last_msg.body AS last_body,
                last_msg.image AS last_image,
                last_msg.created_at AS last_at,
                last_msg.actor AS last_actor,
                (
                  SELECT COUNT(*)
                  FROM sales_direct_messages uq
                  WHERE uq.sales_user_id = u.id
                    AND uq.actor = 'sales'
                    AND uq.admin_read_at IS NULL
                ) AS unread_count
         FROM sales_users u
         LEFT JOIN sales_direct_messages last_msg
           ON last_msg.id = (
             SELECT m.id FROM sales_direct_messages m
             WHERE m.sales_user_id = u.id
             ORDER BY m.id DESC
             LIMIT 1
           )
         WHERE u.published = 1
            OR last_msg.id IS NOT NULL
         ORDER BY (last_msg.id IS NULL) ASC, last_msg.id DESC, u.display_name ASC, u.username ASC"
    )->fetchAll() ?: [];

    $threads = [];
    foreach ($rows as $row) {
        $lastBody = trim((string) ($row['last_body'] ?? ''));
        $lastImage = trim((string) ($row['last_image'] ?? ''));
        $preview = $lastBody;
        if ($preview === '' && $lastImage !== '') {
            $preview = 'تصویر';
        }
        $display = trim((string) ($row['display_name'] ?? ''));
        if ($display === '') {
            $display = (string) ($row['username'] ?? '');
        }
        $presence = sales_users_presence_status(
            isset($row['last_seen_at']) && $row['last_seen_at'] !== null
                ? (string) $row['last_seen_at']
                : null
        );
        $threads[] = [
            'sales_user_id' => (int) $row['id'],
            'display_name' => $display,
            'username' => (string) ($row['username'] ?? ''),
            'published' => !empty($row['published']),
            'last_body' => $preview !== '' ? $preview : null,
            'last_at' => isset($row['last_at']) && $row['last_at'] !== null
                ? (string) $row['last_at']
                : null,
            'last_actor' => isset($row['last_actor']) && $row['last_actor'] !== null
                ? (string) $row['last_actor']
                : null,
            'unread_count' => (int) ($row['unread_count'] ?? 0),
            'is_online' => $presence['is_online'],
            'last_seen_at' => $presence['last_seen_at'],
        ];
    }
    return $threads;
}

function sales_direct_messages_handle_image_upload(string $field = 'image'): ?string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $error = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود تصویر ناموفق بود');
    }
    if (!is_uploaded_file((string) $_FILES[$field]['tmp_name'])) {
        throw new RuntimeException('فایل آپلود معتبر نیست');
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES[$field]['tmp_name']) ?: '';
    }
    $map = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('فقط JPEG/PNG/WebP مجاز است');
    }
    if ((int) ($_FILES[$field]['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('حداکثر حجم تصویر ۵ مگابایت است');
    }

    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sales-chat';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('ساخت پوشه آپلود ممکن نیست');
    }

    $name = 'chat-' . bin2hex(random_bytes(8)) . $map[$mime];
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string) $_FILES[$field]['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره تصویر ناموفق بود');
    }
    return '/uploads/sales-chat/' . $name;
}

/**
 * @return array<string, mixed>
 */
function sales_direct_messages_insert(
    PDO $pdo,
    int $salesUserId,
    string $actor,
    string $senderName,
    string $body,
    ?string $image,
    ?int $adminUserId
): array {
    sales_direct_messages_ensure_schema($pdo);
    $user = sales_users_get($pdo, $salesUserId);
    if ($user === null) {
        throw new InvalidArgumentException('کاربر فروش یافت نشد');
    }

    $body = trim($body);
    $image = $image !== null ? trim($image) : '';
    if ($body === '' && $image === '') {
        throw new InvalidArgumentException('متن یا تصویر پیام الزامی است');
    }
    if (mb_strlen($body) > 4000) {
        throw new InvalidArgumentException('پیام خیلی طولانی است');
    }
    if ($actor !== 'admin' && $actor !== 'sales') {
        throw new InvalidArgumentException('فرستنده نامعتبر است');
    }

    $adminRead = $actor === 'admin' ? date('Y-m-d H:i:s') : null;
    $salesRead = $actor === 'sales' ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare(
        'INSERT INTO sales_direct_messages
         (sales_user_id, actor, admin_user_id, sender_name, body, image, admin_read_at, sales_read_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $salesUserId,
        $actor,
        $adminUserId,
        $senderName,
        $body,
        $image !== '' ? $image : null,
        $adminRead,
        $salesRead,
    ]);
    $id = (int) $pdo->lastInsertId();

    $rowStmt = $pdo->prepare('SELECT * FROM sales_direct_messages WHERE id = ? LIMIT 1');
    $rowStmt->execute([$id]);
    $row = $rowStmt->fetch();
    if (!$row) {
        throw new RuntimeException('ثبت پیام ناموفق بود');
    }

    $message = sales_direct_messages_serialize($row);
    $preview = $body !== '' ? $body : 'تصویر';
    if ($actor === 'admin') {
        sales_direct_messages_notify_sales($pdo, $salesUserId, $senderName, $preview);
    } else {
        sales_direct_messages_notify_admins($pdo, $salesUserId, $senderName, $preview);
    }
    return $message;
}

function sales_direct_messages_notify_sales(PDO $pdo, int $salesUserId, string $senderName, string $body): void
{
    if (!function_exists('sales_push_notify_direct_message')) {
        require_once __DIR__ . '/sales-push.php';
    }
    if (function_exists('sales_push_notify_direct_message')) {
        sales_push_notify_direct_message($pdo, $salesUserId, $senderName, $body);
    }
}

function sales_direct_messages_notify_admins(PDO $pdo, int $salesUserId, string $senderName, string $body): void
{
    if (!function_exists('admin_push_notify_direct_message')) {
        require_once __DIR__ . '/admin-push.php';
    }
    if (function_exists('admin_push_notify_direct_message')) {
        admin_push_notify_direct_message($pdo, $salesUserId, $senderName, $body);
    }
}
