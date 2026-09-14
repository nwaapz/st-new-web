<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/admin-car-models.php';
require_once dirname(__DIR__) . '/cms/lib/admin-factories.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    cms_ensure_car_model_factories_schema($pdo);
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
            $item = admin_car_models_get($pdo, $id);
            if ($item === null) {
                api_error('مدل یافت نشد', 404);
            }
            api_json([
                'ok' => true,
                'car_model' => $item,
                'factories' => admin_factories_options($pdo),
            ]);
        }
        api_json([
            'ok' => true,
            'car_models' => admin_car_models_list($pdo),
            'factories' => admin_factories_options($pdo),
        ]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            api_error('شناسه الزامی است', 400);
        }
        admin_car_models_delete($pdo, $id);
        api_json(['ok' => true, 'message' => 'مدل حذف شد']);
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
        admin_car_models_delete($pdo, $id);
        api_json(['ok' => true, 'message' => 'مدل حذف شد']);
    }

    $savedId = admin_car_models_save($pdo, $body);
    $item = admin_car_models_get($pdo, $savedId);
    if ($item === null) {
        api_error('خطا در ذخیره مدل', 500);
    }

    api_json([
        'ok' => true,
        'message' => ((int) ($body['id'] ?? 0)) > 0 ? 'مدل به‌روز شد' : 'مدل اضافه شد',
        'car_model' => $item,
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-car-models] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
