<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/messages.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    messages_ensure_schema($pdo);
    orders_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    $userId = (int) $user['id'];

    if ($method === 'GET') {
        api_json([
            'ok' => true,
            'unread' => messages_client_unread_summary($pdo, $userId),
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = site_auth_request_json();
    $action = isset($body['action']) ? trim((string) $body['action']) : '';

    if ($action === 'mark_orders_read') {
        messages_mark_orders_seen($pdo, $userId);
        api_json([
            'ok' => true,
            'unread' => messages_client_unread_summary($pdo, $userId),
        ]);
    }

    if ($action === 'mark_order_read') {
        $orderId = isset($body['order_id']) ? (int) $body['order_id'] : 0;
        if ($orderId <= 0) {
            api_error('سفارش نامعتبر', 400);
        }
        messages_mark_order_seen($pdo, $userId, $orderId);
        api_json([
            'ok' => true,
            'unread' => messages_client_unread_summary($pdo, $userId),
        ]);
    }

    if ($action === 'mark_messages_read') {
        messages_mark_admin_messages_read($pdo, $userId);
        api_json([
            'ok' => true,
            'unread' => messages_client_unread_summary($pdo, $userId),
        ]);
    }

    if ($action === 'mark_all_read') {
        messages_mark_orders_seen($pdo, $userId);
        messages_mark_admin_messages_read($pdo, $userId);
        api_json([
            'ok' => true,
            'unread' => messages_client_unread_summary($pdo, $userId),
        ]);
    }

    api_error('عملیات نامعتبر', 400);
} catch (Throwable $e) {
    error_log('[notifications] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
