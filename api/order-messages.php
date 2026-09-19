<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';
require_once dirname(__DIR__) . '/cms/lib/order-messages.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    orders_ensure_schema($pdo);
    order_messages_ensure_schema($pdo);

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }
    $userId = (int) $user['id'];

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    if ($orderId <= 0 && $method === 'POST') {
        $body = site_auth_request_json();
        $orderId = (int) ($body['order_id'] ?? 0);
    }
    if ($orderId <= 0) {
        api_error('شناسه سفارش نامعتبر است', 400);
    }

    $order = order_messages_verify_client_order($pdo, $orderId, $userId);
    if ($order === null) {
        api_error('سفارش یافت نشد', 404);
    }

    if ($method === 'GET') {
        $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
        $wait = isset($_GET['wait']) && (string) $_GET['wait'] === '1';
        order_messages_mark_read_for_client($pdo, $orderId);

        $messages = $wait
            ? order_messages_wait($pdo, $orderId, $sinceId, (int) ($_GET['timeout'] ?? 25))
            : order_messages_fetch($pdo, $orderId, $sinceId);

        api_json([
            'ok' => true,
            'order_id' => $orderId,
            'messages' => $messages,
            'unread_count' => order_messages_unread_count_for_client($pdo, $orderId),
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = site_auth_request_json();
    $text = trim((string) ($body['body'] ?? ''));
    $displayName = trim((string) ($user['phone'] ?? ''));
    if ($displayName === '') {
        $displayName = 'مشتری';
    }

    $message = order_messages_post_client(
        $pdo,
        $orderId,
        $userId,
        $displayName,
        $text
    );

    api_json([
        'ok' => true,
        'message' => $message,
    ]);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
