<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/flash-deals.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    flash_deals_ensure_schema($pdo);
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $deal = flash_deals_get($pdo, $id);
            if ($deal === null) {
                api_error('کمپین یافت نشد', 404);
            }
            api_json(['ok' => true, 'campaign' => $deal]);
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if (isset($_GET['product_q'])) {
            api_json([
                'ok' => true,
                'products' => flash_deals_search_products($pdo, (string) $_GET['product_q']),
            ]);
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = flash_deals_list($pdo, [
            'q' => $q,
            'status' => trim((string) ($_GET['status'] ?? '')),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        ], $page);
        api_json([
            'ok' => true,
            'campaigns' => $list['items'],
            'total' => $list['total'],
            'page' => $list['page'],
            'total_pages' => $list['total_pages'],
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    $action = trim((string) ($body['action'] ?? 'save'));
    if ($action === 'search_products') {
        api_json([
            'ok' => true,
            'products' => flash_deals_search_products($pdo, (string) ($body['q'] ?? '')),
        ]);
    }
    if (in_array($action, ['pause', 'resume', 'end', 'archive', 'duplicate', 'delete'], true)) {
        $result = flash_deals_action(
            $pdo,
            (int) ($body['id'] ?? 0),
            $action,
            (int) ($body['version'] ?? 0)
        );
        api_json(['ok' => true, 'campaign' => $result]);
    }

    $saved = flash_deals_save($pdo, $body);
    api_json(['ok' => true, 'campaign' => $saved], ((int) ($body['id'] ?? 0) > 0) ? 200 : 201);
} catch (FlashDealException $e) {
    $failBody = (isset($body) && is_array($body)) ? $body : [];
    flash_deals_audit_failure($pdo, (int) ($failBody['id'] ?? 0), (string) ($failBody['action'] ?? 'save'), $e->getMessage());
    flash_deals_emit_api_error($e);
} catch (InvalidArgumentException $e) {
    $failBody = (isset($body) && is_array($body)) ? $body : [];
    flash_deals_audit_failure($pdo, (int) ($failBody['id'] ?? 0), (string) ($failBody['action'] ?? 'save'), $e->getMessage());
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-flash-deals] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
