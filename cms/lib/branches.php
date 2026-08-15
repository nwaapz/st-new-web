<?php
declare(strict_types=1);

/**
 * Branch catalog helpers + ticket schema.
 */

function branches_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if (strpos($digits, '98') === 0 && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    }
    return $digits;
}

function branches_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS branches (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          name VARCHAR(191) NOT NULL,
          province_code VARCHAR(64) NOT NULL,
          province_name VARCHAR(191) NOT NULL,
          city VARCHAR(191) NOT NULL,
          phone VARCHAR(20) NULL,
          address TEXT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          published TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_branches_province (province_code),
          KEY idx_branches_published (published),
          UNIQUE KEY uq_branches_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Upgrade phone column + unique index.
    try {
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM branches');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cols[(string) ($row['Field'] ?? '')] = true;
        }
        if (isset($cols['phone'])) {
            try {
                $pdo->exec('ALTER TABLE branches MODIFY COLUMN phone VARCHAR(20) NULL');
            } catch (Throwable $e) {
                /* ignore */
            }
        }
        try {
            $pdo->exec('ALTER TABLE branches ADD UNIQUE KEY uq_branches_phone (phone)');
        } catch (Throwable $e) {
            /* exists */
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    // site_users.branch_id
    try {
        $userCols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM site_users');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $userCols[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($userCols['branch_id'])) {
            $pdo->exec(
                'ALTER TABLE site_users
                 ADD COLUMN branch_id INT UNSIGNED NULL AFTER phone,
                 ADD KEY idx_site_users_branch (branch_id)'
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS branch_tickets (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          branch_id INT UNSIGNED NOT NULL,
          user_id INT UNSIGNED NOT NULL,
          subject VARCHAR(255) NOT NULL,
          status ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_branch_tickets_branch (branch_id),
          KEY idx_branch_tickets_user (user_id),
          KEY idx_branch_tickets_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS branch_ticket_messages (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          ticket_id INT UNSIGNED NOT NULL,
          actor ENUM('branch','admin') NOT NULL,
          body TEXT NULL,
          image VARCHAR(512) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          branch_read_at TIMESTAMP NULL DEFAULT NULL,
          admin_read_at TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (id),
          KEY idx_ticket_messages_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

/**
 * Find published branch by normalized phone.
 *
 * @return array<string, mixed>|null
 */
function branches_find_by_phone(PDO $pdo, string $phone): ?array
{
    branches_ensure_schema($pdo);
    $phone = branches_normalize_phone($phone);
    if ($phone === '' || !preg_match('/^09\d{9}$/', $phone)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, name, province_code, province_name, city, phone, address, published
         FROM branches
         WHERE phone = ? AND published = 1
         LIMIT 1'
    );
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Sync site_users.branch_id from branches.phone match.
 *
 * @return array{id:int,name:string,city:string,province_name:string,province_code:string}|null
 */
function branches_sync_user_branch(PDO $pdo, int $userId, string $phone): ?array
{
    branches_ensure_schema($pdo);
    $branch = branches_find_by_phone($pdo, $phone);
    if ($branch) {
        $pdo->prepare('UPDATE site_users SET branch_id = ? WHERE id = ?')
            ->execute([(int) $branch['id'], $userId]);
        return [
            'id' => (int) $branch['id'],
            'name' => (string) $branch['name'],
            'city' => (string) $branch['city'],
            'province_name' => (string) $branch['province_name'],
            'province_code' => (string) $branch['province_code'],
        ];
    }
    $pdo->prepare('UPDATE site_users SET branch_id = NULL WHERE id = ?')->execute([$userId]);
    return null;
}

/**
 * @return array{id:int,name:string,city:string,province_name:string,province_code:string}|null
 */
function branches_for_user(PDO $pdo, int $userId): ?array
{
    branches_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT b.id, b.name, b.city, b.province_name, b.province_code
         FROM site_users u
         INNER JOIN branches b ON b.id = u.branch_id AND b.published = 1
         WHERE u.id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'city' => (string) $row['city'],
        'province_name' => (string) $row['province_name'],
        'province_code' => (string) $row['province_code'],
    ];
}

/**
 * Public auth user payload including optional branch.
 *
 * @return array<string, mixed>
 */
function branches_auth_user_payload(PDO $pdo, int $userId, string $phone): array
{
    $branch = branches_for_user($pdo, $userId);
    $payload = [
        'id' => $userId,
        'phone' => $phone,
        'phone_tail' => function_exists('site_auth_phone_tail')
            ? site_auth_phone_tail($phone, 4)
            : substr(preg_replace('/\D/', '', $phone) ?? '', -4),
        'is_branch' => $branch !== null,
        'branch' => $branch,
    ];
    return $payload;
}
