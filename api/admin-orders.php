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
            $orderPayload = orders_admin_serialize(
                $order,
                $items,
                orders_fetch_events($pdo, $orderId)
            );
            api_json([
                'ok' => true,
                'pricing_api_version' => 2,
                'order' => $orderPayload,
                'totals' => invoices_display_totals_from_items($orderPayload['items'] ?? $items),
                'status_labels' => orders_status_labels(),
                'allowed_transitions' => orders_allowed_transitions()[(string) $order['status']] ?? [],
                'can_delete' => orders_can_delete((string) $order['status']),
            ]);
        }

        $view = isset($_GET['view']) ? trim((string) $_GET['view']) : '';
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;

        $scope = isset($_GET['scope']) ? trim((string) $_GET['scope']) : 'all';

        if ($view === 'manual_sale_meta') {
            $meta = orders_admin_manual_sale_meta($pdo);
            api_json([
                'ok' => true,
                'branches' => $meta['branches'],
                'series_supported' => $meta['series_supported'],
            ]);
        }

        if ($view === 'clients') {
            if ($perPage < 1) {
                $perPage = 30;
            }
            $list = orders_admin_clients_list($pdo, $q, $page, $perPage, $scope);
            api_json([
                'ok' => true,
                'clients' => $list['items'],
                'total' => $list['total'],
                'page' => $list['page'],
                'per_page' => $list['per_page'],
                'total_pages' => $list['total_pages'],
            ]);
        }

        $status = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
        $ongoingMode = isset($_GET['ongoing_mode']) ? trim((string) $_GET['ongoing_mode']) : 'new_order';
        $phone = isset($_GET['phone']) ? trim((string) $_GET['phone']) : '';
        $branchId = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;
        $createdSinceDays = orders_normalize_created_since_days($_GET['created_since_days'] ?? 0);

        $list = orders_admin_list(
            $pdo,
            $scope,
            $status,
            $q,
            $page,
            $perPage,
            $ongoingMode,
            $phone,
            $branchId,
            $createdSinceDays
        );
        api_json([
            'ok' => true,
            'orders' => $list['items'],
            'total' => $list['total'],
            'page' => $list['page'],
            'per_page' => $list['per_page'],
            'total_pages' => $list['total_pages'],
            'submitted_count' => $list['submitted_count'],
            'branch_submitted_count' => $list['branch_submitted_count'] ?? 0,
            'list_scope' => $list['list_scope'] ?? $scope,
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
    $action = trim((string) ($body['action'] ?? ''));
    if (
        $action === ''
        && isset($body['customer_type'], $body['items'])
        && is_array($body['items'])
        && (int) ($body['order_id'] ?? $body['id'] ?? 0) <= 0
    ) {
        $action = 'create';
    }
    if ($action === 'create') {
        $adminUser = admin_auth_current_user($pdo);
        $result = orders_admin_create_manual($pdo, $body, $adminUser);
        api_json($result, 201);
    }

    $orderId = (int) ($body['order_id'] ?? $body['id'] ?? 0);
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

    $itemAdjustments = [];
    if (isset($body['item_adjustments']) && is_array($body['item_adjustments'])) {
        foreach ($body['item_adjustments'] as $itemId => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $itemAdjustments[(string) $itemId] = $payload;
        }
    }

    if ($orderId <= 0 || $action === '') {
        api_error('سفارش و عملیات الزامی است', 400);
    }

    $paymentMethod = trim((string) ($body['payment_method'] ?? ''));
    $advancePricingStep = !empty($body['advance_pricing_step']);
    $result = orders_admin_apply_action(
        $pdo,
        $orderId,
        $action,
        $message,
        $prices,
        $preInvoiceDueAt,
        $cheque,
        $paymentMethod,
        $itemAdjustments,
        $advancePricingStep
    );

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
    $orderPayload = orders_admin_serialize(
        $order,
        $items,
        orders_fetch_events($pdo, $orderId)
    );
    $response = [
        'ok' => true,
        'pricing_api_version' => 2,
        'message' => $result['message'],
        'order' => $orderPayload,
        'totals' => invoices_display_totals_from_items($orderPayload['items'] ?? $items),
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
