<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function cms_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

function cms_require_login(): void
{
    cms_session_start();
    if (empty($_SESSION['cms_user_id'])) {
        cms_redirect('login.php');
    }
}

function cms_current_username(): string
{
    cms_session_start();
    return (string) ($_SESSION['cms_username'] ?? '');
}

function cms_attempt_login(string $username, string $password): bool
{
    $pdo = cms_pdo();
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    cms_session_start();
    session_regenerate_id(true);
    $_SESSION['cms_user_id'] = (int) $user['id'];
    $_SESSION['cms_username'] = $user['username'];
    return true;
}

function cms_logout(): void
{
    cms_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}
