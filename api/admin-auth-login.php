<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($username === '' || $password === '') {
        api_error('نام کاربری و رمز عبور الزامی است', 400);
    }

    $user = admin_auth_attempt_login($pdo, $username, $password);
    if ($user === null) {
        api_error('نام کاربری یا رمز عبور نادرست است', 401);
    }

    api_json([
        'ok' => true,
        'user' => $user,
    ]);
} catch (Throwable $e) {
    error_log('[admin-auth-login] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
