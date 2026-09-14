<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/admin-categories.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
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
            $item = admin_categories_get($pdo, $id);
            if ($item === null) {
                api_error('دسته یافت نشد', 404);
            }
            api_json(['ok' => true, 'category' => $item]);
        }
        api_json(['ok' => true, 'categories' => admin_categories_list($pdo)]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            api_error('شناسه الزامی است', 400);
        }
        admin_categories_delete($pdo, $id);
        api_json(['ok' => true, 'message' => 'دسته حذف شد']);
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
        admin_categories_delete($pdo, $id);
        api_json(['ok' => true, 'message' => 'دسته حذف شد']);
    }

    $savedId = admin_categories_save($pdo, $body);
    $item = admin_categories_get($pdo, $savedId);
    if ($item === null) {
        api_error('خطا در ذخیره دسته', 500);
    }

    api_json([
        'ok' => true,
        'message' => ((int) ($body['id'] ?? 0)) > 0 ? 'دسته به‌روز شد' : 'دسته اضافه شد',
        'category' => $item,
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-categories] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
