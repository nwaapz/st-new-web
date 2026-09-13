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
        header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'POST') {
        $body = admin_auth_request_json();
        $token = trim((string) ($body['fcm_token'] ?? $body['token'] ?? ''));
        $platform = trim((string) ($body['platform'] ?? 'android'));
        if ($token === '') {
            api_error('توکن FCM الزامی است', 400);
        }
        admin_push_register_token($pdo, (int) $admin['id'], $token, $platform);
        api_json(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $body = admin_auth_request_json();
        $token = trim((string) ($body['fcm_token'] ?? $body['token'] ?? ''));
        if ($token === '') {
            admin_push_unregister_all_for_user($pdo, (int) $admin['id']);
        } else {
            admin_push_unregister_token($pdo, (int) $admin['id'], $token);
        }
        api_json(['ok' => true]);
    }

    api_error('Method not allowed', 405);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-push-register] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
