<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cms/lib/sales-users.php';

const SALES_AUTH_SESSION_NAME = 'STARTECHSALESSESSID';
const SALES_AUTH_TTL_SECONDS = 30 * 24 * 60 * 60;

function sales_auth_prepare_cors(): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Credentials: true');
}

function sales_auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== SALES_AUTH_SESSION_NAME) {
            session_write_close();
        } else {
            return;
        }
    }

    ini_set('session.gc_maxlifetime', (string) SALES_AUTH_TTL_SECONDS);
    session_name(SALES_AUTH_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SALES_AUTH_TTL_SECONDS,
        'path' => '/',
        'secure' => sales_auth_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function sales_auth_cookie_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443';
}

function sales_auth_login(int $userId, string $username): void
{
    sales_auth_session_start();
    $_SESSION['sales_user_id'] = $userId;
    $_SESSION['sales_username'] = $username;
    sales_auth_refresh_session_cookie();
}

function sales_auth_refresh_session_cookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $id = session_id();
    if ($id === '') {
        return;
    }
    setcookie(session_name(), $id, [
        'expires' => time() + SALES_AUTH_TTL_SECONDS,
        'path' => '/',
        'secure' => sales_auth_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function sales_auth_logout(): void
{
    sales_auth_session_start();
    $_SESSION = [];
    if (session_id() !== '') {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => sales_auth_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}

/**
 * @return array{id: int, username: string, display_name: string, branch_id: ?int, published: int}|null
 */
function sales_auth_current_user(PDO $pdo): ?array
{
    sales_auth_session_start();
    $id = isset($_SESSION['sales_user_id']) ? (int) $_SESSION['sales_user_id'] : 0;
    if ($id <= 0) {
        return null;
    }

    sales_users_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, username, display_name, branch_id, published
         FROM sales_users
         WHERE id = ? AND published = 1
         LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        sales_auth_logout();
        return null;
    }

    sales_auth_refresh_session_cookie();

    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'display_name' => (string) ($row['display_name'] ?? ''),
        'branch_id' => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
        'published' => (int) $row['published'],
    ];
}

/**
 * @return array{id: int, username: string, display_name: string, branch_id: ?int}|null
 */
function sales_auth_attempt_login(PDO $pdo, string $username, string $password): ?array
{
    sales_users_ensure_schema($pdo);
    $username = sales_users_normalize_username($username);
    if ($username === '' || $password === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, display_name, branch_id
         FROM sales_users
         WHERE username = ? AND published = 1
         LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        return null;
    }

    sales_auth_login((int) $row['id'], (string) $row['username']);

    return sales_users_public_row($row);
}

function sales_auth_request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
