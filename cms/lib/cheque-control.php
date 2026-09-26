<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics-orders.php';
require_once __DIR__ . '/order-cheques.php';

/** Default high-value cheque threshold in toman (500M). */
function cheque_control_high_value_threshold(): int
{
    return 500_000_000;
}

function cheque_control_ensure_facts(PDO $pdo, bool $forceRefresh = false): void
{
    analytics_orders_ensure_schema($pdo);
    order_cheques_ensure_schema($pdo);

    $chequeCount = (int) $pdo->query('SELECT COUNT(*) FROM order_cheques')->fetchColumn();
    $factCount = (int) $pdo->query('SELECT COUNT(*) FROM analytics_order_cheque_facts')->fetchColumn();

    if ($forceRefresh || $chequeCount !== $factCount) {
        analytics_orders_sync_cheque_facts($pdo);
    }
}

/**
 * @return array<string, mixed>
 */
function cheque_control_dashboard(PDO $pdo, bool $forceRefresh = false): array
{
    cheque_control_ensure_facts($pdo, $forceRefresh);

    $lastRefresh = $pdo->query(
        'SELECT MAX(refreshed_at) FROM analytics_order_cheque_facts'
    )->fetchColumn();

    $kpiRow = $pdo->query(
        'SELECT
            COALESCE(SUM(CASE WHEN is_pending = 1 THEN COALESCE(amount_toman, 0) ELSE 0 END), 0) AS outstanding_toman,
            COALESCE(SUM(CASE WHEN is_pending = 1 AND days_to_due BETWEEN 0 AND 7 THEN COALESCE(amount_toman, 0) ELSE 0 END), 0) AS due_7d_toman,
            COALESCE(SUM(CASE WHEN is_pending = 1 AND days_to_due < 0 THEN COALESCE(amount_toman, 0) ELSE 0 END), 0) AS overdue_toman,
            COALESCE(SUM(CASE WHEN bank_result = \'bounced\' THEN COALESCE(amount_toman, 0) ELSE 0 END), 0) AS returned_toman,
            SUM(CASE WHEN bank_result = \'bounced\' THEN 1 ELSE 0 END) AS returned_count,
            COALESCE(SUM(CASE WHEN is_pending = 1 AND days_to_due < 0 THEN COALESCE(amount_toman, 0) ELSE 0 END), 0) AS at_risk_toman
         FROM analytics_order_cheque_facts'
    )->fetch() ?: [];

    $collectedStmt = $pdo->query(
        'SELECT COALESCE(SUM(COALESCE(f.amount_toman, 0)), 0) AS collected_toman
         FROM analytics_order_cheque_facts f
         INNER JOIN order_cheques c ON c.id = f.cheque_id
         WHERE f.bank_result = \'funded\'
           AND YEAR(c.updated_at) = YEAR(CURDATE())
           AND MONTH(c.updated_at) = MONTH(CURDATE())'
    );
    $collectedRow = $collectedStmt ? ($collectedStmt->fetch() ?: []) : [];

    $forecast = [];
    foreach ([7, 30, 60, 90] as $days) {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(COALESCE(amount_toman, 0)), 0) AS amount_toman
             FROM analytics_order_cheque_facts
             WHERE is_pending = 1 AND days_to_due BETWEEN 0 AND ?'
        );
        $stmt->execute([$days]);
        $row = $stmt->fetch() ?: [];
        $forecast[] = [
            'days' => $days,
            'amount_toman' => (int) ($row['amount_toman'] ?? 0),
        ];
    }

    $actions = cheque_control_action_items($pdo);

    return [
        'refreshed_at' => $lastRefresh ? (string) $lastRefresh : null,
        'kpis' => [
            'outstanding_toman' => (int) ($kpiRow['outstanding_toman'] ?? 0),
            'due_7d_toman' => (int) ($kpiRow['due_7d_toman'] ?? 0),
            'overdue_toman' => (int) ($kpiRow['overdue_toman'] ?? 0),
            'returned_toman' => (int) ($kpiRow['returned_toman'] ?? 0),
            'returned_count' => (int) ($kpiRow['returned_count'] ?? 0),
            'collected_month_toman' => (int) ($collectedRow['collected_toman'] ?? 0),
            'at_risk_toman' => (int) ($kpiRow['at_risk_toman'] ?? 0),
        ],
        'forecast' => $forecast,
        'actions' => $actions,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function cheque_control_action_items(PDO $pdo): array
{
    $actions = [];

    $dueToday = (int) $pdo->query(
        'SELECT COUNT(*) FROM analytics_order_cheque_facts
         WHERE is_pending = 1 AND due_on = CURDATE()'
    )->fetchColumn();
    if ($dueToday > 0) {
        $actions[] = [
            'key' => 'due_today',
            'filter' => 'today',
            'label' => $dueToday . ' چک سررسید امروز',
            'count' => $dueToday,
        ];
    }

    $overdue = (int) $pdo->query(
        'SELECT COUNT(*) FROM analytics_order_cheque_facts
         WHERE is_pending = 1 AND days_to_due < 0'
    )->fetchColumn();
    if ($overdue > 0) {
        $actions[] = [
            'key' => 'overdue',
            'filter' => 'overdue',
            'label' => $overdue . ' چک سررسید گذشته',
            'count' => $overdue,
        ];
    }

    $bounced = (int) $pdo->query(
        'SELECT COUNT(*) FROM analytics_order_cheque_facts
         WHERE bank_result = \'bounced\''
    )->fetchColumn();
    if ($bounced > 0) {
        $actions[] = [
            'key' => 'returned',
            'filter' => 'returned',
            'label' => $bounced . ' چک برگشتی نیازمند پیگیری',
            'count' => $bounced,
        ];
    }

    $threshold = cheque_control_high_value_threshold();
    $highValueStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM analytics_order_cheque_facts
         WHERE is_pending = 1 AND COALESCE(amount_toman, 0) >= ?'
    );
    $highValueStmt->execute([$threshold]);
    $highValue = (int) $highValueStmt->fetchColumn();
    if ($highValue > 0) {
        $actions[] = [
            'key' => 'high_value',
            'filter' => 'high_value',
            'label' => $highValue . ' چک با مبلغ بالا در انتظار',
            'count' => $highValue,
        ];
    }

    return $actions;
}

/**
 * @return array{cheques: list<array<string, mixed>>, total: int, page: int, total_pages: int}
 */
function cheque_control_list(
    PDO $pdo,
    string $filter = 'all',
    string $query = '',
    int $page = 1,
    int $perPage = 20,
    bool $forceRefresh = false
): array {
    cheque_control_ensure_facts($pdo, $forceRefresh);

    $page = max(1, $page);
    $perPage = max(1, min(50, $perPage));
    $offset = ($page - 1) * $perPage;

    $where = ['1=1'];
    $params = [];

    switch ($filter) {
        case 'today':
            $where[] = 'f.is_pending = 1 AND f.due_on = CURDATE()';
            break;
        case 'next_7':
            $where[] = 'f.is_pending = 1 AND f.days_to_due BETWEEN 0 AND 7';
            break;
        case 'next_30':
            $where[] = 'f.is_pending = 1 AND f.days_to_due BETWEEN 0 AND 30';
            break;
        case 'next_60':
            $where[] = 'f.is_pending = 1 AND f.days_to_due BETWEEN 0 AND 60';
            break;
        case 'next_90':
            $where[] = 'f.is_pending = 1 AND f.days_to_due BETWEEN 0 AND 90';
            break;
        case 'overdue':
            $where[] = 'f.is_pending = 1 AND f.days_to_due < 0';
            break;
        case 'returned':
            $where[] = 'f.bank_result = \'bounced\'';
            break;
        case 'pending':
            $where[] = 'f.is_pending = 1';
            break;
        case 'high_value':
            $where[] = 'f.is_pending = 1 AND COALESCE(f.amount_toman, 0) >= ?';
            $params[] = cheque_control_high_value_threshold();
            break;
        case 'at_risk':
            $where[] = 'f.is_pending = 1 AND f.days_to_due < 0';
            break;
        case 'all':
        default:
            break;
    }

    $q = trim($query);
    if ($q !== '') {
        $where[] = '(
            f.serial LIKE ? OR f.order_public_code LIKE ? OR f.customer_name LIKE ?
            OR f.sales_user_name LIKE ? OR f.phone LIKE ?
        )';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM analytics_order_cheque_facts f WHERE {$whereSql}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

    $listStmt = $pdo->prepare(
        "SELECT f.*
         FROM analytics_order_cheque_facts f
         WHERE {$whereSql}
         ORDER BY f.due_on ASC, f.cheque_id ASC
         LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset
    );
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll() ?: [];

    $cheques = [];
    foreach ($rows as $row) {
        $cheques[] = cheque_control_serialize_list_row($row);
    }

    return [
        'cheques' => $cheques,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function cheque_control_serialize_list_row(array $row): array
{
    return [
        'id' => (int) ($row['cheque_id'] ?? 0),
        'order_id' => (int) ($row['order_id'] ?? 0),
        'order_public_code' => (string) ($row['order_public_code'] ?? ''),
        'customer_name' => isset($row['customer_name']) ? (string) $row['customer_name'] : null,
        'phone' => isset($row['phone']) ? (string) $row['phone'] : null,
        'sales_user_name' => isset($row['sales_user_name']) ? (string) $row['sales_user_name'] : null,
        'received_on' => (string) ($row['received_on'] ?? ''),
        'due_on' => (string) ($row['due_on'] ?? ''),
        'serial' => isset($row['serial']) ? (string) $row['serial'] : null,
        'amount_text' => isset($row['amount_text']) ? (string) $row['amount_text'] : null,
        'amount_toman' => isset($row['amount_toman']) ? (int) $row['amount_toman'] : null,
        'bank_result' => isset($row['bank_result']) && $row['bank_result'] !== ''
            ? (string) $row['bank_result']
            : null,
        'status_label' => cheque_control_status_label($row),
        'days_to_due' => (int) ($row['days_to_due'] ?? 0),
        'is_pending' => (int) ($row['is_pending'] ?? 0) === 1,
    ];
}

/**
 * @param array<string, mixed> $row
 */
function cheque_control_status_label(array $row): string
{
    $bankResult = isset($row['bank_result']) ? trim((string) $row['bank_result']) : '';
    if ($bankResult === 'funded') {
        return 'وصول شده';
    }
    if ($bankResult === 'bounced') {
        return 'برگشت خورده';
    }
    $daysToDue = (int) ($row['days_to_due'] ?? 0);
    if ($daysToDue < 0) {
        return 'سررسید گذشته';
    }

    return 'در انتظار وصول';
}

/**
 * @return array<string, mixed>|null
 */
function cheque_control_detail(PDO $pdo, int $chequeId, bool $forceRefresh = false): ?array
{
    cheque_control_ensure_facts($pdo, $forceRefresh);
    if ($chequeId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT f.* FROM analytics_order_cheque_facts f WHERE f.cheque_id = ? LIMIT 1'
    );
    $stmt->execute([$chequeId]);
    $row = $stmt->fetch();
    if (!$row) {
        $chequeRow = order_cheques_get($pdo, $chequeId);
        if ($chequeRow === null) {
            return null;
        }
        analytics_orders_sync_cheque_facts($pdo);
        $stmt->execute([$chequeId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
    }

    $orderId = (int) ($row['order_id'] ?? 0);
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        return null;
    }

    $items = orders_fetch_items($pdo, $orderId);
    $orderPayload = orders_admin_serialize(
        $order,
        $items,
        orders_fetch_events($pdo, $orderId)
    );

    $chequeEvents = [
        'cheque_received',
        'cheque_due_soon',
        'cheque_funded',
        'cheque_bounced',
    ];
    $timeline = [];
    foreach ($orderPayload['events'] ?? [] as $event) {
        $toStatus = (string) ($event['to_status'] ?? '');
        if (!in_array($toStatus, $chequeEvents, true)) {
            continue;
        }
        $timeline[] = [
            'type' => $toStatus,
            'label' => order_cheques_event_labels()[$toStatus] ?? $toStatus,
            'message' => isset($event['message']) ? (string) $event['message'] : null,
            'created_at' => (string) ($event['created_at'] ?? ''),
            'actor' => (string) ($event['actor'] ?? ''),
        ];
    }

    $chequeFromOrder = null;
    foreach ($orderPayload['cheques'] ?? [] as $cheque) {
        if ((int) ($cheque['id'] ?? 0) === $chequeId) {
            $chequeFromOrder = $cheque;
            break;
        }
    }

    return [
        'cheque' => array_merge(
            cheque_control_serialize_list_row($row),
            $chequeFromOrder ?: []
        ),
        'order' => [
            'id' => $orderId,
            'public_code' => (string) ($order['public_code'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'status_label' => orders_status_labels()[(string) ($order['status'] ?? '')] ?? '',
            'customer_name' => $orderPayload['customer_name'] ?? null,
            'phone' => (string) ($order['phone'] ?? ''),
            'sales_user_name' => $orderPayload['sales_user_name'] ?? null,
            'payment_method' => $orderPayload['payment_method'] ?? null,
        ],
        'timeline' => $timeline,
    ];
}
