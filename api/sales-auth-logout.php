<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/application-logs.php';

sales_auth_prepare_cors();

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
    $salesUser = sales_auth_current_user($pdo);
    sales_auth_logout();
    cms_app_log([
        'source_app' => 'sales_app',
        'severity' => 'INFO',
        'category' => 'authentication',
        'event_code' => 'sales_logout',
        'summary' => 'خروج از اپ فروش',
        'user_type' => 'sales',
        'user_id' => $salesUser !== null ? (int) $salesUser['id'] : null,
        'user_label' => $salesUser !== null ? (string) $salesUser['username'] : null,
    ]);
    api_json(['ok' => true]);
} catch (Throwable $e) {
    error_log('[sales-auth-logout] ' . $e->getMessage());
    cms_app_log_exception($e, [
        'source_app' => 'sales_app',
        'category' => 'authentication',
        'event_code' => 'sales_logout_error',
    ]);
    api_error('خطای سرور', 500);
}
