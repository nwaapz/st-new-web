<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/price-import.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    cms_ensure_product_car_models_schema($pdo);
    cms_ensure_product_categories_schema($pdo);
    price_import_ensure_schema($pdo);
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $status = price_import_get_sync_status();
        api_json([
            'ok' => true,
            'last_sync_at' => $status['last_sync_at'],
            'last_sync_at_display' => $status['last_sync_at_display'],
            'updated' => $status['updated'],
            'source' => $status['source'],
            'sheet_configured' => $status['sheet_configured'],
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $status = price_import_get_sync_status();
    if (!$status['sheet_configured']) {
        api_error('آدرس Google Sheet در تنظیمات CMS ثبت نشده است', 400);
    }

    $result = price_import_sync_from_google_sheet($pdo);
    $skipReasons = [];
    foreach ($result['skipped'] as $skip) {
        if (!is_array($skip)) {
            continue;
        }
        $reason = trim((string) ($skip['reason'] ?? 'نامشخص'));
        if ($reason === '') {
            $reason = 'نامشخص';
        }
        $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
    }
    api_json([
        'ok' => true,
        'last_sync_at' => $result['last_sync_at'],
        'last_sync_at_display' => $result['last_sync_at_display'],
        'updated' => $result['updated'],
        'source' => $result['source'],
        'total_rows' => $result['total_rows'],
        'skipped_count' => count($result['skipped']),
        'skipped' => $result['skipped'],
        'skip_reasons' => $skipReasons,
        'message' => $result['message'],
        'sheet_configured' => true,
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-price-sync] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
