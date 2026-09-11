<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';

sales_auth_prepare_cors();

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

    $body = sales_auth_request_json();
    $username = sales_users_normalize_username((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($username === '' || $password === '') {
        api_error('نام کاربری و رمز عبور الزامی است', 400);
    }
    if (!sales_users_is_valid_username($username)) {
        api_error('نام کاربری نامعتبر است', 400);
    }

    $user = sales_auth_attempt_login($pdo, $username, $password);
    if ($user === null) {
        api_error('نام کاربری یا رمز عبور نادرست است', 401);
    }

    api_json([
        'ok' => true,
        'user' => $user,
    ]);
} catch (Throwable $e) {
    error_log('[sales-auth-login] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
