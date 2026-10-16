<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';
require_once dirname(__DIR__) . '/cms/lib/gsm-sms.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    gsm_sms_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }
    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $salesUser = sales_auth_current_user($pdo);
    if ($salesUser === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    $body = sales_auth_request_json();
    $result = gsm_sms_ingest_order($pdo, $body, (int) $salesUser['id']);
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
        'metadata' => ['error' => $e->getMessage(), 'device_send' => true, 'server_processed' => false],
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
        'metadata' => ['error' => $e->getMessage(), 'device_send' => true, 'server_processed' => false],
    ]);
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[sales-sms-orders] ' . $e->getMessage());
    require_once dirname(__DIR__) . '/cms/lib/application-logs.php';
    cms_app_log_exception($e, [
        'source_app' => 'sms_processor',
        'category' => 'sms',
        'event_code' => 'sms_order_ingest_error',
    ]);
    api_error('خطای سرور', 500);
}
