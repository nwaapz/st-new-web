<?php
declare(strict_types=1);

/**
 * Client ↔ admin messaging + unread watermarks for order changes.
 */

function messages_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_messages (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id INT UNSIGNED NOT NULL,
          body TEXT NOT NULL,
          actor ENUM('client','admin') NOT NULL,
          channel ENUM('support','branch') NOT NULL DEFAULT 'support',
          branch_id INT UNSIGNED NULL,
          province_code VARCHAR(64) NULL,
          province_name VARCHAR(191) NULL,
          branch_name VARCHAR(191) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          client_read_at TIMESTAMP NULL DEFAULT NULL,
          admin_read_at TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (id),
          KEY idx_site_messages_user (user_id),
          KEY idx_site_messages_user_created (user_id, created_at),
          KEY idx_site_messages_channel (channel),
          KEY idx_site_messages_branch (branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_user_reads (
          user_id INT UNSIGNED NOT NULL,
          orders_seen_at TIMESTAMP NULL DEFAULT NULL,
          messages_seen_at TIMESTAMP NULL DEFAULT NULL,
          orders_seen_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_order_reads (
          user_id INT UNSIGNED NOT NULL,
          order_id INT UNSIGNED NOT NULL,
          seen_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (user_id, order_id),
          KEY idx_site_order_reads_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS branches (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          name VARCHAR(191) NOT NULL,
          province_code VARCHAR(64) NOT NULL,
          province_name VARCHAR(191) NOT NULL,
          city VARCHAR(191) NOT NULL,
          phone VARCHAR(64) NULL,
          address TEXT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          published TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_branches_province (province_code),
          KEY idx_branches_published (published)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Upgrade existing installs.
    try {
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM site_user_reads');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cols[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($cols['orders_seen_event_id'])) {
            $pdo->exec(
                'ALTER TABLE site_user_reads
                 ADD COLUMN orders_seen_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0'
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    try {
        $msgCols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM site_messages');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $msgCols[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($msgCols['channel'])) {
            $pdo->exec(
                "ALTER TABLE site_messages
                 ADD COLUMN channel ENUM('support','branch') NOT NULL DEFAULT 'support' AFTER actor"
            );
        }
        if (!isset($msgCols['branch_id'])) {
            $pdo->exec(
                'ALTER TABLE site_messages
                 ADD COLUMN branch_id INT UNSIGNED NULL AFTER channel'
            );
        }
        if (!isset($msgCols['province_code'])) {
            $pdo->exec(
                'ALTER TABLE site_messages
                 ADD COLUMN province_code VARCHAR(64) NULL AFTER branch_id'
            );
        }
        if (!isset($msgCols['province_name'])) {
            $pdo->exec(
                'ALTER TABLE site_messages
                 ADD COLUMN province_name VARCHAR(191) NULL AFTER province_code'
            );
        }
        if (!isset($msgCols['branch_name'])) {
            $pdo->exec(
                'ALTER TABLE site_messages
                 ADD COLUMN branch_name VARCHAR(191) NULL AFTER province_name'
            );
        }
        try {
            $pdo->exec('ALTER TABLE site_messages ADD KEY idx_site_messages_channel (channel)');
        } catch (Throwable $e) {
            /* exists */
        }
        try {
            $pdo->exec('ALTER TABLE site_messages ADD KEY idx_site_messages_branch (branch_id)');
        } catch (Throwable $e) {
            /* exists */
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    $ready = true;
}

/**
 * @return array{orders_seen_at: ?string, messages_seen_at: ?string, orders_seen_event_id: int}
 */
function messages_get_reads(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT orders_seen_at, messages_seen_at, orders_seen_event_id
         FROM site_user_reads WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return [
            'orders_seen_at' => null,
            'messages_seen_at' => null,
            'orders_seen_event_id' => 0,
        ];
    }
    return [
        'orders_seen_at' => $row['orders_seen_at'] !== null ? (string) $row['orders_seen_at'] : null,
        'messages_seen_at' => $row['messages_seen_at'] !== null ? (string) $row['messages_seen_at'] : null,
        'orders_seen_event_id' => isset($row['orders_seen_event_id'])
            ? (int) $row['orders_seen_event_id']
            : 0,
    ];
}

function messages_ensure_read_row(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO site_user_reads (user_id) VALUES (?)'
    );
    $stmt->execute([$userId]);
}

function messages_touch_reads(PDO $pdo, int $userId, bool $orders, bool $messages): void
{
    messages_ensure_read_row($pdo, $userId);

    if ($orders) {
        $maxStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(e.id), 0)
             FROM order_events e
             INNER JOIN orders o ON o.id = e.order_id
             WHERE o.user_id = ?"
        );
        $maxStmt->execute([$userId]);
        $maxId = (int) $maxStmt->fetchColumn();

        $upd = $pdo->prepare(
            'UPDATE site_user_reads
             SET orders_seen_event_id = ?,
                 orders_seen_at = CURRENT_TIMESTAMP
             WHERE user_id = ?'
        );
        $upd->execute([$maxId, $userId]);
    }

    if ($messages) {
        $upd = $pdo->prepare(
            'UPDATE site_user_reads
             SET messages_seen_at = CURRENT_TIMESTAMP
             WHERE user_id = ?'
        );
        $upd->execute([$userId]);
    }
}

function messages_count_unread_for_client(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM site_messages
         WHERE user_id = ? AND actor = 'admin' AND client_read_at IS NULL"
    );
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function messages_count_unread_orders_for_client(PDO $pdo, int $userId): int
{
    $reads = messages_get_reads($pdo, $userId);
    $globalSeen = (int) ($reads['orders_seen_event_id'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM order_events e
         INNER JOIN orders o ON o.id = e.order_id
         LEFT JOIN site_order_reads r
           ON r.order_id = e.order_id AND r.user_id = o.user_id
         WHERE o.user_id = ?
           AND e.actor = 'admin'
           AND e.id > COALESCE(r.seen_event_id, ?)"
    );
    $stmt->execute([$userId, $globalSeen]);
    return (int) $stmt->fetchColumn();
}

/**
 * Unread admin event counts keyed by order_id for a user's orders.
 * Floor = COALESCE(per-order seen, global watermark, 0).
 *
 * @param list<int>|null $orderIds Limit to these order IDs (null = all for user).
 * @return array<int, int> order_id => unread count
 */
function messages_unread_admin_events_by_order(PDO $pdo, int $userId, ?array $orderIds = null): array
{
    $reads = messages_get_reads($pdo, $userId);
    $globalSeen = (int) ($reads['orders_seen_event_id'] ?? 0);

    $sql = "SELECT e.order_id, COUNT(*) AS unread_count
            FROM order_events e
            INNER JOIN orders o ON o.id = e.order_id
            LEFT JOIN site_order_reads r
              ON r.order_id = e.order_id AND r.user_id = o.user_id
            WHERE o.user_id = ?
              AND e.actor = 'admin'
              AND e.id > COALESCE(r.seen_event_id, ?)";
    $params = [$userId, $globalSeen];

    if ($orderIds !== null) {
        $ids = [];
        foreach ($orderIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND e.order_id IN ({$placeholders})";
        foreach ($ids as $id) {
            $params[] = $id;
        }
    }

    $sql .= ' GROUP BY e.order_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $out[(int) $row['order_id']] = (int) $row['unread_count'];
    }
    return $out;
}

function messages_unread_admin_events_for_order(PDO $pdo, int $userId, int $orderId): int
{
    $map = messages_unread_admin_events_by_order($pdo, $userId, [$orderId]);
    return $map[$orderId] ?? 0;
}

function messages_mark_order_seen(PDO $pdo, int $userId, int $orderId): void
{
    $own = $pdo->prepare(
        'SELECT 1 FROM orders WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $own->execute([$orderId, $userId]);
    if (!$own->fetchColumn()) {
        throw new RuntimeException('سفارش یافت نشد');
    }

    $maxStmt = $pdo->prepare(
        'SELECT COALESCE(MAX(id), 0) FROM order_events WHERE order_id = ?'
    );
    $maxStmt->execute([$orderId]);
    $maxId = (int) $maxStmt->fetchColumn();

    $upd = $pdo->prepare(
        'INSERT INTO site_order_reads (user_id, order_id, seen_event_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE seen_event_id = GREATEST(seen_event_id, VALUES(seen_event_id))'
    );
    $upd->execute([$userId, $orderId, $maxId]);
}

/**
 * @return array{orders: int, messages: int, total: int}
 */
function messages_client_unread_summary(PDO $pdo, int $userId): array
{
    $orders = messages_count_unread_orders_for_client($pdo, $userId);
    $msgs = messages_count_unread_for_client($pdo, $userId);
    return [
        'orders' => $orders,
        'messages' => $msgs,
        'total' => $orders + $msgs,
    ];
}

function messages_mark_admin_messages_read(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        "UPDATE site_messages
         SET client_read_at = CURRENT_TIMESTAMP
         WHERE user_id = ? AND actor = 'admin' AND client_read_at IS NULL"
    );
    $stmt->execute([$userId]);
    messages_touch_reads($pdo, $userId, false, true);
}

function messages_mark_client_messages_read(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        "UPDATE site_messages
         SET admin_read_at = CURRENT_TIMESTAMP
         WHERE user_id = ? AND actor = 'client' AND admin_read_at IS NULL"
    );
    $stmt->execute([$userId]);
}

function messages_mark_orders_seen(PDO $pdo, int $userId): void
{
    messages_touch_reads($pdo, $userId, true, false);

    // Also upsert per-order watermarks so clear-all stays consistent with per-order reads.
    $stmt = $pdo->prepare(
        "SELECT o.id AS order_id, COALESCE(MAX(e.id), 0) AS max_event_id
         FROM orders o
         LEFT JOIN order_events e ON e.order_id = o.id
         WHERE o.user_id = ?
         GROUP BY o.id"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll() ?: [];
    if ($rows === []) {
        return;
    }

    $upd = $pdo->prepare(
        'INSERT INTO site_order_reads (user_id, order_id, seen_event_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE seen_event_id = GREATEST(seen_event_id, VALUES(seen_event_id))'
    );
    foreach ($rows as $row) {
        $upd->execute([
            $userId,
            (int) $row['order_id'],
            (int) $row['max_event_id'],
        ]);
    }
}

/**
 * @return array<string, mixed>
 */
function messages_serialize(array $row): array
{
    $channel = isset($row['channel']) ? (string) $row['channel'] : 'support';
    if ($channel !== 'branch') {
        $channel = 'support';
    }

    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'body' => (string) $row['body'],
        'actor' => (string) $row['actor'],
        'channel' => $channel,
        'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== null
            ? (int) $row['branch_id']
            : null,
        'province_code' => isset($row['province_code']) && $row['province_code'] !== null
            ? (string) $row['province_code']
            : null,
        'province_name' => isset($row['province_name']) && $row['province_name'] !== null
            ? (string) $row['province_name']
            : null,
        'branch_name' => isset($row['branch_name']) && $row['branch_name'] !== null
            ? (string) $row['branch_name']
            : null,
        'created_at' => (string) $row['created_at'],
        'client_read_at' => isset($row['client_read_at']) && $row['client_read_at'] !== null
            ? (string) $row['client_read_at']
            : null,
        'admin_read_at' => isset($row['admin_read_at']) && $row['admin_read_at'] !== null
            ? (string) $row['admin_read_at']
            : null,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function messages_fetch_thread(PDO $pdo, int $userId, int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare(
        "SELECT * FROM site_messages
         WHERE user_id = ?
         ORDER BY created_at ASC, id ASC
         LIMIT {$limit}"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = messages_serialize($row);
    }
    return $out;
}

/**
 * @param array{
 *   channel?: string,
 *   branch_id?: int|null,
 *   province_code?: string|null,
 *   province_name?: string|null,
 *   branch_name?: string|null
 * } $meta
 */
function messages_add(PDO $pdo, int $userId, string $body, string $actor, array $meta = []): array
{
    $body = trim($body);
    if ($body === '') {
        throw new RuntimeException('متن پیام خالی است');
    }
    if (mb_strlen($body) > 4000) {
        throw new RuntimeException('پیام خیلی طولانی است');
    }
    if ($actor !== 'client' && $actor !== 'admin') {
        throw new RuntimeException('فرستنده نامعتبر است');
    }

    $channel = isset($meta['channel']) ? (string) $meta['channel'] : 'support';
    if ($channel !== 'branch') {
        $channel = 'support';
    }

    $branchId = isset($meta['branch_id']) && $meta['branch_id'] !== null
        ? (int) $meta['branch_id']
        : null;
    if ($branchId !== null && $branchId <= 0) {
        $branchId = null;
    }

    $provinceCode = isset($meta['province_code']) && $meta['province_code'] !== null
        ? trim((string) $meta['province_code'])
        : null;
    if ($provinceCode === '') {
        $provinceCode = null;
    }

    $provinceName = isset($meta['province_name']) && $meta['province_name'] !== null
        ? trim((string) $meta['province_name'])
        : null;
    if ($provinceName === '') {
        $provinceName = null;
    }

    $branchName = isset($meta['branch_name']) && $meta['branch_name'] !== null
        ? trim((string) $meta['branch_name'])
        : null;
    if ($branchName === '') {
        $branchName = null;
    }

    if ($channel === 'support') {
        $branchId = null;
        $provinceCode = null;
        $provinceName = null;
        $branchName = null;
    }

    $clientRead = $actor === 'client' ? date('Y-m-d H:i:s') : null;
    $adminRead = $actor === 'admin' ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare(
        'INSERT INTO site_messages
         (user_id, body, actor, channel, branch_id, province_code, province_name, branch_name, client_read_at, admin_read_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $body,
        $actor,
        $channel,
        $branchId,
        $provinceCode,
        $provinceName,
        $branchName,
        $clientRead,
        $adminRead,
    ]);
    $id = (int) $pdo->lastInsertId();

    $get = $pdo->prepare('SELECT * FROM site_messages WHERE id = ? LIMIT 1');
    $get->execute([$id]);
    $row = $get->fetch();
    if (!$row) {
        throw new RuntimeException('خطا در ذخیره پیام');
    }
    return messages_serialize($row);
}

/**
 * Conversation list for CMS.
 * @param 'all'|'support'|'branch' $channelFilter
 * @return list<array<string, mixed>>
 */
function messages_phone_digits(string $value): string
{
    $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];
    $normalized = strtr(trim($value), $map);
    return preg_replace('/\D+/', '', $normalized) ?? '';
}

function messages_list_conversations(
    PDO $pdo,
    int $limit = 100,
    string $channelFilter = 'all',
    string $phoneQuery = ''
): array
{
    $limit = max(1, min(300, $limit));
    if ($channelFilter !== 'support' && $channelFilter !== 'branch') {
        $channelFilter = 'all';
    }

    $existsClause = $channelFilter === 'all'
        ? 'EXISTS (SELECT 1 FROM site_messages m WHERE m.user_id = u.id)'
        : "EXISTS (SELECT 1 FROM site_messages m WHERE m.user_id = u.id AND m.channel = " . $pdo->quote($channelFilter) . ")";

    $lastWhere = $channelFilter === 'all'
        ? 'm.user_id = u.id'
        : 'm.user_id = u.id AND m.channel = ' . $pdo->quote($channelFilter);

    $unreadWhere = $channelFilter === 'all'
        ? "m.user_id = u.id AND m.actor = 'client' AND m.admin_read_at IS NULL"
        : "m.user_id = u.id AND m.channel = " . $pdo->quote($channelFilter)
            . " AND m.actor = 'client' AND m.admin_read_at IS NULL";

    $phoneDigits = messages_phone_digits($phoneQuery);
    $phoneClause = '';
    if ($phoneDigits !== '') {
        $phoneClause = ' AND u.phone LIKE ' . $pdo->quote('%' . $phoneDigits . '%');
    }

    $sql = "SELECT
              u.id AS user_id,
              u.phone,
              (
                SELECT m.body FROM site_messages m
                WHERE {$lastWhere}
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 1
              ) AS last_body,
              (
                SELECT m.created_at FROM site_messages m
                WHERE {$lastWhere}
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 1
              ) AS last_at,
              (
                SELECT m.actor FROM site_messages m
                WHERE {$lastWhere}
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 1
              ) AS last_actor,
              (
                SELECT m.channel FROM site_messages m
                WHERE {$lastWhere}
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 1
              ) AS last_channel,
              (
                SELECT m.province_name FROM site_messages m
                WHERE {$lastWhere}
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 1
              ) AS last_province_name,
              (
                SELECT m.branch_name FROM site_messages m
                WHERE {$lastWhere}
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 1
              ) AS last_branch_name,
              (
                SELECT COUNT(*) FROM site_messages m
                WHERE {$unreadWhere}
              ) AS unread_admin
            FROM site_users u
            WHERE {$existsClause}{$phoneClause}
            ORDER BY last_at DESC
            LIMIT {$limit}";
    $rows = $pdo->query($sql)->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'user_id' => (int) $row['user_id'],
            'phone' => (string) $row['phone'],
            'last_body' => $row['last_body'] !== null ? (string) $row['last_body'] : '',
            'last_at' => $row['last_at'] !== null ? (string) $row['last_at'] : null,
            'last_actor' => $row['last_actor'] !== null ? (string) $row['last_actor'] : null,
            'last_channel' => $row['last_channel'] !== null ? (string) $row['last_channel'] : 'support',
            'last_province_name' => $row['last_province_name'] !== null
                ? (string) $row['last_province_name']
                : null,
            'last_branch_name' => $row['last_branch_name'] !== null
                ? (string) $row['last_branch_name']
                : null,
            'unread_admin' => (int) $row['unread_admin'],
        ];
    }
    return $out;
}

/**
 * Latest branch-channel metadata for a user (for admin reply context).
 *
 * @return array{
 *   channel: string,
 *   branch_id: ?int,
 *   province_code: ?string,
 *   province_name: ?string,
 *   branch_name: ?string
 * }|null
 */
function messages_latest_branch_context(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT channel, branch_id, province_code, province_name, branch_name
         FROM site_messages
         WHERE user_id = ? AND channel = 'branch'
         ORDER BY created_at DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'channel' => 'branch',
        'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== null
            ? (int) $row['branch_id']
            : null,
        'province_code' => isset($row['province_code']) && $row['province_code'] !== null
            ? (string) $row['province_code']
            : null,
        'province_name' => isset($row['province_name']) && $row['province_name'] !== null
            ? (string) $row['province_name']
            : null,
        'branch_name' => isset($row['branch_name']) && $row['branch_name'] !== null
            ? (string) $row['branch_name']
            : null,
    ];
}
