<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/application-logs.php';

/**
 * Delete expired operational logs. Does not touch admin_audit_log or order_events.
 * GET/POST /api/app-logs-cleanup.php?key=SECRET
 */

try {
    $given = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
    $secret = application_logs_cleanup_secret();
    if ($secret === '' || $given === '' || strlen($given) !== strlen($secret) || !hash_equals($secret, $given)) {
        api_error('Forbidden', 403);
    }

    $pdo = cms_pdo();
    $deleted = application_logs_cleanup($pdo, APP_LOG_CLEANUP_BATCH * 4);
    api_json([
        'ok' => true,
        'deleted' => $deleted,
        'retention_days' => application_logs_retention_days(),
    ]);
} catch (Throwable $e) {
    error_log('[app-logs-cleanup] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
