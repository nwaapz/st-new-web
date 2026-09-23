<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    orders_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    $body = site_auth_request_json();
    $orderId = (int) ($body['order_id'] ?? $body['id'] ?? 0);

    if ($orderId <= 0) {
        api_error('شناسه سفارش نامعتبر است', 400);
    }

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null || !orders_client_can_access_order($user, $order)) {
        api_error('سفارش یافت نشد', 404);
    }

    if ((string) ($order['status'] ?? '') !== 'submitted') {
        api_error('تأیید تعداد فقط برای سفارش در مرحله ثبت مجاز است', 400);
    }

    $items = orders_fetch_items($pdo, $orderId);
    if (!orders_order_has_item_adjustments($items)) {
        api_error('تغییری در تعداد اقلام ثبت نشده است', 400);
    }
    if (!orders_items_adjustment_pending_flag($order)) {
        api_error('تأیید تعداد در حال حاضر لازم نیست', 400);
    }

    orders_mark_items_adjustment_confirmed($pdo, $order, 'client');
    $order = orders_get_by_id($pdo, $orderId);

    api_json([
        'ok' => true,
        'message' => 'تعداد اقلام تأیید شد',
        'order' => orders_serialize(
            $order,
            orders_fetch_items($pdo, $orderId),
            orders_fetch_events($pdo, $orderId)
        ),
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[order-confirm-adjustments] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
