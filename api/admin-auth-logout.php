<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/application-logs.php';

admin_auth_prepare_cors();

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $pdo = cms_pdo();
    $admin = admin_auth_current_user($pdo);
    admin_auth_logout();
    cms_app_log([
        'source_app' => 'admin_app',
        'severity' => 'INFO',
        'category' => 'authentication',
        'event_code' => 'admin_logout',
        'summary' => 'خروج از اپ ادمین',
        'user_type' => 'admin',
        'user_id' => $admin !== null ? (int) $admin['id'] : null,
        'user_label' => $admin !== null ? (string) $admin['username'] : null,
    ]);
    api_json(['ok' => true]);
} catch (Throwable $e) {
    error_log('[admin-auth-logout] ' . $e->getMessage());
    cms_app_log_exception($e, [
        'source_app' => 'admin_app',
        'category' => 'authentication',
        'event_code' => 'admin_logout_error',
    ]);
    api_error('خطای سرور', 500);
}
