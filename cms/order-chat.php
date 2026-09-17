<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/orders.php';
require_once __DIR__ . '/lib/order-messages.php';

cms_require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = cms_pdo();
    orders_ensure_schema($pdo);
    order_messages_ensure_schema($pdo);

    $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    if ($orderId <= 0 && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $orderId = (int) ($body['order_id'] ?? 0);
        }
    }
    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'شناسه سفارش نامعتبر است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'سفارش یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $chatOpen = orders_chat_send_allowed((string) $order['status']);

    if ($method === 'GET') {
        $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
        $wait = isset($_GET['wait']) && (string) $_GET['wait'] === '1';
        order_messages_mark_read_for_admin($pdo, $orderId);

        $messages = $wait
            ? order_messages_wait($pdo, $orderId, $sinceId, (int) ($_GET['timeout'] ?? 25))
            : order_messages_fetch($pdo, $orderId, $sinceId);

        echo json_encode([
            'ok' => true,
            'order_id' => $orderId,
            'messages' => $messages,
            'unread_count' => order_messages_unread_count_for_admin($pdo, $orderId),
            'chat_open' => $chatOpen,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'بدنه درخواست نامعتبر است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $admin = cms_current_admin();
    if ($admin === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'لطفاً وارد شوید'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $text = trim((string) ($body['body'] ?? ''));
    $message = order_messages_post_admin(
        $pdo,
        $orderId,
        (int) $admin['id'],
        (string) $admin['username'],
        $text
    );

    echo json_encode([
        'ok' => true,
        'message' => $message,
        'chat_open' => $chatOpen,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
