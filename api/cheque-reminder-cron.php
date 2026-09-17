<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';
require_once dirname(__DIR__) . '/cms/lib/order-cheques.php';
require_once dirname(__DIR__) . '/cms/lib/sales-push.php';
require_once dirname(__DIR__) . '/cms/lib/melipayamak.php';
require_once dirname(__DIR__) . '/cms/lib/jalali.php';

/**
 * Remind cheque senders 2 days before due_on, at 21:30–22:29 Asia/Tehran.
 * GET/POST /api/cheque-reminder-cron.php?key=SECRET
 */

try {
    date_default_timezone_set('Asia/Tehran');
    $pdo = cms_pdo();
    $pdo->exec("SET time_zone = '+03:30'");
    orders_ensure_schema($pdo);
    order_cheques_ensure_schema($pdo);

    $given = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
    $secret = order_cheques_cron_secret();
    if ($given === '' || strlen($given) !== strlen($secret) || !hash_equals($secret, $given)) {
        api_error('Forbidden', 403);
    }

    $force = isset($_GET['force']) || isset($_POST['force']);
    $result = order_cheques_send_due_reminders($pdo, $force);
    api_json([
        'ok' => true,
        'skipped' => $result['skipped'],
        'reason' => $result['reason'] ?? null,
        'sent' => $result['sent'],
        'failed' => $result['failed'],
    ]);
} catch (Throwable $e) {
    error_log('[cheque-reminder-cron] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
