<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/order-cheques.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    order_cheques_ensure_schema($pdo);

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

    $salesUserId = (int) $salesUser['id'];
    $cheques = order_cheques_fetch_for_sales_user($pdo, $salesUserId);

    api_json([
        'ok' => true,
        'cheques' => $cheques,
    ]);
} catch (Throwable $e) {
    error_log('[sales-cheques] ' . $e->getMessage());
    api_error('بارگذاری چک‌ها ناموفق بود', 500);
}
