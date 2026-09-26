<?php
declare(strict_types=1);

function sales_users_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sales_users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          username VARCHAR(64) NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          password_plain VARCHAR(128) NOT NULL DEFAULT \'\',
          display_name VARCHAR(128) NOT NULL DEFAULT \'\',
          branch_id INT UNSIGNED NULL,
          published TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_sales_users_username (username),
          KEY idx_sales_users_branch (branch_id),
          KEY idx_sales_users_published (published)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    try {
        $col = $pdo->query("SHOW COLUMNS FROM sales_users LIKE 'password_plain'")->fetchAll();
        if (count($col) === 0) {
            $pdo->exec(
                'ALTER TABLE sales_users ADD COLUMN password_plain VARCHAR(128) NOT NULL DEFAULT \'\' AFTER password_hash'
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM sales_users LIKE 'sms_phone'")->fetchAll();
        if (count($col) === 0) {
            $pdo->exec(
                "ALTER TABLE sales_users ADD COLUMN sms_phone VARCHAR(20) NOT NULL DEFAULT '' AFTER display_name"
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    $ready = true;
}

function sales_users_normalize_username(string $raw): string
{
    return strtolower(trim($raw));
}

function sales_users_is_valid_username(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/i', $username);
}

/**
 * @return array{id: int, username: string, display_name: string, branch_id: ?int}|null
 */
function sales_users_public_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'display_name' => (string) ($row['display_name'] ?? ''),
        'sms_phone' => (string) ($row['sms_phone'] ?? ''),
        'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== null
            ? (int) $row['branch_id']
            : null,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function sales_users_admin_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'display_name' => (string) ($row['display_name'] ?? ''),
        'sms_phone' => (string) ($row['sms_phone'] ?? ''),
        'password' => (string) ($row['password_plain'] ?? ''),
        'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== null
            ? (int) $row['branch_id']
            : null,
        'branch_name' => isset($row['branch_name']) && $row['branch_name'] !== null
            ? (string) $row['branch_name']
            : null,
        'published' => (int) ($row['published'] ?? 1) === 1,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function sales_users_list(PDO $pdo): array
{
    sales_users_ensure_schema($pdo);
    $rows = $pdo->query(
        'SELECT s.*, b.name AS branch_name
         FROM sales_users s
         LEFT JOIN branches b ON b.id = s.branch_id
         ORDER BY s.display_name ASC, s.username ASC'
    )->fetchAll() ?: [];

    return array_map('sales_users_admin_row', $rows);
}

function sales_users_get(PDO $pdo, int $id): ?array
{
    sales_users_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT s.*, b.name AS branch_name
         FROM sales_users s
         LEFT JOIN branches b ON b.id = s.branch_id
         WHERE s.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? sales_users_admin_row($row) : null;
}

/**
 * @return list<array{id:int,name:string,city:string}>
 */
function sales_users_branch_options(PDO $pdo): array
{
    require_once __DIR__ . '/branches.php';
    branches_ensure_schema($pdo);
    $rows = $pdo->query(
        'SELECT id, name, city FROM branches WHERE published = 1 ORDER BY sort_order ASC, name ASC'
    )->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'city' => (string) ($row['city'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @param array{id?:int,username:string,display_name:string,sms_phone?:string,password?:string,branch_id?:?int,published?:bool} $data
 */
function sales_users_save(PDO $pdo, array $data): int
{
    sales_users_ensure_schema($pdo);
    require_once __DIR__ . '/branches.php';
    require_once __DIR__ . '/melipayamak.php';
    branches_ensure_schema($pdo);

    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $username = sales_users_normalize_username((string) ($data['username'] ?? ''));
    $displayName = trim((string) ($data['display_name'] ?? ''));
    $smsPhone = cms_sms_normalize_phone((string) ($data['sms_phone'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $branchId = isset($data['branch_id']) && $data['branch_id'] !== null
        ? (int) $data['branch_id']
        : 0;
    $published = !isset($data['published']) || (bool) $data['published'];

    if ($username === '' || !sales_users_is_valid_username($username)) {
        throw new RuntimeException('نام کاربری معتبر نیست (۳ تا ۶۴ کاراکتر، حروف انگلیسی و عدد)');
    }
    if ($displayName === '') {
        throw new RuntimeException('نام نمایشی الزامی است');
    }
    if ($smsPhone !== '' && !preg_match('/^09\d{9}$/', $smsPhone)) {
        throw new RuntimeException('شماره پیامک باید مانند 09121234567 باشد');
    }

    $dup = $pdo->prepare('SELECT id FROM sales_users WHERE username = ? AND id <> ? LIMIT 1');
    $dup->execute([$username, $id]);
    if ($dup->fetch()) {
        throw new RuntimeException('این نام کاربری قبلاً ثبت شده است');
    }

    if ($branchId > 0) {
        $branchCheck = $pdo->prepare('SELECT id FROM branches WHERE id = ? LIMIT 1');
        $branchCheck->execute([$branchId]);
        if (!$branchCheck->fetch()) {
            throw new RuntimeException('نماینده انتخاب‌شده نامعتبر است');
        }
    } else {
        $branchId = 0;
    }

    if ($id > 0) {
        if ($password !== '') {
            if (strlen($password) < 6) {
                throw new RuntimeException('رمز عبور باید حداقل ۶ کاراکتر باشد');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                'UPDATE sales_users
                 SET username = ?, display_name = ?, sms_phone = ?, password_hash = ?, password_plain = ?, branch_id = ?, published = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $username,
                $displayName,
                $smsPhone,
                $hash,
                $password,
                $branchId > 0 ? $branchId : null,
                $published ? 1 : 0,
                $id,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE sales_users
                 SET username = ?, display_name = ?, sms_phone = ?, branch_id = ?, published = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $username,
                $displayName,
                $smsPhone,
                $branchId > 0 ? $branchId : null,
                $published ? 1 : 0,
                $id,
            ]);
        }
        return $id;
    }

    if ($password === '' || strlen($password) < 6) {
        throw new RuntimeException('رمز عبور باید حداقل ۶ کاراکتر باشد');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO sales_users (username, password_hash, password_plain, display_name, sms_phone, branch_id, published)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $username,
        $hash,
        $password,
        $displayName,
        $smsPhone,
        $branchId > 0 ? $branchId : null,
        $published ? 1 : 0,
    ]);
    return (int) $pdo->lastInsertId();
}

function sales_users_delete(PDO $pdo, int $id): void
{
    sales_users_ensure_schema($pdo);
    if ($id <= 0) {
        throw new RuntimeException('کاربر نامعتبر است');
    }
    $stmt = $pdo->prepare('DELETE FROM sales_users WHERE id = ?');
    $stmt->execute([$id]);
}

function sales_users_display_name_for_id(PDO $pdo, ?int $salesUserId): ?string
{
    if ($salesUserId === null || $salesUserId <= 0) {
        return null;
    }
    sales_users_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT display_name FROM sales_users WHERE id = ? LIMIT 1');
    $stmt->execute([$salesUserId]);
    $name = $stmt->fetchColumn();
    if ($name === false || trim((string) $name) === '') {
        return null;
    }
    return trim((string) $name);
}
