<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/sales-direct-messages.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    sales_direct_messages_ensure_schema($pdo);
    $admin = admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET' && isset($_GET['threads']) && (string) $_GET['threads'] === '1') {
        api_json([
            'ok' => true,
            'threads' => sales_direct_messages_threads_for_admin($pdo),
            'unread_count' => sales_direct_messages_unread_total_for_admin($pdo),
        ]);
    }

    $salesUserId = isset($_GET['sales_user_id']) ? (int) $_GET['sales_user_id'] : 0;
    if ($salesUserId <= 0 && $method === 'POST') {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'multipart/form-data') !== false) {
            $salesUserId = (int) ($_POST['sales_user_id'] ?? 0);
        } else {
            $payload = admin_auth_request_json();
            $salesUserId = (int) ($payload['sales_user_id'] ?? 0);
        }
    }
    if ($salesUserId <= 0) {
        api_error('شناسه کاربر فروش نامعتبر است', 400);
    }

    if ($method === 'GET') {
        $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
        $wait = isset($_GET['wait']) && (string) $_GET['wait'] === '1';
        sales_direct_messages_mark_read_for_admin($pdo, $salesUserId);

        $messages = $wait
            ? sales_direct_messages_wait($pdo, $salesUserId, $sinceId, (int) ($_GET['timeout'] ?? 25))
            : sales_direct_messages_fetch($pdo, $salesUserId, $sinceId);

        api_json([
            'ok' => true,
            'sales_user_id' => $salesUserId,
            'messages' => $messages,
            'unread_count' => sales_direct_messages_unread_count_for_admin($pdo, $salesUserId),
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
        $payload = admin_auth_request_json();
        $text = trim((string) ($payload['body'] ?? ''));
    }

    $message = sales_direct_messages_insert(
        $pdo,
        $salesUserId,
        'admin',
        (string) ($admin['username'] ?? 'ادمین'),
        $text,
        $image,
        (int) $admin['id']
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
