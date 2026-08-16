<?php
declare(strict_types=1);

/**
 * Site-user (customer) session helpers — separate keys from CMS admin.
 * Include after _common.php.
 */

const SITE_DEVICE_COOKIE = 'STARTECH_DEVICE';
/** Stay signed in on this device for 30 days after last use. */
const SITE_AUTH_TTL_SECONDS = 30 * 24 * 60 * 60;
const SITE_DEVICE_TTL_SECONDS = SITE_AUTH_TTL_SECONDS;

/** Replace wildcard CORS so credentialed session cookies work. */
function site_auth_prepare_cors(): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Credentials: true');
}

function site_auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $ttl = SITE_AUTH_TTL_SECONDS;
    ini_set('session.gc_maxlifetime', (string) $ttl);
    session_set_cookie_params([
        'lifetime' => $ttl,
        'path' => '/',
        'secure' => site_auth_device_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('STARTECHWEBSESSID');
    session_start();
}

function site_auth_refresh_session_cookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $id = session_id();
    if ($id === '') {
        return;
    }
    setcookie(session_name(), $id, [
        'expires' => time() + SITE_AUTH_TTL_SECONDS,
        'path' => '/',
        'secure' => site_auth_device_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function site_auth_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          phone VARCHAR(20) NOT NULL,
          branch_id INT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_site_users_phone (phone),
          KEY idx_site_users_branch (branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_otp_codes (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          phone VARCHAR(20) NOT NULL,
          code_hash VARCHAR(255) NOT NULL,
          expires_at DATETIME NOT NULL,
          attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_site_otp_phone (phone),
          KEY idx_site_otp_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_device_tokens (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id INT UNSIGNED NOT NULL,
          phone VARCHAR(20) NOT NULL,
          token_hash CHAR(64) NOT NULL,
          expires_at DATETIME NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_used_at TIMESTAMP NULL DEFAULT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_site_device_token_hash (token_hash),
          KEY idx_site_device_phone (phone),
          KEY idx_site_device_expires (expires_at),
          KEY idx_site_device_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    try {
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM site_users');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cols[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($cols['branch_id'])) {
            $pdo->exec(
                'ALTER TABLE site_users
                 ADD COLUMN branch_id INT UNSIGNED NULL AFTER phone,
                 ADD KEY idx_site_users_branch (branch_id)'
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    require_once dirname(__DIR__) . '/cms/lib/branches.php';
    branches_ensure_schema($pdo);

    $ready = true;
}

function site_auth_device_cookie_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443';
}

function site_auth_clear_device_cookie(): void
{
    setcookie(SITE_DEVICE_COOKIE, '', [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => site_auth_device_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[SITE_DEVICE_COOKIE]);
}

function site_auth_set_device_cookie(string $token, int $maxAge): void
{
    $params = [
        'expires' => time() + $maxAge,
        'path' => '/',
        'secure' => site_auth_device_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie(SITE_DEVICE_COOKIE, $token, $params);
    $_COOKIE[SITE_DEVICE_COOKIE] = $token;
}

function site_auth_read_device_cookie(): string
{
    return trim((string) ($_COOKIE[SITE_DEVICE_COOKIE] ?? ''));
}

/**
 * Issue (or rotate) a long-lived device token for this browser + phone.
 */
function site_auth_issue_device_token(PDO $pdo, int $userId, string $phone): void
{
    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $expires = (new DateTimeImmutable('now'))
        ->modify('+' . SITE_DEVICE_TTL_SECONDS . ' seconds')
        ->format('Y-m-d H:i:s');

    $oldRaw = site_auth_read_device_cookie();
    if ($oldRaw !== '') {
        $oldHash = hash('sha256', $oldRaw);
        $pdo->prepare('DELETE FROM site_device_tokens WHERE token_hash = ?')->execute([$oldHash]);
    }

    $ins = $pdo->prepare(
        'INSERT INTO site_device_tokens (user_id, phone, token_hash, expires_at, last_used_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $ins->execute([$userId, $phone, $hash, $expires]);

    site_auth_set_device_cookie($raw, SITE_DEVICE_TTL_SECONDS);
}

/**
 * @return array{id: int, user_id: int, phone: string}|null
 */
function site_auth_find_device_row(PDO $pdo): ?array
{
    $raw = site_auth_read_device_cookie();
    if ($raw === '') {
        return null;
    }

    $hash = hash('sha256', $raw);
    $stmt = $pdo->prepare(
        'SELECT id, user_id, phone, expires_at
         FROM site_device_tokens
         WHERE token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $expiresAt = strtotime((string) $row['expires_at']);
    if ($expiresAt === false || $expiresAt < time()) {
        $pdo->prepare('DELETE FROM site_device_tokens WHERE id = ?')->execute([(int) $row['id']]);
        site_auth_clear_device_cookie();
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'phone' => (string) $row['phone'],
    ];
}

/**
 * Restore the site user from the device cookie (no phone required).
 *
 * @return array{id: int, phone: string}|null
 */
function site_auth_restore_from_device(PDO $pdo): ?array
{
    $row = site_auth_find_device_row($pdo);
    if ($row === null) {
        return null;
    }

    $check = $pdo->prepare('SELECT id, phone FROM site_users WHERE id = ? AND phone = ? LIMIT 1');
    $check->execute([$row['user_id'], $row['phone']]);
    $user = $check->fetch();
    if (!$user) {
        $pdo->prepare('DELETE FROM site_device_tokens WHERE id = ?')->execute([$row['id']]);
        site_auth_clear_device_cookie();
        return null;
    }

    site_auth_touch_device($pdo, $row['id']);

    return [
        'id' => (int) $user['id'],
        'phone' => (string) $user['phone'],
    ];
}

/**
 * If the device cookie is valid for this phone, return the user row; else null.
 *
 * @return array{id: int, phone: string}|null
 */
function site_auth_lookup_device(PDO $pdo, string $phone): ?array
{
    if (!site_auth_is_valid_mobile($phone)) {
        return null;
    }
    $row = site_auth_find_device_row($pdo);
    if ($row === null || $row['phone'] !== $phone) {
        return null;
    }
    return site_auth_restore_from_device($pdo);
}

function site_auth_touch_device(PDO $pdo, int $tokenId): void
{
    $expires = (new DateTimeImmutable('now'))
        ->modify('+' . SITE_DEVICE_TTL_SECONDS . ' seconds')
        ->format('Y-m-d H:i:s');
    $pdo->prepare(
        'UPDATE site_device_tokens SET last_used_at = NOW(), expires_at = ? WHERE id = ?'
    )->execute([$expires, $tokenId]);

    $raw = site_auth_read_device_cookie();
    if ($raw !== '') {
        site_auth_set_device_cookie($raw, SITE_DEVICE_TTL_SECONDS);
    }
}

function site_auth_is_valid_mobile(string $phone): bool
{
    return (bool) preg_match('/^09\d{9}$/', $phone);
}

/**
 * @return array{id: int, phone: string, branch_id: ?int}|null
 */
function site_auth_current_user(PDO $pdo): ?array
{
    site_auth_session_start();
    $id = isset($_SESSION['web_user_id']) ? (int) $_SESSION['web_user_id'] : 0;
    if ($id <= 0) {
        $restored = site_auth_restore_from_device($pdo);
        if ($restored === null) {
            return null;
        }
        site_auth_login($restored['id'], $restored['phone']);
        $id = $restored['id'];
    }

    $stmt = $pdo->prepare('SELECT id, phone, branch_id FROM site_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['web_user_id'], $_SESSION['web_user_phone']);
        return null;
    }

    $userId = (int) $row['id'];
    $phone = (string) $row['phone'];
    $device = site_auth_find_device_row($pdo);
    if ($device === null || $device['user_id'] !== $userId) {
        site_auth_issue_device_token($pdo, $userId, $phone);
    } else {
        site_auth_touch_device($pdo, $device['id']);
    }
    site_auth_refresh_session_cookie();

    return [
        'id' => $userId,
        'phone' => $phone,
        'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== null
            ? (int) $row['branch_id']
            : null,
    ];
}

function site_auth_login(int $userId, string $phone): void
{
    site_auth_session_start();
    session_regenerate_id(true);
    $_SESSION['web_user_id'] = $userId;
    $_SESSION['web_user_phone'] = $phone;
    site_auth_refresh_session_cookie();
}

function site_auth_logout(?PDO $pdo = null): void
{
    if ($pdo instanceof PDO) {
        $raw = site_auth_read_device_cookie();
        if ($raw !== '') {
            $hash = hash('sha256', $raw);
            $pdo->prepare('DELETE FROM site_device_tokens WHERE token_hash = ?')->execute([$hash]);
        }
    }
    site_auth_clear_device_cookie();

    site_auth_session_start();
    unset($_SESSION['web_user_id'], $_SESSION['web_user_phone']);
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => site_auth_device_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Read JSON body or form POST into an array.
 * @return array<string, mixed>
 */
function site_auth_request_json(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function site_auth_phone_tail(string $phone, int $digits = 4): string
{
    $phone = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($phone) < $digits) {
        return $phone;
    }
    return substr($phone, -$digits);
}
