<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/sales-push.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    sales_push_ensure_schema($pdo);
    $salesUser = sales_auth_current_user($pdo);
    if ($salesUser === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'POST') {
        $body = sales_auth_request_json();
        $token = trim((string) ($body['fcm_token'] ?? $body['token'] ?? ''));
        $platform = trim((string) ($body['platform'] ?? 'android'));
        if ($token === '') {
            api_error('توکن FCM الزامی است', 400);
        }
        sales_push_register_token($pdo, (int) $salesUser['id'], $token, $platform);
        api_json(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $body = sales_auth_request_json();
        $token = trim((string) ($body['fcm_token'] ?? $body['token'] ?? ''));
        if ($token === '') {
            sales_push_unregister_all_for_user($pdo, (int) $salesUser['id']);
        } else {
            sales_push_unregister_token($pdo, (int) $salesUser['id'], $token);
        }
        api_json(['ok' => true]);
    }

    api_error('Method not allowed', 405);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[sales-push-register] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
