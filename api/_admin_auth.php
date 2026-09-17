<?php
declare(strict_types=1);

const ADMIN_AUTH_SESSION_NAME = 'STARTECHADMINSESSID';
const ADMIN_AUTH_TTL_SECONDS = 30 * 24 * 60 * 60;

function admin_auth_prepare_cors(): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Credentials: true');
}

function admin_auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== ADMIN_AUTH_SESSION_NAME) {
            session_write_close();
        } else {
            return;
        }
    }

    ini_set('session.gc_maxlifetime', (string) ADMIN_AUTH_TTL_SECONDS);
    session_name(ADMIN_AUTH_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => ADMIN_AUTH_TTL_SECONDS,
        'path' => '/',
        'secure' => admin_auth_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function admin_auth_cookie_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443';
}

function admin_auth_cookie_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    // /test2/api/admin-auth-login.php → /test2/
    $apiDir = rtrim(dirname($script), '/');
    $base = rtrim(dirname($apiDir), '/');
    if ($base === '' || $base === '/' || $base === '.') {
        return '/';
    }
    return $base . '/';
}

function admin_auth_login(int $userId, string $username): void
{
    admin_auth_session_start();
    session_regenerate_id(true);
    $_SESSION['cms_user_id'] = $userId;
    $_SESSION['cms_username'] = $username;
    admin_auth_refresh_session_cookie();
}

function admin_auth_refresh_session_cookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $id = session_id();
    if ($id === '') {
        return;
    }
    setcookie(session_name(), $id, [
        'expires' => time() + ADMIN_AUTH_TTL_SECONDS,
        'path' => '/',
        'secure' => admin_auth_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function admin_auth_logout(): void
{
    admin_auth_session_start();
    $_SESSION = [];
    if (session_id() !== '') {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => admin_auth_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}

/**
 * @return array{id: int, username: string}|null
 */
function admin_auth_current_user(PDO $pdo): ?array
{
    admin_auth_session_start();
    $id = isset($_SESSION['cms_user_id']) ? (int) $_SESSION['cms_user_id'] : 0;
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username FROM admin_users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        admin_auth_logout();
        return null;
    }

    admin_auth_refresh_session_cookie();

    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
    ];
}

/**
 * @return array{id: int, username: string}|null
 */
function admin_auth_attempt_login(PDO $pdo, string $username, string $password): ?array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username, password_hash, is_active FROM admin_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        return null;
    }
    if (isset($row['is_active']) && (int) $row['is_active'] !== 1) {
        return null;
    }

    admin_auth_login((int) $row['id'], (string) $row['username']);

    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
    ];
}

function admin_auth_request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function admin_auth_require_user(PDO $pdo): array
{
    $user = admin_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }
    return $user;
}
