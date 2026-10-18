<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/auth.php';
require_once dirname(__DIR__) . '/cms/lib/admin-push.php';

admin_auth_prepare_cors();

/**
 * Admin mobile API session (STARTECHADMINSESSID) or CMS web login (PHPSESSID).
 *
 * @return array{id: int, username: string}
 */
function admin_push_status_require_user(PDO $pdo): array
{
    $user = admin_auth_current_user($pdo);
    if ($user !== null) {
        return $user;
    }
    $cms = cms_current_admin();
    if ($cms !== null) {
        return $cms;
    }
    api_error('لطفاً وارد حساب کاربری شوید', 401);
}

try {
    $pdo = cms_pdo();
    admin_push_ensure_schema($pdo);
    $admin = admin_push_status_require_user($pdo);

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
        $result = admin_push_send_test($pdo, (int) $admin['id'], 'تست اعلان', 'اتصال FCM برقرار است');
        api_json([
            'ok' => true,
            'queued_phone' => $result['queued'],
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'fcm_tokens' => $result['fcm_tokens'],
            'tray' => admin_push_delivery_mode(),
            'last_fcm_error' => admin_push_last_fcm_error(),
        ]);
    }

    api_error('Method not allowed', 405);
} catch (Throwable $e) {
    error_log('[admin-push-status] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
