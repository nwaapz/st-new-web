<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';
require_once dirname(__DIR__) . '/cms/lib/branches.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    orders_ensure_schema($pdo);
    branches_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $salesUser = sales_auth_current_user($pdo);
    if ($salesUser === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    $salesUserId = (int) $salesUser['id'];

    if ($method === 'GET') {
        $orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        if ($orderId > 0) {
            $stmt = $pdo->prepare(
                'SELECT * FROM orders WHERE id = ? AND sales_user_id = ? LIMIT 1'
            );
            $stmt->execute([$orderId, $salesUserId]);
            $order = $stmt->fetch();
            if (!$order) {
                api_error('سفارش یافت نشد', 404);
            }
            api_json([
                'ok' => true,
                'order' => orders_serialize(
                    $order,
                    orders_fetch_items($pdo, (int) $order['id']),
                    orders_fetch_events($pdo, (int) $order['id'])
                ),
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM orders WHERE sales_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100'
        );
        $stmt->execute([$salesUserId]);
        $rows = $stmt->fetchAll() ?: [];
        $orders = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $orders[] = orders_serialize(
                $row,
                orders_fetch_items($pdo, $id),
                orders_fetch_events($pdo, $id)
            );
        }
        api_json(['ok' => true, 'orders' => $orders]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = sales_auth_request_json();
    $rawItems = $body['items'] ?? null;
    if (!is_array($rawItems) || $rawItems === []) {
        api_error('سبد خرید خالی است', 400);
    }

    $normalized = orders_normalize_cart_items($pdo, $rawItems);
    if ($normalized === []) {
        api_error('اقلام سفارش نامعتبر است', 400);
    }

    $siteUser = orders_resolve_site_user_for_sales($pdo, $salesUser);
    $branchId = isset($salesUser['branch_id']) && $salesUser['branch_id'] !== null
        ? (int) $salesUser['branch_id']
        : 0;
    $branchSnap = orders_branch_snapshot_for_branch_id($pdo, $branchId, $siteUser['phone']);
    $submitNote = $branchSnap['branch_id']
        ? 'سفارش از اپ فروش ثبت شد'
        : 'سفارش از اپ فروش ثبت شد';

    $orderId = orders_create_from_normalized(
        $pdo,
        (int) $siteUser['id'],
        (string) $siteUser['phone'],
        $normalized,
        $branchSnap,
        $salesUserId,
        $submitNote
    );

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        api_error('خطا در ایجاد سفارش', 500);
    }

    api_json([
        'ok' => true,
        'order' => orders_serialize(
            $order,
            orders_fetch_items($pdo, $orderId),
            orders_fetch_events($pdo, $orderId)
        ),
    ], 201);
} catch (Throwable $e) {
    error_log('[sales-orders] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
