<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/admin-product-series.php';
require_once dirname(__DIR__) . '/cms/lib/admin-categories.php';
require_once dirname(__DIR__) . '/cms/lib/admin-products.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    admin_product_series_ensure_schema($pdo);
    admin_categories_ensure_schema($pdo);
    admin_products_ensure_schema($pdo);
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $series = admin_product_series_get($pdo, $id);
            if ($series === null) {
                api_error('سری یافت نشد', 404);
            }
            api_json([
                'ok' => true,
                'series' => $series,
                'categories' => admin_categories_options($pdo),
                'products' => admin_products_options($pdo),
            ]);
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = admin_product_series_list($pdo, $q, $page);
        api_json([
            'ok' => true,
            'series_list' => $list['items'],
            'total' => $list['total'],
            'page' => $list['page'],
            'total_pages' => $list['total_pages'],
        ]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            api_error('شناسه الزامی است', 400);
        }
        admin_product_series_delete($pdo, $id);
        api_json(['ok' => true, 'message' => 'سری حذف شد']);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    $action = trim((string) ($body['action'] ?? 'save'));

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            api_error('شناسه الزامی است', 400);
        }
        admin_product_series_delete($pdo, $id);
        api_json(['ok' => true, 'message' => 'سری حذف شد']);
    }

    $savedId = admin_product_series_save($pdo, $body);
    $series = admin_product_series_get($pdo, $savedId);
    if ($series === null) {
        api_error('خطا در ذخیره سری', 500);
    }

    api_json([
        'ok' => true,
        'message' => ((int) ($body['id'] ?? 0)) > 0 ? 'سری به‌روز شد' : 'سری اضافه شد',
        'series' => $series,
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-product-series] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
