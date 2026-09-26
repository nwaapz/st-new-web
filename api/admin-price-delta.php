<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/product-price-sync.php';

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
        api_error('روش نامعتبر است', 405);
    }

    admin_auth_require_user($pdo);
    product_price_sync_ensure_schema($pdo);

    $body = admin_auth_request_json();
    $updates = isset($body['updates']) && is_array($body['updates']) ? $body['updates'] : [];
    if ($updates === []) {
        api_error('لیست بروزرسانی خالی است', 400);
    }

    $updatedIds = product_price_sync_apply_admin_delta($pdo, $updates);

    api_json([
        'ok' => true,
        'updated_ids' => $updatedIds,
        'updated_count' => count($updatedIds),
        'message' => count($updatedIds) > 0
            ? count($updatedIds) . ' قیمت ذخیره شد'
            : 'تغییری اعمال نشد',
    ]);
} catch (Throwable $e) {
    error_log('[admin-price-delta] ' . $e->getMessage());
    api_error($e->getMessage() !== '' ? $e->getMessage() : 'ذخیره قیمت‌ها ناموفق بود', 500);
}
