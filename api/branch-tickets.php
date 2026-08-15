<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/branches.php';
require_once dirname(__DIR__) . '/cms/lib/branch-tickets.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    branches_ensure_schema($pdo);

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

    branches_sync_user_branch($pdo, (int) $user['id'], (string) $user['phone']);
    $branch = branches_for_user($pdo, (int) $user['id']);
    if ($branch === null) {
        api_error('فقط نمایندگان می‌توانند تیکت ثبت کنند', 403);
    }

    $userId = (int) $user['id'];
    $branchId = (int) $branch['id'];

    if ($method === 'GET') {
        $ticketId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($ticketId > 0) {
            $stmt = $pdo->prepare(
                'SELECT t.* FROM branch_tickets t
                 WHERE t.id = ? AND t.user_id = ? AND t.branch_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$ticketId, $userId, $branchId]);
            $ticket = $stmt->fetch();
            if (!$ticket) {
                api_error('تیکت یافت نشد', 404);
            }

            $pdo->prepare(
                "UPDATE branch_ticket_messages
                 SET branch_read_at = CURRENT_TIMESTAMP
                 WHERE ticket_id = ? AND actor = 'admin' AND branch_read_at IS NULL"
            )->execute([$ticketId]);

            $msgs = $pdo->prepare(
                'SELECT * FROM branch_ticket_messages
                 WHERE ticket_id = ?
                 ORDER BY created_at ASC, id ASC'
            );
            $msgs->execute([$ticketId]);
            $messages = [];
            foreach ($msgs->fetchAll() ?: [] as $m) {
                $messages[] = branch_tickets_serialize_message($m);
            }

            api_json([
                'ok' => true,
                'ticket' => branch_tickets_serialize_ticket($ticket),
                'messages' => $messages,
            ]);
        }

        $list = $pdo->prepare(
            "SELECT t.*,
              (SELECT COUNT(*) FROM branch_ticket_messages m
               WHERE m.ticket_id = t.id AND m.actor = 'admin' AND m.branch_read_at IS NULL
              ) AS unread
             FROM branch_tickets t
             WHERE t.user_id = ? AND t.branch_id = ?
             ORDER BY t.updated_at DESC, t.id DESC
             LIMIT 100"
        );
        $list->execute([$userId, $branchId]);
        $items = [];
        foreach ($list->fetchAll() ?: [] as $row) {
            $items[] = branch_tickets_serialize_ticket($row);
        }
        api_json(['ok' => true, 'items' => $items]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    $isMultipart = stripos($contentType, 'multipart/form-data') !== false;
    $payload = $isMultipart ? $_POST : site_auth_request_json();
    $action = trim((string) ($payload['action'] ?? 'create'));

    if ($action === 'create') {
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        if ($subject === '') {
            api_error('موضوع تیکت الزامی است', 400);
        }
        if (mb_strlen($subject) > 255) {
            api_error('موضوع خیلی طولانی است', 400);
        }
        $image = $isMultipart ? branch_tickets_handle_image_upload('image') : null;
        if ($body === '' && $image === null) {
            api_error('متن یا تصویر پیام الزامی است', 400);
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO branch_tickets (branch_id, user_id, subject, status)
                 VALUES (?, ?, ?, \'open\')'
            );
            $ins->execute([$branchId, $userId, $subject]);
            $ticketId = (int) $pdo->lastInsertId();

            $msg = $pdo->prepare(
                'INSERT INTO branch_ticket_messages
                 (ticket_id, actor, body, image, branch_read_at)
                 VALUES (?, \'branch\', ?, ?, CURRENT_TIMESTAMP)'
            );
            $msg->execute([
                $ticketId,
                $body !== '' ? $body : null,
                $image,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        api_json(['ok' => true, 'ticket_id' => $ticketId]);
    }

    if ($action === 'reply') {
        $ticketId = (int) ($payload['ticket_id'] ?? 0);
        $body = trim((string) ($payload['body'] ?? ''));
        if ($ticketId <= 0) {
            api_error('تیکت نامعتبر است', 400);
        }
        $check = $pdo->prepare(
            'SELECT id, status FROM branch_tickets
             WHERE id = ? AND user_id = ? AND branch_id = ? LIMIT 1'
        );
        $check->execute([$ticketId, $userId, $branchId]);
        $ticket = $check->fetch();
        if (!$ticket) {
            api_error('تیکت یافت نشد', 404);
        }
        if ((string) $ticket['status'] === 'closed') {
            api_error('این تیکت بسته شده است', 400);
        }

        $image = $isMultipart ? branch_tickets_handle_image_upload('image') : null;
        if ($body === '' && $image === null) {
            api_error('متن یا تصویر پیام الزامی است', 400);
        }
        if ($body !== '' && mb_strlen($body) > 4000) {
            api_error('پیام خیلی طولانی است', 400);
        }

        $msg = $pdo->prepare(
            'INSERT INTO branch_ticket_messages
             (ticket_id, actor, body, image, branch_read_at)
             VALUES (?, \'branch\', ?, ?, CURRENT_TIMESTAMP)'
        );
        $msg->execute([
            $ticketId,
            $body !== '' ? $body : null,
            $image,
        ]);
        $pdo->prepare(
            "UPDATE branch_tickets SET status = 'open', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        )->execute([$ticketId]);

        api_json(['ok' => true, 'ticket_id' => $ticketId]);
    }

    api_error('عملیات نامعتبر', 400);
} catch (Throwable $e) {
    error_log('[branch-tickets] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (
        strpos($msg, 'متن') === 0
        || strpos($msg, 'موضوع') === 0
        || strpos($msg, 'تصویر') === 0
        || strpos($msg, 'فقط') === 0
        || strpos($msg, 'آپلود') === 0
        || strpos($msg, 'تیکت') === 0
        || strpos($msg, 'حداکثر') === 0
        || strpos($msg, 'ساخت') === 0
        || strpos($msg, 'ذخیره') === 0
        || strpos($msg, 'فایل') === 0
    ) {
        api_error($msg, 400);
    }
    api_error('خطای سرور', 500);
}
