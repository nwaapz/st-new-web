<?php
declare(strict_types=1);

/**
 * Branch support tickets (branch ↔ CMS admin).
 */

require_once __DIR__ . '/branches.php';

/**
 * @return array<string, mixed>
 */
function branch_tickets_serialize_ticket(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'branch_id' => (int) $row['branch_id'],
        'user_id' => (int) $row['user_id'],
        'subject' => (string) $row['subject'],
        'status' => (string) $row['status'],
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'branch_name' => isset($row['branch_name']) ? (string) $row['branch_name'] : null,
        'branch_city' => isset($row['branch_city']) ? (string) $row['branch_city'] : null,
        'branch_province_name' => isset($row['branch_province_name'])
            ? (string) $row['branch_province_name']
            : null,
        'unread' => isset($row['unread']) ? (int) $row['unread'] : 0,
    ];
}

/**
 * @return array<string, mixed>
 */
function branch_tickets_serialize_message(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'ticket_id' => (int) $row['ticket_id'],
        'actor' => (string) $row['actor'],
        'body' => $row['body'] !== null ? (string) $row['body'] : null,
        'image' => $row['image'] !== null ? (string) $row['image'] : null,
        'created_at' => (string) $row['created_at'],
    ];
}

/**
 * Save optional uploaded ticket image; returns public path or null.
 */
function branch_tickets_handle_image_upload(string $field = 'image'): ?string
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
    if ((int) $_FILES[$field]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('حداکثر حجم تصویر ۵ مگابایت است');
    }

    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tickets';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('ساخت پوشه آپلود ممکن نیست');
    }

    $name = 'ticket-' . bin2hex(random_bytes(8)) . $map[$mime];
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string) $_FILES[$field]['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره تصویر ناموفق بود');
    }
    return '/uploads/tickets/' . $name;
}
