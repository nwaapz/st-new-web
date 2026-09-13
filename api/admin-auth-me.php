<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = admin_auth_current_user($pdo);
    if ($user === null) {
        api_json(['ok' => true, 'authenticated' => false, 'user' => null]);
    }

    api_json([
        'ok' => true,
        'authenticated' => true,
        'user' => $user,
    ]);
} catch (Throwable $e) {
    error_log('[admin-auth-me] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
