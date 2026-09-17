<?php
declare(strict_types=1);

function admin_users_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          username VARCHAR(64) NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_admin_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    try {
        $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'is_active'")->fetchAll();
        if (count($col) === 0) {
            $pdo->exec(
                'ALTER TABLE admin_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash'
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    $ready = true;
}

function admin_users_normalize_username(string $raw): string
{
    return strtolower(trim($raw));
}

function admin_users_is_valid_username(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/i', $username);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function admin_users_admin_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'is_active' => !isset($row['is_active']) || (int) $row['is_active'] === 1,
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function admin_users_list(PDO $pdo): array
{
    admin_users_ensure_schema($pdo);
    $rows = $pdo->query(
        'SELECT * FROM admin_users ORDER BY username ASC'
    )->fetchAll() ?: [];

    return array_map('admin_users_admin_row', $rows);
}

function admin_users_get(PDO $pdo, int $id): ?array
{
    admin_users_ensure_schema($pdo);
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ? admin_users_admin_row($row) : null;
}

function admin_users_active_count(PDO $pdo, int $excludeId = 0): int
{
    admin_users_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM admin_users WHERE is_active = 1 AND id <> ?'
    );
    $stmt->execute([$excludeId]);

    return (int) $stmt->fetchColumn();
}

/**
 * @param array{id?:int,username:string,password?:string,is_active?:bool} $data
 */
function admin_users_save(PDO $pdo, array $data): int
{
    admin_users_ensure_schema($pdo);

    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $username = admin_users_normalize_username((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $isActive = !isset($data['is_active']) || (bool) $data['is_active'];

    if ($username === '' || !admin_users_is_valid_username($username)) {
        throw new RuntimeException('نام کاربری معتبر نیست (۳ تا ۶۴ کاراکتر، حروف انگلیسی و عدد)');
    }

    $dup = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? AND id <> ? LIMIT 1');
    $dup->execute([$username, $id]);
    if ($dup->fetch()) {
        throw new RuntimeException('این نام کاربری قبلاً ثبت شده است');
    }

    if ($id > 0) {
        $existing = admin_users_get($pdo, $id);
        if ($existing === null) {
            throw new RuntimeException('مدیر یافت نشد');
        }
        if (!$isActive && admin_users_active_count($pdo, $id) === 0) {
            throw new RuntimeException('حداقل یک مدیر فعال باید باقی بماند');
        }
        if ($password !== '') {
            if (strlen($password) < 6) {
                throw new RuntimeException('رمز عبور باید حداقل ۶ کاراکتر باشد');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                'UPDATE admin_users SET username = ?, password_hash = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$username, $hash, $isActive ? 1 : 0, $id]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE admin_users SET username = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$username, $isActive ? 1 : 0, $id]);
        }

        return $id;
    }

    if ($password === '') {
        throw new RuntimeException('رمز عبور الزامی است');
    }
    if (strlen($password) < 6) {
        throw new RuntimeException('رمز عبور باید حداقل ۶ کاراکتر باشد');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO admin_users (username, password_hash, is_active) VALUES (?, ?, ?)'
    );
    $stmt->execute([$username, $hash, $isActive ? 1 : 0]);

    return (int) $pdo->lastInsertId();
}

function admin_users_delete(PDO $pdo, int $id, int $currentAdminId): void
{
    admin_users_ensure_schema($pdo);
    if ($id <= 0) {
        throw new RuntimeException('مدیر نامعتبر');
    }
    if ($id === $currentAdminId) {
        throw new RuntimeException('نمی‌توانید حساب خود را حذف کنید');
    }
    $row = admin_users_get($pdo, $id);
    if ($row === null) {
        throw new RuntimeException('مدیر یافت نشد');
    }
    if ($row['is_active'] && admin_users_active_count($pdo, $id) === 0) {
        throw new RuntimeException('حداقل یک مدیر فعال باید باقی بماند');
    }

    $stmt = $pdo->prepare('DELETE FROM admin_users WHERE id = ?');
    $stmt->execute([$id]);
}
