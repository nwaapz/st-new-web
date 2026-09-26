<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/sales-users.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    sales_users_ensure_schema($pdo);
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $userId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($userId > 0) {
            $user = sales_users_get($pdo, $userId);
            if ($user === null) {
                api_error('کاربر یافت نشد', 404);
            }
            api_json([
                'ok' => true,
                'user' => $user,
                'branches' => sales_users_branch_options($pdo),
            ]);
        }

        api_json([
            'ok' => true,
            'users' => sales_users_list($pdo),
            'branches' => sales_users_branch_options($pdo),
        ]);
    }

    if ($method === 'DELETE') {
        $userId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($userId <= 0) {
            api_error('شناسه کاربر الزامی است', 400);
        }
        sales_users_delete($pdo, $userId);
        api_json(['ok' => true, 'message' => 'کاربر حذف شد']);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    $action = trim((string) ($body['action'] ?? 'save'));

    if ($action === 'delete') {
        $userId = (int) ($body['id'] ?? 0);
        if ($userId <= 0) {
            api_error('شناسه کاربر الزامی است', 400);
        }
        sales_users_delete($pdo, $userId);
        api_json(['ok' => true, 'message' => 'کاربر حذف شد']);
    }

    $branchRaw = $body['branch_id'] ?? null;
    $branchId = null;
    if ($branchRaw !== null && $branchRaw !== '' && (int) $branchRaw > 0) {
        $branchId = (int) $branchRaw;
    }

    $savedId = sales_users_save($pdo, [
        'id' => (int) ($body['id'] ?? 0),
        'username' => (string) ($body['username'] ?? ''),
        'display_name' => (string) ($body['display_name'] ?? ''),
        'sms_phone' => (string) ($body['sms_phone'] ?? ''),
        'password' => (string) ($body['password'] ?? ''),
        'branch_id' => $branchId,
        'published' => !isset($body['published']) || (bool) $body['published'],
    ]);

    $user = sales_users_get($pdo, $savedId);
    if ($user === null) {
        api_error('خطا در ذخیره کاربر', 500);
    }

    api_json([
        'ok' => true,
        'message' => ((int) ($body['id'] ?? 0)) > 0 ? 'کاربر به‌روز شد' : 'کاربر اضافه شد',
        'user' => $user,
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-sales-users] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
