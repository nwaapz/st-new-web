<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/gsm-sms.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    gsm_sms_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }
    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $salesUser = sales_auth_current_user($pdo);
    if ($salesUser === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    $payload = gsm_sms_config_payload($pdo, false);
    $payload['self'] = [
        'id' => (int) $salesUser['id'],
        'sms_phone' => gsm_sms_normalize_phone((string) ($salesUser['sms_phone'] ?? '')),
    ];
    api_json($payload);
} catch (Throwable $e) {
    error_log('[sales-sms-gateway] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
