<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/application-logs.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $admin = admin_auth_require_user($pdo);
    if (!application_logs_can_view((string) $admin['username'])) {
        api_error('دسترسی به گزارش رویدادها مجاز نیست', 403);
    }

    application_logs_ensure_schema($pdo);

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id > 0) {
        $row = application_logs_get($pdo, $id);
        if ($row === null) {
            api_error('رویداد یافت نشد', 404);
        }
        $siblings = [];
        $corr = trim((string) ($row['correlation_id'] ?? ''));
        if ($corr !== '') {
            $siblings = application_logs_correlation_siblings($pdo, $corr, $id);
        }
        api_json([
            'ok' => true,
            'log' => $row,
            'related' => $siblings,
        ]);
    }

    $filters = [
        'from' => (string) ($_GET['from'] ?? ''),
        'to' => (string) ($_GET['to'] ?? ''),
        'severity' => (string) ($_GET['severity'] ?? ''),
        'source' => (string) ($_GET['source'] ?? ''),
        'category' => (string) ($_GET['category'] ?? ''),
        'event_code' => (string) ($_GET['event_code'] ?? ''),
        'user_id' => (string) ($_GET['user_id'] ?? ''),
        'order_id' => (string) ($_GET['order_id'] ?? ''),
        'correlation_id' => (string) ($_GET['correlation_id'] ?? ''),
        'q' => (string) ($_GET['q'] ?? ''),
    ];
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $pageSize = isset($_GET['page_size']) ? (int) $_GET['page_size'] : 40;
    $list = application_logs_list($pdo, $filters, $page, $pageSize);
    $summary = application_logs_summary($pdo, $filters);

    api_json([
        'ok' => true,
        'summary' => $summary,
        'items' => $list['items'],
        'total' => $list['total'],
        'page' => $list['page'],
        'total_pages' => $list['total_pages'],
    ]);
} catch (Throwable $e) {
    error_log('[admin-system-logs] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
