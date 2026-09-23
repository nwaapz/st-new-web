<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/price-sheet.php';

admin_auth_prepare_cors();

/**
 * @param array<string, mixed> $status
 * @return array<string, mixed>
 */
function admin_price_sheet_json_payload(array $status, bool $ok = true): array
{
    return [
        'ok' => $ok,
        'draft_rows' => (int) ($status['draft_rows'] ?? 0),
        'last_published_at' => (string) ($status['last_published_at'] ?? ''),
        'last_published_at_display' => (string) ($status['last_published_at_display'] ?? ''),
        'last_published_count' => (int) ($status['last_published_count'] ?? 0),
        'draft_updated_at' => (string) ($status['draft_updated_at'] ?? ''),
        'draft_updated_at_display' => (string) ($status['draft_updated_at_display'] ?? ''),
        'categories' => is_array($status['categories'] ?? null) ? $status['categories'] : [],
        // Legacy field names for existing admin app mapping
        'last_sync_at' => (string) ($status['last_published_at'] ?? ''),
        'last_sync_at_display' => (string) ($status['last_published_at_display'] ?? ''),
        'updated' => (int) ($status['last_published_count'] ?? 0),
        'source' => 'price_sheet',
    ];
}

try {
    $pdo = cms_pdo();
    cms_ensure_product_car_models_schema($pdo);
    cms_ensure_product_categories_schema($pdo);
    price_import_ensure_schema($pdo);
    price_sheet_ensure_schema($pdo);
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $status = price_sheet_get_status($pdo);
        api_json(admin_price_sheet_json_payload($status));
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    $action = trim((string) ($body['action'] ?? 'publish'));
    if ($action !== 'publish') {
        api_error('عملیات نامعتبر', 400);
    }

    $categoryId = isset($body['category_id']) ? (int) $body['category_id'] : 0;
    $categoryScope = $categoryId > 0 ? $categoryId : null;

    $result = price_sheet_publish($pdo, $categoryScope);
    $status = price_sheet_get_status($pdo);

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

    api_json(array_merge(admin_price_sheet_json_payload($status), [
        'total_rows' => $result['total_rows'],
        'updated' => $result['updated'],
        'skipped_count' => count($result['skipped']),
        'skipped' => $result['skipped'],
        'updated_rows' => $result['updated_rows'] ?? [],
        'skip_reasons' => $skipReasons,
        'message' => $result['message'],
        'source' => 'price_sheet',
        'category_id' => $result['category_id'],
        'category_name' => $result['category_name'],
    ]));
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-price-sheet] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
