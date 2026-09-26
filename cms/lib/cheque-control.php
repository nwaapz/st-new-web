<?php
declare(strict_types=1);

require_once __DIR__ . '/analytics-orders.php';
require_once __DIR__ . '/order-cheques.php';
require_once __DIR__ . '/cheque-workflow.php';
require_once __DIR__ . '/customer-credit.php';
require_once __DIR__ . '/cheque-follow-ups.php';

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

    cheque_workflow_ensure_schema($pdo);
    $pendingVerification = (int) $pdo->query(
        "SELECT COUNT(*) FROM order_cheques
         WHERE workflow_status = 'pending_verification'"
    )->fetchColumn();
    if ($pendingVerification > 0) {
        $actions[] = [
            'key' => 'pending_verification',
            'filter' => 'pending_verification',
            'label' => $pendingVerification . ' چک در انتظار تأیید',
            'count' => $pendingVerification,
        ];
    }

    $highCredit = customer_credit_high_utilization_count($pdo);
    if ($highCredit > 0) {
        $actions[] = [
            'key' => 'high_credit',
            'filter' => 'customers',
            'label' => $highCredit . ' مشتری با استفاده بالای اعتبار',
            'count' => $highCredit,
        ];
    }

    return $actions;
}

/**
 * @param array<string, mixed> $row
 * @return array{risk_level: string, risk_reasons: list<string>}
 */
function cheque_control_risk_for_row(PDO $pdo, array $row): array
{
    $reasons = [];
    $level = 'normal';
    $daysToDue = (int) ($row['days_to_due'] ?? 0);
    $amount = (int) ($row['amount_toman'] ?? 0);
    $phone = isset($row['phone']) ? trim((string) $row['phone']) : '';

    if ($daysToDue < 0 && (int) ($row['is_pending'] ?? 0) === 1) {
        $level = 'warning';
        $reasons[] = 'سررسید گذشته';
    }
    if ($amount >= cheque_control_high_value_threshold()) {
        $level = $level === 'warning' ? 'warning' : 'elevated';
        $reasons[] = 'مبلغ بالا';
    }
    if ($phone !== '') {
        $summary = customer_credit_summary($pdo, $phone);
        $creditLevel = (string) ($summary['risk_level'] ?? 'normal');
        if ($creditLevel === 'blocked') {
            $level = 'blocked';
        } elseif ($creditLevel === 'warning' && $level !== 'blocked') {
            $level = 'warning';
        }
        foreach ($summary['risk_reasons'] ?? [] as $reason) {
            $reasons[] = (string) $reason;
        }
    }
    $bankResult = trim((string) ($row['bank_result'] ?? ''));
    if ($bankResult === 'bounced') {
        $level = 'blocked';
        $reasons[] = 'چک برگشتی';
    }

    return [
        'risk_level' => $level,
        'risk_reasons' => array_values(array_unique($reasons)),
    ];
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
        case 'pending_verification':
            $where[] = "f.workflow_status = 'pending_verification'";
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
            f.serial LIKE ? OR f.sayad_id LIKE ? OR f.order_public_code LIKE ?
            OR f.customer_name LIKE ? OR f.sales_user_name LIKE ? OR f.phone LIKE ?
            OR f.bank_name LIKE ?
        )';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
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
        $cheques[] = cheque_control_serialize_list_row($pdo, $row);
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
function cheque_control_serialize_list_row(PDO $pdo, array $row): array
{
    $workflowStatus = trim((string) ($row['workflow_status'] ?? ''));
    if ($workflowStatus === '') {
        $workflowStatus = cheque_workflow_resolve_status($row);
    }
    $labels = cheque_workflow_status_labels();
    $risk = cheque_control_risk_for_row($pdo, $row);

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
        'sayad_id' => isset($row['sayad_id']) ? (string) $row['sayad_id'] : null,
        'bank_name' => isset($row['bank_name']) ? (string) $row['bank_name'] : null,
        'workflow_status' => $workflowStatus,
        'workflow_status_label' => $labels[$workflowStatus] ?? cheque_control_status_label($row),
        'amount_text' => isset($row['amount_text']) ? (string) $row['amount_text'] : null,
        'amount_toman' => isset($row['amount_toman']) ? (int) $row['amount_toman'] : null,
        'bank_result' => isset($row['bank_result']) && $row['bank_result'] !== ''
            ? (string) $row['bank_result']
            : null,
        'status_label' => $labels[$workflowStatus] ?? cheque_control_status_label($row),
        'days_to_due' => (int) ($row['days_to_due'] ?? 0),
        'is_pending' => (int) ($row['is_pending'] ?? 0) === 1,
        'risk_level' => $risk['risk_level'],
        'risk_reasons' => $risk['risk_reasons'],
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

    $chequeRow = order_cheques_get($pdo, $chequeId);
    $workflowStatus = $chequeRow !== null
        ? cheque_workflow_resolve_status($chequeRow)
        : cheque_workflow_resolve_status($row);
    $workflowSteps = cheque_workflow_timeline_steps($workflowStatus);
    $statusHistory = cheque_workflow_fetch_history($pdo, $chequeId);
    $allowedActions = cheque_workflow_allowed_actions($workflowStatus);

    $phone = (string) ($order['phone'] ?? '');
    $creditSummary = $phone !== '' ? customer_credit_summary($pdo, $phone) : null;
    $followUps = cheque_follow_ups_fetch($pdo, $chequeId, $phone, 20);

    $preInvoice = isset($order['pre_invoice_file']) ? (string) $order['pre_invoice_file'] : '';
    $finalInvoice = isset($order['final_invoice_file']) ? (string) $order['final_invoice_file'] : '';
    $uploadBase = function_exists('cms_upload_url') ? '' : '/uploads/';
    $invoiceLinks = [
        'pre_invoice_url' => $preInvoice !== ''
            ? (function_exists('cms_upload_url') ? cms_upload_url($preInvoice) : $uploadBase . ltrim($preInvoice, '/'))
            : null,
        'final_invoice_url' => $finalInvoice !== ''
            ? (function_exists('cms_upload_url') ? cms_upload_url($finalInvoice) : $uploadBase . ltrim($finalInvoice, '/'))
            : null,
    ];

    return [
        'cheque' => array_merge(
            cheque_control_serialize_list_row($pdo, $row),
            $chequeFromOrder ?: [],
            $chequeRow ? order_cheques_serialize_row($chequeRow) : []
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
            'invoice_links' => $invoiceLinks,
        ],
        'timeline' => $timeline,
        'workflow_steps' => $workflowSteps,
        'status_history' => $statusHistory,
        'allowed_actions' => $allowedActions,
        'credit_summary' => $creditSummary,
        'follow_ups' => $followUps,
    ];
}

/**
 * @return array{customers: list<array<string, mixed>>, total: int, page: int, total_pages: int}
 */
function cheque_control_customers(PDO $pdo, string $query = '', int $page = 1, int $perPage = 20): array
{
    return customer_credit_list($pdo, $query, $page, $perPage);
}

/**
 * @return array<string, mixed>|null
 */
function cheque_control_customer_detail(PDO $pdo, string $phone): ?array
{
    $phone = customer_credit_normalize_phone($phone);
    if ($phone === '') {
        return null;
    }

    return customer_credit_detail($pdo, $phone);
}

/**
 * @return list<array<string, mixed>>
 */
function cheque_control_representatives(PDO $pdo): array
{
    cheque_control_ensure_facts($pdo);
    $stmt = $pdo->query(
        "SELECT sales_user_id, sales_user_name,
                COUNT(*) AS cheque_count,
                COALESCE(SUM(COALESCE(amount_toman, 0)), 0) AS total_toman,
                COALESCE(SUM(CASE WHEN is_pending = 1 AND days_to_due < 0 THEN COALESCE(amount_toman, 0) ELSE 0 END), 0) AS overdue_toman,
                SUM(CASE WHEN bank_result = 'bounced' THEN 1 ELSE 0 END) AS bounced_count,
                SUM(CASE WHEN bank_result = 'funded' THEN 1 ELSE 0 END) AS cleared_count
         FROM analytics_order_cheque_facts
         WHERE sales_user_id IS NOT NULL
         GROUP BY sales_user_id, sales_user_name
         ORDER BY overdue_toman DESC, bounced_count DESC"
    );
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    $out = [];
    foreach ($rows as $row) {
        $cleared = (int) ($row['cleared_count'] ?? 0);
        $bounced = (int) ($row['bounced_count'] ?? 0);
        $settled = $cleared + $bounced;
        $onTimePct = $settled > 0 ? round(($cleared / $settled) * 100, 1) : 0.0;
        $out[] = [
            'id' => (int) ($row['sales_user_id'] ?? 0),
            'name' => (string) ($row['sales_user_name'] ?? ''),
            'cheque_count' => (int) ($row['cheque_count'] ?? 0),
            'total_toman' => (int) ($row['total_toman'] ?? 0),
            'overdue_toman' => (int) ($row['overdue_toman'] ?? 0),
            'bounced_count' => $bounced,
            'on_time_pct' => $onTimePct,
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function cheque_control_representative_detail(PDO $pdo, int $salesUserId): ?array
{
    if ($salesUserId <= 0) {
        return null;
    }
    $reps = cheque_control_representatives($pdo);
    $rep = null;
    foreach ($reps as $item) {
        if ((int) ($item['id'] ?? 0) === $salesUserId) {
            $rep = $item;
            break;
        }
    }
    if ($rep === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM analytics_order_cheque_facts WHERE sales_user_id = ?
         ORDER BY due_on ASC LIMIT 50'
    );
    $stmt->execute([$salesUserId]);
    $cheques = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $cheques[] = cheque_control_serialize_list_row($pdo, $row);
    }
    $rep['cheques'] = $cheques;

    return $rep;
}

/**
 * @return list<array<string, mixed>>
 */
function cheque_control_bank_summary(PDO $pdo): array
{
    cheque_control_ensure_facts($pdo);
    $stmt = $pdo->query(
        "SELECT COALESCE(bank_name, 'نامشخص') AS bank_name,
                COALESCE(SUM(CASE WHEN is_pending = 1 THEN COALESCE(amount_toman, 0) ELSE 0 END), 0) AS pending_toman,
                COALESCE(SUM(CASE WHEN bank_result = 'bounced' THEN COALESCE(amount_toman, 0) ELSE 0 END), 0) AS returned_toman,
                COUNT(*) AS cheque_count
         FROM analytics_order_cheque_facts
         GROUP BY COALESCE(bank_name, 'نامشخص')
         ORDER BY pending_toman DESC"
    );
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'bank_name' => (string) ($row['bank_name'] ?? 'نامشخص'),
            'pending_toman' => (int) ($row['pending_toman'] ?? 0),
            'returned_toman' => (int) ($row['returned_toman'] ?? 0),
            'cheque_count' => (int) ($row['cheque_count'] ?? 0),
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function cheque_control_reports(PDO $pdo): array
{
    cheque_control_ensure_facts($pdo);

    $monthly = [];
    for ($i = 5; $i >= 0; $i--) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(COALESCE(f.amount_toman, 0)), 0) AS collected_toman
             FROM analytics_order_cheque_facts f
             INNER JOIN order_cheques c ON c.id = f.cheque_id
             WHERE f.bank_result = 'funded'
               AND YEAR(c.updated_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL ? MONTH))
               AND MONTH(c.updated_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL ? MONTH))"
        );
        $stmt->execute([$i, $i]);
        $row = $stmt->fetch() ?: [];
        $monthly[] = [
            'month_offset' => $i,
            'collected_toman' => (int) ($row['collected_toman'] ?? 0),
        ];
    }

    $totalStmt = $pdo->query(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN bank_result = \'bounced\' THEN 1 ELSE 0 END) AS bounced
         FROM analytics_order_cheque_facts'
    );
    $totalRow = $totalStmt ? ($totalStmt->fetch() ?: []) : [];
    $total = (int) ($totalRow['total'] ?? 0);
    $bounced = (int) ($totalRow['bounced'] ?? 0);
    $bounceRate = $total > 0 ? round(($bounced / $total) * 100, 1) : 0.0;

    $aging = [];
    $buckets = [
        ['key' => 'current', 'label' => 'جاری', 'min' => 0, 'max' => 7],
        ['key' => '8_30', 'label' => '۸ تا ۳۰ روز', 'min' => 8, 'max' => 30],
        ['key' => '31_60', 'label' => '۳۱ تا ۶۰ روز', 'min' => 31, 'max' => 60],
        ['key' => '61_90', 'label' => '۶۱ تا ۹۰ روز', 'min' => 61, 'max' => 90],
        ['key' => 'over_90', 'label' => 'بیش از ۹۰ روز', 'min' => 91, 'max' => 9999],
        ['key' => 'overdue', 'label' => 'معوق', 'min' => -9999, 'max' => -1],
    ];
    foreach ($buckets as $bucket) {
        if ($bucket['key'] === 'overdue') {
            $stmt = $pdo->query(
                'SELECT COALESCE(SUM(COALESCE(amount_toman, 0)), 0) AS amount_toman, COUNT(*) AS count
                 FROM analytics_order_cheque_facts WHERE is_pending = 1 AND days_to_due < 0'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(COALESCE(amount_toman, 0)), 0) AS amount_toman, COUNT(*) AS count
                 FROM analytics_order_cheque_facts
                 WHERE is_pending = 1 AND days_to_due BETWEEN ? AND ?'
            );
            $stmt->execute([(int) $bucket['min'], (int) $bucket['max']]);
        }
        $row = $stmt ? ($stmt->fetch() ?: []) : [];
        $aging[] = [
            'key' => $bucket['key'],
            'label' => $bucket['label'],
            'amount_toman' => (int) ($row['amount_toman'] ?? 0),
            'count' => (int) ($row['count'] ?? 0),
        ];
    }

    return [
        'monthly_collection' => $monthly,
        'bounce_rate_pct' => $bounceRate,
        'bounced_count' => $bounced,
        'total_cheques' => $total,
        'aging_buckets' => $aging,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function cheque_control_audit(PDO $pdo, int $chequeId): array
{
    cheque_workflow_ensure_schema($pdo);
    $entries = [];
    foreach (cheque_workflow_fetch_history($pdo, $chequeId) as $item) {
        $entries[] = [
            'source' => 'status_history',
            'action' => ($item['from_label'] ?? '—') . ' → ' . ($item['to_label'] ?? ''),
            'actor' => (string) ($item['changed_by'] ?? ''),
            'note' => $item['note'] ?? null,
            'created_at' => (string) ($item['created_at'] ?? ''),
        ];
    }

    require_once __DIR__ . '/admin-audit.php';
    admin_audit_ensure_schema($pdo);
    $auditStmt = $pdo->prepare(
        "SELECT * FROM admin_audit_log
         WHERE entity_type = 'cheque' AND entity_id = ?
         ORDER BY id DESC LIMIT 50"
    );
    $auditStmt->execute([$chequeId]);
    foreach ($auditStmt->fetchAll() ?: [] as $row) {
        $entries[] = [
            'source' => 'admin_audit',
            'action' => admin_audit_action_label((string) ($row['action'] ?? '')),
            'actor' => (string) ($row['admin_username'] ?? ''),
            'note' => isset($row['summary']) ? (string) $row['summary'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    usort($entries, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $entries;
}

/**
 * @return array<string, mixed>
 */
function cheque_control_dashboard_extended(PDO $pdo, bool $forceRefresh = false): array
{
    $dashboard = cheque_control_dashboard($pdo, $forceRefresh);
    $dashboard['bank_summary'] = cheque_control_bank_summary($pdo);

    return $dashboard;
}
