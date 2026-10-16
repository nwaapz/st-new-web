<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';
require_once dirname(__DIR__) . '/cms/lib/gsm-sms.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    gsm_sms_ensure_schema($pdo);
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }
    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    $result = gsm_sms_ingest_order($pdo, $body, null);
    api_json($result, !empty($result['deduped']) ? 200 : 201);
} catch (FlashDealException $e) {
    flash_deals_emit_api_error($e);
} catch (OrdersConflictException | OrdersIdempotencyForbiddenException $e) {
    orders_api_emit_idempotency_error($e);
} catch (InvalidArgumentException $e) {
    require_once dirname(__DIR__) . '/cms/lib/application-logs.php';
    cms_app_log([
        'source_app' => 'sms_processor',
        'severity' => 'WARNING',
        'category' => 'sms',
        'event_code' => 'sms_order_rejected',
        'summary' => 'پیامک سفارش رد شد',
        'metadata' => ['error' => $e->getMessage(), 'server_processed' => false],
    ]);
    api_error($e->getMessage(), 400);
} catch (RuntimeException $e) {
    require_once dirname(__DIR__) . '/cms/lib/application-logs.php';
    cms_app_log([
        'source_app' => 'sms_processor',
        'severity' => 'WARNING',
        'category' => 'sms',
        'event_code' => 'sms_order_parse_failed',
        'summary' => 'پردازش پیامک سفارش ناموفق بود',
        'metadata' => ['error' => $e->getMessage(), 'server_processed' => false],
    ]);
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-sms-orders] ' . $e->getMessage());
    require_once dirname(__DIR__) . '/cms/lib/application-logs.php';
    cms_app_log_exception($e, [
        'source_app' => 'sms_processor',
        'category' => 'sms',
        'event_code' => 'sms_order_ingest_error',
    ]);
    api_error('خطای سرور', 500);
}
