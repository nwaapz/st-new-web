<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
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

    $salesUser = sales_auth_current_user($pdo);
    if ($salesUser === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }
    $salesUserId = (int) $salesUser['id'];

    $body = sales_auth_request_json();
    $orderId = (int) ($body['order_id'] ?? $body['id'] ?? 0);
    $message = trim((string) ($body['message'] ?? $body['reason'] ?? ''));

    if ($orderId <= 0) {
        api_error('شناسه سفارش نامعتبر است', 400);
    }

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null || !orders_sales_user_owns_order($order, $salesUserId)) {
        api_error('سفارش یافت نشد', 404);
    }

    $result = orders_cancel($pdo, $order, 'client', $message);
    $order = orders_get_by_id($pdo, $orderId);

    api_json([
        'ok' => true,
        'message' => $result['message'],
        'order' => orders_serialize(
            $order,
            orders_fetch_items($pdo, $orderId),
            orders_fetch_events($pdo, $orderId)
        ),
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[sales-order-cancel] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
