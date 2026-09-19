<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';
require_once dirname(__DIR__) . '/cms/lib/invoices.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    orders_ensure_schema($pdo);
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        if ($orderId > 0) {
            $order = orders_get_by_id($pdo, $orderId);
            if ($order === null) {
                api_error('سفارش یافت نشد', 404);
            }
            $items = orders_fetch_items($pdo, $orderId);
            api_json([
                'ok' => true,
                'pricing_api_version' => 2,
                'order' => orders_admin_serialize(
                    $order,
                    $items,
                    orders_fetch_events($pdo, $orderId)
                ),
                'totals' => invoices_totals_from_items($items),
                'status_labels' => orders_status_labels(),
                'allowed_transitions' => orders_allowed_transitions()[(string) $order['status']] ?? [],
                'can_delete' => orders_can_delete((string) $order['status']),
            ]);
        }

        $scope = isset($_GET['scope']) ? trim((string) $_GET['scope']) : 'customers';
        $status = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
        $ongoingMode = isset($_GET['ongoing_mode']) ? trim((string) $_GET['ongoing_mode']) : 'new_order';
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;

        $list = orders_admin_list($pdo, $scope, $status, $q, $page, $perPage, $ongoingMode);
        api_json([
            'ok' => true,
            'orders' => $list['items'],
            'total' => $list['total'],
            'page' => $list['page'],
            'per_page' => $list['per_page'],
            'total_pages' => $list['total_pages'],
            'submitted_count' => $list['submitted_count'],
            'status_labels' => orders_status_labels(),
            'all_statuses' => orders_all_statuses(),
            'ongoing_modes' => orders_ongoing_mode_labels(),
            'ongoing_mode' => orders_normalize_ongoing_mode($ongoingMode),
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    $orderId = (int) ($body['order_id'] ?? $body['id'] ?? 0);
    $action = trim((string) ($body['action'] ?? ''));
    $message = trim((string) ($body['message'] ?? ''));
    $preInvoiceDueAt = trim((string) ($body['pre_invoice_due_at'] ?? ''));
    $cheque = [
        'id' => (int) ($body['cheque_id'] ?? 0),
        'received_on' => trim((string) ($body['received_on'] ?? '')),
        'due_on' => trim((string) ($body['due_on'] ?? '')),
        'serial' => trim((string) ($body['serial'] ?? '')),
        'amount_text' => trim((string) ($body['amount_text'] ?? '')),
        'bank_result' => trim((string) ($body['bank_result'] ?? '')),
    ];

    $prices = [];
    if (isset($body['prices']) && is_array($body['prices'])) {
        foreach ($body['prices'] as $itemId => $priceRaw) {
            $prices[(string) $itemId] = (string) $priceRaw;
        }
    }

    if ($orderId <= 0 || $action === '') {
        api_error('سفارش و عملیات الزامی است', 400);
    }

    $result = orders_admin_apply_action($pdo, $orderId, $action, $message, $prices, $preInvoiceDueAt, $cheque);

    if ($action === 'delete' || $action === 'remove' || !empty($result['deleted'])) {
        api_json([
            'ok' => true,
            'deleted' => true,
            'message' => $result['message'],
        ]);
    }

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        api_error('سفارش یافت نشد', 404);
    }

    $items = orders_fetch_items($pdo, $orderId);
    $response = [
        'ok' => true,
        'pricing_api_version' => 2,
        'message' => $result['message'],
        'order' => orders_admin_serialize(
            $order,
            $items,
            orders_fetch_events($pdo, $orderId)
        ),
        'totals' => invoices_totals_from_items($items),
        'allowed_transitions' => orders_allowed_transitions()[(string) $order['status']] ?? [],
        'can_delete' => orders_can_delete((string) $order['status']),
    ];
    if ($result['invoice_warning'] !== null) {
        $response['invoice_warning'] = $result['invoice_warning'];
    }

    api_json($response);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[admin-orders] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $message = trim($e->getMessage());
    api_error($message !== '' ? $message : 'خطای سرور', 500);
}
