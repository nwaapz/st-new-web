<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/gsm-sms.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    gsm_sms_ensure_schema($pdo);
    $admin = admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        api_json(gsm_sms_config_payload($pdo, true));
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    $phones = gsm_sms_save_gateway_phones($body['gateway_phones'] ?? '');
    api_json([
        'ok' => true,
        'gateway_phones' => $phones,
        'message' => 'شماره‌های گیت‌وی پیامک ذخیره شد',
        'admin' => $admin['username'] ?? '',
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-sms-gateway] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
