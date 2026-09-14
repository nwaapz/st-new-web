<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/admin-push.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    admin_push_ensure_schema($pdo);
    $admin = admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $servicePath = admin_push_service_account_path();
        $tokens = admin_push_all_tokens($pdo);
        api_json([
            'ok' => true,
            'service_account' => $servicePath !== null,
            'service_account_path' => $servicePath,
            'firebase_project_id' => admin_push_firebase_project_id(),
            'registered_tokens' => count($tokens),
            'admin_user_id' => (int) $admin['id'],
        ]);
    }

    if ($method === 'POST') {
        $tokens = admin_push_all_tokens($pdo);
        if ($tokens === []) {
            api_error('هیچ توکن FCM ثبت نشده — اپ ادمین را باز کنید و وارد شوید', 400);
        }
        if (admin_push_service_account_path() === null) {
            api_error('firebase-service-account.json روی سرور یافت نشد', 503);
        }

        $data = [
            'order_id' => '0',
            'type' => 'test',
            'public_code' => '',
            'activity' => 'test',
        ];
        $sent = 0;
        foreach ($tokens as $token) {
            if (admin_push_send_to_token($token, 'تست اعلان', 'اتصال FCM برقرار است', $data)) {
                $sent++;
            }
        }
        if ($sent === 0) {
            api_error('ارسال FCM ناموفق بود — error log سرور را بررسی کنید', 502);
        }
        api_json(['ok' => true, 'sent' => $sent, 'tokens' => count($tokens)]);
    }

    api_error('Method not allowed', 405);
} catch (Throwable $e) {
    error_log('[admin-push-status] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
