<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/admin-push.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    admin_push_ensure_schema($pdo);
    $admin = admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    api_json([
        'ok' => true,
        'items' => admin_push_pull($pdo, (int) $admin['id']),
    ]);
} catch (Throwable $e) {
    error_log('[admin-push-inbox] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
