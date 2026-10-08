<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/sales-direct-messages.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    sales_direct_messages_ensure_schema($pdo);
    $salesUser = sales_auth_current_user($pdo);
    if ($salesUser === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }
    $salesUserId = (int) $salesUser['id'];

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        if (isset($_GET['unread']) && (string) $_GET['unread'] === '1') {
            api_json([
                'ok' => true,
                'unread_count' => sales_direct_messages_unread_count_for_sales($pdo, $salesUserId),
            ]);
        }

        if (isset($_GET['timeline']) && (string) $_GET['timeline'] === '1') {
            sales_direct_messages_mark_read_for_sales($pdo, $salesUserId);
            sales_direct_messages_mark_order_chats_read_for_sales($pdo, $salesUserId);
            api_json([
                'ok' => true,
                'sales_user_id' => $salesUserId,
                'messages' => sales_direct_messages_timeline_for_sales($pdo, $salesUserId),
                'unread_count' => 0,
            ]);
        }

        $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
        $wait = isset($_GET['wait']) && (string) $_GET['wait'] === '1';
        sales_direct_messages_mark_read_for_sales($pdo, $salesUserId);

        $messages = $wait
            ? sales_direct_messages_wait($pdo, $salesUserId, $sinceId, (int) ($_GET['timeout'] ?? 25))
            : sales_direct_messages_fetch($pdo, $salesUserId, $sinceId);

        api_json([
            'ok' => true,
            'sales_user_id' => $salesUserId,
            'messages' => $messages,
            'unread_count' => 0,
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $isMultipart = strpos($contentType, 'multipart/form-data') !== false;
    $text = '';
    $image = null;
    if ($isMultipart) {
        $text = isset($_POST['body']) ? trim((string) $_POST['body']) : '';
        $image = sales_direct_messages_handle_image_upload('image');
    } else {
        $payload = sales_auth_request_json();
        $text = trim((string) ($payload['body'] ?? ''));
    }

    $displayName = trim((string) ($salesUser['display_name'] ?? ''));
    if ($displayName === '') {
        $displayName = (string) ($salesUser['username'] ?? 'فروشنده');
    }

    $message = sales_direct_messages_insert(
        $pdo,
        $salesUserId,
        'sales',
        $displayName,
        $text,
        $image,
        null
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
