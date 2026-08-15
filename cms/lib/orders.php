<?php
declare(strict_types=1);

/**
 * Shared order schema + helpers for CMS and public API.
 */

function orders_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS orders (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          public_code VARCHAR(32) NOT NULL,
          user_id INT UNSIGNED NOT NULL,
          phone VARCHAR(20) NOT NULL,
          status ENUM('submitted','accepted','rejected','payment_proof_sent','paid','shipped','not_received','returned_to_origin','lost','received') NOT NULL DEFAULT 'submitted',
          payment_note TEXT NULL,
          payment_file VARCHAR(512) NULL,
          payment_files TEXT NULL,
          payment_warning TEXT NULL,
          payment_warning_state VARCHAR(16) NULL,
          payment_submitted_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_orders_public_code (public_code),
          KEY idx_orders_user (user_id),
          KEY idx_orders_status (status),
          KEY idx_orders_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_items (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          order_id INT UNSIGNED NOT NULL,
          product_id INT UNSIGNED NULL,
          name VARCHAR(191) NOT NULL,
          slug VARCHAR(191) NOT NULL DEFAULT '',
          price_text VARCHAR(128) NULL,
          image VARCHAR(512) NULL,
          quantity INT UNSIGNED NOT NULL DEFAULT 1,
          unit_type ENUM('piece','pack') NOT NULL DEFAULT 'piece',
          pack_size INT UNSIGNED NULL,
          factory_name VARCHAR(191) NULL,
          model_name VARCHAR(191) NULL,
          category_name VARCHAR(191) NULL,
          PRIMARY KEY (id),
          KEY idx_order_items_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_events (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          order_id INT UNSIGNED NOT NULL,
          from_status VARCHAR(32) NULL,
          to_status VARCHAR(32) NOT NULL,
          message TEXT NULL,
          actor ENUM('client','admin') NOT NULL DEFAULT 'client',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_order_events_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Upgrade existing installs: ENUM + payment columns.
    try {
        $pdo->exec(
            "ALTER TABLE orders
             MODIFY COLUMN status ENUM(
               'submitted','accepted','rejected','payment_proof_sent','paid','shipped',
               'not_received','returned_to_origin','lost','received'
             ) NOT NULL DEFAULT 'submitted'"
        );
    } catch (Throwable $e) {
        /* ignore if already current */
    }

    $cols = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM orders');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cols[(string) ($row['Field'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        $cols = [];
    }

    if (!isset($cols['payment_note'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_note TEXT NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_file'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_file VARCHAR(512) NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_files'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_files TEXT NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_warning'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_warning TEXT NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_warning_state'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_warning_state VARCHAR(16) NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_submitted_at'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_submitted_at TIMESTAMP NULL DEFAULT NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }

    foreach (
        [
            'pre_invoice_file' => 'VARCHAR(512) NULL',
            'pre_invoice_created_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'pre_invoice_due_at' => 'DATE NULL',
            'final_invoice_file' => 'VARCHAR(512) NULL',
            'final_invoice_created_at' => 'TIMESTAMP NULL DEFAULT NULL',
        ] as $col => $def
    ) {
        if (!isset($cols[$col])) {
            try {
                $pdo->exec("ALTER TABLE orders ADD COLUMN {$col} {$def}");
            } catch (Throwable $e) {
                /* ignore */
            }
        }
    }

    // Refresh cols after invoice adds (branch cols may still be missing).
    try {
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM orders');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cols[(string) ($row['Field'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        /* keep previous */
    }

    foreach (
        [
            'branch_id' => 'INT UNSIGNED NULL',
            'branch_name' => 'VARCHAR(191) NULL',
            'branch_city' => 'VARCHAR(191) NULL',
            'branch_province_name' => 'VARCHAR(191) NULL',
            'branch_phone' => 'VARCHAR(20) NULL',
        ] as $col => $def
    ) {
        if (!isset($cols[$col])) {
            try {
                $pdo->exec("ALTER TABLE orders ADD COLUMN {$col} {$def}");
            } catch (Throwable $e) {
                /* ignore */
            }
        }
    }
    try {
        $pdo->exec('ALTER TABLE orders ADD KEY idx_orders_branch (branch_id)');
    } catch (Throwable $e) {
        /* exists */
    }

    // Backfill: existing warning text without state counts as open.
    try {
        $pdo->exec(
            "UPDATE orders
             SET payment_warning_state = 'open'
             WHERE payment_warning IS NOT NULL
               AND TRIM(payment_warning) <> ''
               AND (payment_warning_state IS NULL OR payment_warning_state = '')"
        );
    } catch (Throwable $e) {
        /* ignore */
    }

    // Migrate legacy single payment_file into payment_files JSON when empty.
    try {
        $pdo->exec(
            "UPDATE orders
             SET payment_files = CONCAT('[\"', REPLACE(payment_file, '\"', '\\\\\"'), '\"]')
             WHERE (payment_files IS NULL OR payment_files = '' OR payment_files = '[]')
               AND payment_file IS NOT NULL
               AND TRIM(payment_file) <> ''"
        );
    } catch (Throwable $e) {
        /* ignore */
    }

    // products.pack_size
    try {
        $prodCols = [];
        $ps = $pdo->query('SHOW COLUMNS FROM products');
        foreach ($ps->fetchAll() ?: [] as $row) {
            $prodCols[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($prodCols['pack_size'])) {
            $pdo->exec('ALTER TABLE products ADD COLUMN pack_size INT UNSIGNED NULL AFTER price_text');
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    // order_items.unit_type + pack_size
    try {
        $itemCols = [];
        $is = $pdo->query('SHOW COLUMNS FROM order_items');
        foreach ($is->fetchAll() ?: [] as $row) {
            $itemCols[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($itemCols['unit_type'])) {
            $pdo->exec(
                "ALTER TABLE order_items
                 ADD COLUMN unit_type ENUM('piece','pack') NOT NULL DEFAULT 'piece' AFTER quantity"
            );
        }
        if (!isset($itemCols['pack_size'])) {
            $pdo->exec('ALTER TABLE order_items ADD COLUMN pack_size INT UNSIGNED NULL AFTER unit_type');
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    $ready = true;
}

/** @return array<string, string> */
function orders_status_labels(): array
{
    return [
        'submitted' => 'ارسال‌شده از مشتری',
        'accepted' => 'تأیید انبار',
        'rejected' => 'بایگانی — رد انبار',
        'payment_proof_sent' => 'مدارک پرداخت ارسال شد',
        'paid' => 'پرداخت شده',
        'shipped' => 'ارسال مرسوله',
        'not_received' => 'هنوز دریافت نشده',
        'returned_to_origin' => 'برگشت به مبدأ',
        'lost' => 'مفقود',
        'received' => 'دریافت‌شده — تمام',
    ];
}

/** @return list<string> */
function orders_all_statuses(): array
{
    return [
        'submitted',
        'accepted',
        'rejected',
        'payment_proof_sent',
        'paid',
        'shipped',
        'not_received',
        'returned_to_origin',
        'lost',
        'received',
    ];
}

/**
 * Admin-allowed transitions (client moves accepted → payment_proof_sent via API).
 * After ship, order stays open until admin confirms received (finished).
 * @return array<string, list<string>>
 */
function orders_allowed_transitions(): array
{
    return [
        'submitted' => ['accepted', 'rejected'],
        'accepted' => [],
        'rejected' => [],
        'payment_proof_sent' => ['paid'],
        'paid' => ['shipped'],
        'shipped' => ['not_received', 'returned_to_origin', 'lost', 'received'],
        'not_received' => ['returned_to_origin', 'lost', 'received', 'shipped'],
        'returned_to_origin' => ['shipped', 'lost', 'received'],
        'lost' => ['shipped', 'returned_to_origin', 'received'],
        'received' => [],
    ];
}

/** Rejected warehouse orders are closed/archived (no further actions). */
function orders_is_archived(string $status): bool
{
    return $status === 'rejected';
}

/** Delivery confirmed by admin — order is finished. */
function orders_is_finished(string $status): bool
{
    return $status === 'received';
}

/** Parcel problem / in-transit after ship (still open until received). */
function orders_is_parcel_open(string $status): bool
{
    return in_array($status, ['shipped', 'not_received', 'returned_to_origin', 'lost'], true);
}

/** Statuses shown as active work queue in CMS (archived / finished kept separate). */
function orders_active_statuses(): array
{
    return [
        'submitted',
        'accepted',
        'payment_proof_sent',
        'paid',
        'shipped',
        'not_received',
        'returned_to_origin',
        'lost',
    ];
}

function orders_generate_public_code(PDO $pdo): string
{
    $prefix = 'ST-' . date('ymd') . '-';
    for ($i = 0; $i < 12; $i++) {
        $code = $prefix . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE public_code = ? LIMIT 1');
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    return $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/**
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function orders_serialize(array $order, array $items, array $events): array
{
    $serializedItems = [];
    foreach ($items as $item) {
        $serializedItems[] = [
            'id' => (int) $item['id'],
            'product_id' => isset($item['product_id']) && $item['product_id'] !== null
                ? (int) $item['product_id']
                : null,
            'name' => (string) $item['name'],
            'slug' => (string) ($item['slug'] ?? ''),
            'price_text' => $item['price_text'] !== null ? (string) $item['price_text'] : null,
            'image' => $item['image'] !== null ? (string) $item['image'] : null,
            'quantity' => (int) $item['quantity'],
            'unit_type' => isset($item['unit_type']) && (string) $item['unit_type'] === 'pack'
                ? 'pack'
                : 'piece',
            'pack_size' => isset($item['pack_size']) && $item['pack_size'] !== null && (int) $item['pack_size'] > 0
                ? (int) $item['pack_size']
                : null,
            'factory_name' => $item['factory_name'] !== null ? (string) $item['factory_name'] : null,
            'model_name' => $item['model_name'] !== null ? (string) $item['model_name'] : null,
            'category_name' => $item['category_name'] !== null ? (string) $item['category_name'] : null,
        ];
    }

    $serializedEvents = [];
    foreach ($events as $event) {
        $serializedEvents[] = [
            'id' => (int) $event['id'],
            'from_status' => $event['from_status'] !== null ? (string) $event['from_status'] : null,
            'to_status' => (string) $event['to_status'],
            'message' => $event['message'] !== null && trim((string) $event['message']) !== ''
                ? (string) $event['message']
                : null,
            'actor' => (string) $event['actor'],
            'created_at' => (string) $event['created_at'],
        ];
    }

    $paymentNote = isset($order['payment_note']) && $order['payment_note'] !== null
        ? trim((string) $order['payment_note'])
        : '';
    $paymentFiles = orders_payment_files_list($order);
    $warningText = isset($order['payment_warning']) && $order['payment_warning'] !== null
        ? trim((string) $order['payment_warning'])
        : '';
    $warningState = isset($order['payment_warning_state']) && $order['payment_warning_state'] !== null
        ? trim((string) $order['payment_warning_state'])
        : '';
    if ($warningState === '' && $warningText !== '') {
        $warningState = 'open';
    }
    if (!in_array($warningState, ['open', 'answered'], true)) {
        $warningState = '';
    }

    return [
        'id' => (int) $order['id'],
        'public_code' => (string) $order['public_code'],
        'user_id' => (int) $order['user_id'],
        'phone' => (string) $order['phone'],
        'branch_id' => isset($order['branch_id']) && $order['branch_id'] !== null
            ? (int) $order['branch_id']
            : null,
        'branch_name' => isset($order['branch_name']) && $order['branch_name'] !== null
            ? (string) $order['branch_name']
            : null,
        'branch_city' => isset($order['branch_city']) && $order['branch_city'] !== null
            ? (string) $order['branch_city']
            : null,
        'branch_province_name' => isset($order['branch_province_name']) && $order['branch_province_name'] !== null
            ? (string) $order['branch_province_name']
            : null,
        'branch_phone' => isset($order['branch_phone']) && $order['branch_phone'] !== null
            ? (string) $order['branch_phone']
            : null,
        'status' => (string) $order['status'],
        'payment_note' => $paymentNote !== '' ? $paymentNote : null,
        'payment_file' => $paymentFiles[0] ?? null,
        'payment_files' => $paymentFiles,
        // Client only sees an open (unanswered) warning.
        'payment_warning' => ($warningState === 'open' && $warningText !== '') ? $warningText : null,
        'payment_warning_state' => $warningState !== '' ? $warningState : null,
        'payment_submitted_at' => isset($order['payment_submitted_at']) && $order['payment_submitted_at'] !== null
            ? (string) $order['payment_submitted_at']
            : null,
        'pre_invoice_file' => isset($order['pre_invoice_file']) && $order['pre_invoice_file'] !== null
            && trim((string) $order['pre_invoice_file']) !== ''
            ? (string) $order['pre_invoice_file']
            : null,
        'pre_invoice_created_at' => isset($order['pre_invoice_created_at']) && $order['pre_invoice_created_at'] !== null
            ? (string) $order['pre_invoice_created_at']
            : null,
        'pre_invoice_due_at' => isset($order['pre_invoice_due_at']) && $order['pre_invoice_due_at'] !== null
            ? (string) $order['pre_invoice_due_at']
            : null,
        'final_invoice_file' => isset($order['final_invoice_file']) && $order['final_invoice_file'] !== null
            && trim((string) $order['final_invoice_file']) !== ''
            ? (string) $order['final_invoice_file']
            : null,
        'final_invoice_created_at' => isset($order['final_invoice_created_at']) && $order['final_invoice_created_at'] !== null
            ? (string) $order['final_invoice_created_at']
            : null,
        'created_at' => (string) $order['created_at'],
        'updated_at' => (string) $order['updated_at'],
        'items' => $serializedItems,
        'events' => $serializedEvents,
        'item_count' => array_sum(array_map(
            static fn(array $row): int => (int) $row['quantity'],
            $serializedItems
        )),
    ];
}

/** Max payment proof attachments per order. */
function orders_payment_files_max(): int
{
    return 9;
}

/**
 * @param array<string, mixed> $order
 * @return list<string>
 */
function orders_payment_files_list(array $order): array
{
    $raw = isset($order['payment_files']) ? trim((string) $order['payment_files']) : '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $path = trim($item);
                if ($path !== '' && strpos($path, '/uploads/') === 0) {
                    $out[] = $path;
                }
            }
            return array_values(array_unique($out));
        }
    }

    $legacy = isset($order['payment_file']) && $order['payment_file'] !== null
        ? trim((string) $order['payment_file'])
        : '';
    if ($legacy !== '' && strpos($legacy, '/uploads/') === 0) {
        return [$legacy];
    }
    return [];
}

/**
 * @param list<string> $files
 */
function orders_payment_files_encode(array $files): ?string
{
    $clean = [];
    foreach ($files as $item) {
        $path = trim((string) $item);
        if ($path !== '' && strpos($path, '/uploads/') === 0) {
            $clean[] = $path;
        }
    }
    $clean = array_values(array_unique($clean));
    if ($clean === []) {
        return null;
    }
    return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @return list<array<string, mixed>>
 */
function orders_fetch_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function orders_fetch_events(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM order_events WHERE order_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll() ?: [];
}

function orders_add_event(
    PDO $pdo,
    int $orderId,
    ?string $fromStatus,
    string $toStatus,
    string $actor,
    ?string $message
): void {
    $msg = $message !== null ? trim($message) : '';
    $stmt = $pdo->prepare(
        'INSERT INTO order_events (order_id, from_status, to_status, message, actor)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $orderId,
        $fromStatus,
        $toStatus,
        $msg !== '' ? $msg : null,
        $actor,
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function orders_get_by_id(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Absolute path under site public/uploads for a /uploads/... web path. */
function orders_uploads_abs_path(string $webPath): ?string
{
    $webPath = trim($webPath);
    if ($webPath === '' || strpos($webPath, '/uploads/') !== 0) {
        return null;
    }
    $base = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads');
    if ($base === false) {
        $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';
    }
    $rel = substr($webPath, strlen('/uploads/'));
    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    if ($rel === '' || strpos($rel, '..') !== false) {
        return null;
    }
    return $base . DIRECTORY_SEPARATOR . $rel;
}

function orders_delete_upload_file(?string $webPath): void
{
    if ($webPath === null || trim($webPath) === '') {
        return;
    }
    $abs = orders_uploads_abs_path($webPath);
    if ($abs !== null && is_file($abs)) {
        @unlink($abs);
    }
}

/**
 * Save payment proof upload (JPEG/PNG/WebP/PDF, max 5MB).
 * @return string web path /uploads/...
 */
function orders_save_payment_upload(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود فایل ناموفق بود');
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('فایل آپلود معتبر نیست');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('حداکثر حجم فایل ۵ مگابایت است');
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $file['tmp_name']) ?: '';
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type((string) $file['tmp_name']);
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $extGuess = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        ];
        $mime = $extMap[$extGuess] ?? $mime;
    }

    $map = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
        'application/pdf' => '.pdf',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('فقط تصویر (JPEG/PNG/WebP) یا PDF مجاز است');
    }

    $uploadsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
        throw new RuntimeException('ساخت پوشه uploads ممکن نیست');
    }
    if (!is_writable($uploadsDir)) {
        throw new RuntimeException('پوشه uploads قابل نوشتن نیست');
    }

    $name = 'payment-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . $map[$mime];
    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره فایل ناموفق بود');
    }

    return '/uploads/' . $name;
}
