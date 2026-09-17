<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';
require_once dirname(__DIR__) . '/cms/lib/order-messages.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    orders_ensure_schema($pdo);
    order_messages_ensure_schema($pdo);
    $admin = admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    if ($orderId <= 0 && $method === 'POST') {
        $body = admin_auth_request_json();
        $orderId = (int) ($body['order_id'] ?? 0);
    }
    if ($orderId <= 0) {
        api_error('شناسه سفارش نامعتبر است', 400);
    }

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        api_error('سفارش یافت نشد', 404);
    }

    if ($method === 'GET') {
        $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
        $wait = isset($_GET['wait']) && (string) $_GET['wait'] === '1';
        order_messages_mark_read_for_admin($pdo, $orderId);

        $messages = $wait
            ? order_messages_wait($pdo, $orderId, $sinceId, (int) ($_GET['timeout'] ?? 25))
            : order_messages_fetch($pdo, $orderId, $sinceId);

        api_json([
            'ok' => true,
            'order_id' => $orderId,
            'messages' => $messages,
            'unread_count' => order_messages_unread_count_for_admin($pdo, $orderId),
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    $text = trim((string) ($body['body'] ?? ''));
    $message = order_messages_post_admin(
        $pdo,
        $orderId,
        (int) $admin['id'],
        (string) $admin['username'],
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
