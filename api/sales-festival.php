<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/festivals.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    festivals_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }
    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $salesUser = sales_auth_current_user($pdo);
    if ($salesUser === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    $snapshot = festivals_sales_snapshot($pdo, (int) $salesUser['id']);
    api_json([
        'ok' => true,
        'server_time' => $snapshot['server_time'],
        'festivals' => $snapshot['festivals'],
    ]);
} catch (Throwable $e) {
    error_log('[sales-festival] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
