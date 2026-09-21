<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/admin-products.php';
require_once dirname(__DIR__) . '/cms/lib/admin-categories.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    admin_products_ensure_schema($pdo);
    admin_categories_ensure_schema($pdo);
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
            $product = admin_products_get($pdo, $id);
            if ($product === null) {
                api_error('محصول یافت نشد', 404);
            }
            api_json([
                'ok' => true,
                'product' => $product,
                'categories' => admin_categories_options($pdo),
            ]);
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $categoryId = max(0, (int) ($_GET['category_id'] ?? 0));
        $carModelId = max(0, (int) ($_GET['car_model_id'] ?? 0));
        $list = admin_products_list($pdo, $q, $page, $categoryId, $carModelId);
        api_json([
            'ok' => true,
            'products' => $list['items'],
            'total' => $list['total'],
            'page' => $list['page'],
            'total_pages' => $list['total_pages'],
            'search_intent' => $list['search_intent'],
        ]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            api_error('شناسه الزامی است', 400);
        }
        admin_products_delete($pdo, $id);
        api_json(['ok' => true, 'message' => 'محصول حذف شد']);
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
        admin_products_delete($pdo, $id);
        api_json(['ok' => true, 'message' => 'محصول حذف شد']);
    }

    if ($action === 'options') {
        api_json([
            'ok' => true,
            'products' => admin_products_options($pdo, (string) ($body['q'] ?? '')),
        ]);
    }

    $savedId = admin_products_save($pdo, $body);
    $product = admin_products_get($pdo, $savedId);
    if ($product === null) {
        api_error('خطا در ذخیره محصول', 500);
    }

    api_json([
        'ok' => true,
        'message' => ((int) ($body['id'] ?? 0)) > 0 ? 'محصول به‌روز شد' : 'محصول اضافه شد',
        'product' => $product,
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (PDOException $e) {
    error_log('[admin-products] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (str_contains($msg, 'Duplicate entry')) {
        if (str_contains($msg, 'uq_prod_slug') || str_contains($msg, 'slug')) {
            api_error('این اسلاگ قبلاً استفاده شده است', 400);
        }
        if (str_contains($msg, 'uq_prod_visual_id') || str_contains($msg, 'visual_id')) {
            api_error('این شناسه نمایشی قبلاً استفاده شده است', 400);
        }
        api_error('اطلاعات تکراری است', 400);
    }
    if (str_contains($msg, 'stock_qty')) {
        api_error('ستون موجودی انبار روی سرور آماده نیست. migrate-run.php را اجرا کنید.', 400);
    }
    api_error('خطای پایگاه داده: ' . preg_replace('/\s+\[.*$/', '', $msg), 500);
} catch (Throwable $e) {
    error_log('[admin-products] ' . $e->getMessage());
    $detail = trim($e->getMessage());
    api_error($detail !== '' ? ('خطای سرور: ' . $detail) : 'خطای سرور', 500);
}
