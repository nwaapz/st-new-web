<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/cheque-control.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $view = trim((string) ($_GET['view'] ?? 'dashboard'));
    $forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';

    if ($view === 'dashboard') {
        api_json([
            'ok' => true,
            'dashboard' => cheque_control_dashboard($pdo, $forceRefresh),
        ]);
    }

    if ($view === 'list') {
        $filter = trim((string) ($_GET['filter'] ?? 'all'));
        $query = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($_GET['per_page'] ?? 20)));
        $result = cheque_control_list($pdo, $filter, $query, $page, $perPage, $forceRefresh);
        api_json([
            'ok' => true,
            'cheques' => $result['cheques'],
            'total' => $result['total'],
            'page' => $result['page'],
            'total_pages' => $result['total_pages'],
        ]);
    }

    if ($view === 'detail') {
        $chequeId = (int) ($_GET['id'] ?? 0);
        if ($chequeId <= 0) {
            api_error('شناسه چک الزامی است', 400);
        }
        $detail = cheque_control_detail($pdo, $chequeId, $forceRefresh);
        if ($detail === null) {
            api_error('چک یافت نشد', 404);
        }
        api_json([
            'ok' => true,
            'detail' => $detail,
        ]);
    }

    api_error('نمای نامعتبر', 400);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
