<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/product-price-sync.php';
require_once dirname(__DIR__) . '/cms/lib/product-categories.php';
require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'GET') {
        api_error('روش نامعتبر است', 405);
    }

    $user = sales_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    product_price_sync_ensure_schema($pdo);

    if (!product_price_sync_within_window()) {
        api_error('بروزرسانی قیمت فقط بین ۸ صبح تا ۸ شب امکان‌پذیر است', 403);
    }

    $salesUserId = (int) $user['id'];
    if (product_price_sync_user_synced_today($pdo, $salesUserId)) {
        api_error('امروز قبلاً قیمت‌ها را بروزرسانی کرده‌اید. فردا دوباره تلاش کنید.', 429);
    }

    $since = isset($_GET['since']) ? trim((string) $_GET['since']) : null;
    if ($since === '') {
        $since = null;
    }

    $products = product_price_sync_fetch_delta($pdo, $since);
    $log = product_price_sync_log_user_sync($pdo, $salesUserId, count($products));

    api_json([
        'ok' => true,
        'synced_at' => $log['synced_at'],
        'product_count' => $log['product_count'],
        'remaining_today' => 0,
        'call_for_price' => cms_call_for_price_enabled(),
        'products' => $products,
    ]);
} catch (Throwable $e) {
    error_log('[client-price-sync] ' . $e->getMessage());
    api_error('بروزرسانی قیمت ناموفق بود', 500);
}
