<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/messages.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    messages_ensure_schema($pdo);

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
        $markRead = !isset($_GET['mark_read']) || (string) $_GET['mark_read'] !== '0';
        $thread = messages_fetch_thread($pdo, $userId);
        if ($markRead) {
            messages_mark_admin_messages_read($pdo, $userId);
        }
        api_json([
            'ok' => true,
            'messages' => $thread,
            'unread' => messages_client_unread_summary($pdo, $userId),
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = site_auth_request_json();
    $text = isset($body['body']) ? (string) $body['body'] : '';
    $channel = isset($body['channel']) ? (string) $body['channel'] : 'support';
    $meta = ['channel' => 'support'];

    if ($channel === 'branch') {
        $branchId = isset($body['branch_id']) ? (int) $body['branch_id'] : 0;
        if ($branchId <= 0) {
            api_error('شعبه نامعتبر است', 400);
        }
        $stmt = $pdo->prepare(
            'SELECT id, name, province_code, province_name
             FROM branches
             WHERE id = ? AND published = 1
             LIMIT 1'
        );
        $stmt->execute([$branchId]);
        $branch = $stmt->fetch();
        if (!$branch) {
            api_error('شعبه یافت نشد', 404);
        }
        $meta = [
            'channel' => 'branch',
            'branch_id' => (int) $branch['id'],
            'province_code' => (string) $branch['province_code'],
            'province_name' => (string) $branch['province_name'],
            'branch_name' => (string) $branch['name'],
        ];
    }

    $msg = messages_add($pdo, $userId, $text, 'client', $meta);

    api_json([
        'ok' => true,
        'message' => $msg,
        'messages' => messages_fetch_thread($pdo, $userId),
        'unread' => messages_client_unread_summary($pdo, $userId),
    ]);
} catch (Throwable $e) {
    error_log('[messages] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (
        strpos($msg, 'متن') === 0
        || strpos($msg, 'پیام') === 0
        || strpos($msg, 'فرستنده') === 0
        || strpos($msg, 'شعبه') === 0
    ) {
        api_error($msg, 400);
    }
    api_error('خطای سرور', 500);
}
