<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/flash-deals.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    flash_deals_ensure_schema($pdo);

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

    $payload = flash_deals_sales_offers($pdo);
    api_json([
        'ok' => true,
        'server_time' => $payload['server_time'],
        'offers' => $payload['offers'],
    ]);
} catch (Throwable $e) {
    error_log('[sales-flash-deals] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
