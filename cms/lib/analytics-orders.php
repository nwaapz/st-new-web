<?php
declare(strict_types=1);

require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/order-cheques.php';

function analytics_orders_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    orders_ensure_schema($pdo);
    order_cheques_ensure_schema($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS analytics_order_line_facts (
          line_id INT UNSIGNED NOT NULL,
          order_id INT UNSIGNED NOT NULL,
          order_public_code VARCHAR(32) NOT NULL,
          order_status VARCHAR(32) NOT NULL,
          order_channel VARCHAR(16) NOT NULL,
          order_created_at DATETIME NOT NULL,
          product_id INT UNSIGNED NULL,
          product_name VARCHAR(191) NOT NULL,
          factory_name VARCHAR(191) NULL,
          model_name VARCHAR(191) NULL,
          category_name VARCHAR(191) NULL,
          visual_id VARCHAR(64) NULL,
          quantity INT UNSIGNED NOT NULL DEFAULT 1,
          unit_type VARCHAR(8) NOT NULL DEFAULT \'piece\',
          pack_size INT UNSIGNED NULL,
          unit_price_toman BIGINT NULL,
          line_revenue_toman BIGINT NULL,
          refreshed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (line_id),
          KEY idx_aolf_order (order_id),
          KEY idx_aolf_created (order_created_at),
          KEY idx_aolf_channel (order_channel),
          KEY idx_aolf_category (category_name(64))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS analytics_order_facts (
          order_id INT UNSIGNED NOT NULL,
          public_code VARCHAR(32) NOT NULL,
          status VARCHAR(32) NOT NULL,
          channel VARCHAR(16) NOT NULL,
          phone VARCHAR(20) NOT NULL,
          user_id INT UNSIGNED NOT NULL,
          sales_user_id INT UNSIGNED NULL,
          sales_user_name VARCHAR(128) NULL,
          branch_id INT UNSIGNED NULL,
          branch_name VARCHAR(191) NULL,
          branch_city VARCHAR(191) NULL,
          branch_province_name VARCHAR(191) NULL,
          item_count INT UNSIGNED NOT NULL DEFAULT 0,
          total_units INT UNSIGNED NOT NULL DEFAULT 0,
          revenue_toman BIGINT NOT NULL DEFAULT 0,
          has_priced_lines TINYINT(1) NOT NULL DEFAULT 0,
          cheque_count INT UNSIGNED NOT NULL DEFAULT 0,
          age_days INT NOT NULL DEFAULT 0,
          is_open_backlog TINYINT(1) NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL,
          updated_at DATETIME NOT NULL,
          refreshed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (order_id),
          KEY idx_aof_status (status),
          KEY idx_aof_channel (channel),
          KEY idx_aof_created (created_at),
          KEY idx_aof_sales_user (sales_user_id),
          KEY idx_aof_branch (branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS analytics_order_stage_times (
          order_id INT UNSIGNED NOT NULL,
          submitted_at DATETIME NULL,
          accepted_at DATETIME NULL,
          payment_proof_sent_at DATETIME NULL,
          paid_at DATETIME NULL,
          shipped_at DATETIME NULL,
          received_at DATETIME NULL,
          rejected_at DATETIME NULL,
          hours_to_accept INT NULL,
          hours_to_paid INT NULL,
          hours_to_received INT NULL,
          accepted_within_24h TINYINT(1) NOT NULL DEFAULT 0,
          refreshed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS analytics_order_cheque_facts (
          cheque_id INT UNSIGNED NOT NULL,
          order_id INT UNSIGNED NOT NULL,
          order_public_code VARCHAR(32) NOT NULL,
          order_status VARCHAR(32) NOT NULL,
          order_channel VARCHAR(16) NOT NULL,
          received_on DATE NOT NULL,
          due_on DATE NOT NULL,
          serial VARCHAR(64) NULL,
          amount_text VARCHAR(128) NULL,
          amount_toman BIGINT NULL,
          bank_result VARCHAR(16) NULL,
          days_to_due INT NOT NULL DEFAULT 0,
          due_within_2_days TINYINT(1) NOT NULL DEFAULT 0,
          is_pending TINYINT(1) NOT NULL DEFAULT 1,
          order_created_at DATETIME NOT NULL,
          refreshed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (cheque_id),
          KEY idx_aocf_order (order_id),
          KEY idx_aocf_due (due_on),
          KEY idx_aocf_result (bank_result)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    analytics_orders_create_views($pdo);

    $ready = true;
}

function analytics_orders_create_views(PDO $pdo): void
{
    $views = [
        'vw_order_line_facts' => 'analytics_order_line_facts',
        'vw_order_facts' => 'analytics_order_facts',
        'vw_order_stage_times' => 'analytics_order_stage_times',
        'vw_order_cheque_facts' => 'analytics_order_cheque_facts',
    ];

    foreach ($views as $viewName => $tableName) {
        $pdo->exec("DROP VIEW IF EXISTS {$viewName}");
        $pdo->exec("CREATE VIEW {$viewName} AS SELECT * FROM {$tableName}");
    }
}

function analytics_orders_channel(array $order): string
{
    $salesUserId = isset($order['sales_user_id']) && $order['sales_user_id'] !== null
        ? (int) $order['sales_user_id']
        : 0;
    if ($salesUserId > 0) {
        return 'sales_app';
    }
    $branchId = isset($order['branch_id']) && $order['branch_id'] !== null
        ? (int) $order['branch_id']
        : 0;
    if ($branchId > 0) {
        return 'branch';
    }

    return 'direct_shop';
}

function analytics_orders_channel_label(string $channel): string
{
    switch ($channel) {
        case 'sales_app':
            return 'اپ فروش';
        case 'branch':
            return 'نماینده';
        default:
            return 'فروشگاه';
    }
}

/**
 * @return array{unit_price_toman:?int, line_revenue_toman:?int}
 */
function analytics_orders_line_amounts(array $item): array
{
    $qty = max(1, (int) ($item['quantity'] ?? 1));
    $unitType = isset($item['unit_type']) && (string) $item['unit_type'] === 'pack'
        ? 'pack'
        : 'piece';
    $packSize = isset($item['pack_size']) && $item['pack_size'] !== null
        ? (int) $item['pack_size']
        : 0;

    try {
        $piecePrice = invoices_parse_toman_amount(
            isset($item['price_text']) ? (string) $item['price_text'] : null
        );
    } catch (Throwable $e) {
        $piecePrice = null;
    }

    if ($piecePrice === null) {
        return ['unit_price_toman' => null, 'line_revenue_toman' => null];
    }

    if ($unitType === 'pack' && $packSize > 0) {
        $unitPrice = $piecePrice * $packSize;

        return [
            'unit_price_toman' => $unitPrice,
            'line_revenue_toman' => $unitPrice * $qty,
        ];
    }

    return [
        'unit_price_toman' => $piecePrice,
        'line_revenue_toman' => $piecePrice * $qty,
    ];
}

/**
 * Refresh all analytics fact tables from live operational data.
 *
 * @return array<string, int>
 */
function analytics_orders_refresh(PDO $pdo): array
{
    analytics_orders_ensure_schema($pdo);

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM analytics_order_line_facts');
        $pdo->exec('DELETE FROM analytics_order_facts');
        $pdo->exec('DELETE FROM analytics_order_stage_times');
        $pdo->exec('DELETE FROM analytics_order_cheque_facts');

        $orders = $pdo->query('SELECT * FROM orders ORDER BY id ASC')->fetchAll() ?: [];
        $lineStmt = $pdo->prepare(
            'INSERT INTO analytics_order_line_facts
             (line_id, order_id, order_public_code, order_status, order_channel, order_created_at,
              product_id, product_name, factory_name, model_name, category_name, visual_id,
              quantity, unit_type, pack_size, unit_price_toman, line_revenue_toman)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $factStmt = $pdo->prepare(
            'INSERT INTO analytics_order_facts
             (order_id, public_code, status, channel, phone, user_id, sales_user_id, sales_user_name,
              branch_id, branch_name, branch_city, branch_province_name, item_count, total_units,
              revenue_toman, has_priced_lines, cheque_count, age_days, is_open_backlog,
              created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        foreach ($orders as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $channel = analytics_orders_channel($order);
            $items = orders_fetch_items($pdo, $orderId);
            $revenue = 0;
            $totalUnits = 0;
            $hasPricedLines = 0;

            foreach ($items as $item) {
                $amounts = analytics_orders_line_amounts($item);
                if ($amounts['line_revenue_toman'] !== null) {
                    $revenue += (int) $amounts['line_revenue_toman'];
                    $hasPricedLines = 1;
                }
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $totalUnits += $qty;

                $lineStmt->execute([
                    (int) ($item['id'] ?? 0),
                    $orderId,
                    (string) ($order['public_code'] ?? ''),
                    (string) ($order['status'] ?? 'submitted'),
                    $channel,
                    (string) ($order['created_at'] ?? date('Y-m-d H:i:s')),
                    isset($item['product_id']) ? (int) $item['product_id'] : null,
                    (string) ($item['name'] ?? ''),
                    isset($item['factory_name']) ? (string) $item['factory_name'] : null,
                    isset($item['model_name']) ? (string) $item['model_name'] : null,
                    isset($item['category_name']) ? (string) $item['category_name'] : null,
                    isset($item['visual_id']) ? (string) $item['visual_id'] : null,
                    $qty,
                    isset($item['unit_type']) ? (string) $item['unit_type'] : 'piece',
                    isset($item['pack_size']) && $item['pack_size'] !== null ? (int) $item['pack_size'] : null,
                    $amounts['unit_price_toman'],
                    $amounts['line_revenue_toman'],
                ]);
            }

            $cheques = order_cheques_fetch($pdo, $orderId);
            $createdAt = (string) ($order['created_at'] ?? date('Y-m-d H:i:s'));
            $ageDays = (int) floor((time() - strtotime($createdAt)) / 86400);
            $status = (string) ($order['status'] ?? 'submitted');
            $isOpenBacklog = in_array($status, ['submitted', 'payment_proof_sent'], true) ? 1 : 0;

            $factStmt->execute([
                $orderId,
                (string) ($order['public_code'] ?? ''),
                $status,
                $channel,
                (string) ($order['phone'] ?? ''),
                (int) ($order['user_id'] ?? 0),
                isset($order['sales_user_id']) && $order['sales_user_id'] !== null
                    ? (int) $order['sales_user_id']
                    : null,
                isset($order['sales_user_name']) ? (string) $order['sales_user_name'] : null,
                isset($order['branch_id']) && $order['branch_id'] !== null
                    ? (int) $order['branch_id']
                    : null,
                isset($order['branch_name']) ? (string) $order['branch_name'] : null,
                isset($order['branch_city']) ? (string) $order['branch_city'] : null,
                isset($order['branch_province_name']) ? (string) $order['branch_province_name'] : null,
                count($items),
                $totalUnits,
                $revenue,
                $hasPricedLines,
                count($cheques),
                max(0, $ageDays),
                $isOpenBacklog,
                $createdAt,
                (string) ($order['updated_at'] ?? $createdAt),
            ]);
        }

        analytics_orders_refresh_stage_times($pdo);
        analytics_orders_refresh_cheque_facts($pdo);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return analytics_orders_counts($pdo);
}

function analytics_orders_refresh_stage_times(PDO $pdo): void
{
    $pdo->exec(
        'INSERT INTO analytics_order_stage_times
         (order_id, submitted_at, accepted_at, payment_proof_sent_at, paid_at, shipped_at,
          received_at, rejected_at, hours_to_accept, hours_to_paid, hours_to_received, accepted_within_24h)
         SELECT
           e.order_id,
           MIN(CASE WHEN e.to_status = \'submitted\' THEN e.created_at END) AS submitted_at,
           MIN(CASE WHEN e.to_status = \'accepted\' THEN e.created_at END) AS accepted_at,
           MIN(CASE WHEN e.to_status = \'payment_proof_sent\' THEN e.created_at END) AS payment_proof_sent_at,
           MIN(CASE WHEN e.to_status = \'paid\' THEN e.created_at END) AS paid_at,
           MIN(CASE WHEN e.to_status = \'shipped\' THEN e.created_at END) AS shipped_at,
           MIN(CASE WHEN e.to_status = \'received\' THEN e.created_at END) AS received_at,
           MIN(CASE WHEN e.to_status = \'rejected\' THEN e.created_at END) AS rejected_at,
           NULL,
           NULL,
           NULL,
           0
         FROM order_events e
         GROUP BY e.order_id'
    );

    $rows = $pdo->query(
        'SELECT order_id, submitted_at, accepted_at, paid_at, received_at
         FROM analytics_order_stage_times'
    )->fetchAll() ?: [];

    $update = $pdo->prepare(
        'UPDATE analytics_order_stage_times
         SET hours_to_accept = ?, hours_to_paid = ?, hours_to_received = ?, accepted_within_24h = ?
         WHERE order_id = ?'
    );

    foreach ($rows as $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        $submittedAt = $row['submitted_at'] ?? null;
        $acceptedAt = $row['accepted_at'] ?? null;
        $paidAt = $row['paid_at'] ?? null;
        $receivedAt = $row['received_at'] ?? null;

        $hoursToAccept = analytics_orders_hours_between($submittedAt, $acceptedAt);
        $hoursToPaid = analytics_orders_hours_between($submittedAt, $paidAt);
        $hoursToReceived = analytics_orders_hours_between($submittedAt, $receivedAt);
        $acceptedWithin24h = ($hoursToAccept !== null && $hoursToAccept <= 24) ? 1 : 0;

        $update->execute([
            $hoursToAccept,
            $hoursToPaid,
            $hoursToReceived,
            $acceptedWithin24h,
            $orderId,
        ]);
    }
}

function analytics_orders_hours_between(?string $from, ?string $to): ?int
{
    if ($from === null || $to === null || $from === '' || $to === '') {
        return null;
    }
    $start = strtotime($from);
    $end = strtotime($to);
    if ($start === false || $end === false || $end < $start) {
        return null;
    }

    return (int) round(($end - $start) / 3600);
}

function analytics_orders_refresh_cheque_facts(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT c.*, o.public_code, o.status, o.created_at AS order_created_at,
                o.sales_user_id, o.branch_id
         FROM order_cheques c
         INNER JOIN orders o ON o.id = c.order_id
         ORDER BY c.id ASC'
    );
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
    if ($rows === []) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO analytics_order_cheque_facts
         (cheque_id, order_id, order_public_code, order_status, order_channel, received_on, due_on,
          serial, amount_text, amount_toman, bank_result, days_to_due, due_within_2_days,
          is_pending, order_created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    foreach ($rows as $row) {
        $order = [
            'sales_user_id' => $row['sales_user_id'] ?? null,
            'branch_id' => $row['branch_id'] ?? null,
        ];
        $orderId = (int) ($row['order_id'] ?? 0);

        $amountToman = null;
        try {
            $amountToman = invoices_parse_toman_amount(
                isset($row['amount_text']) ? (string) $row['amount_text'] : null
            );
        } catch (Throwable $e) {
            $amountToman = null;
        }

        $dueOn = (string) ($row['due_on'] ?? date('Y-m-d'));
        $daysToDue = (int) ((strtotime($dueOn . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);
        $bankResult = isset($row['bank_result']) ? (string) $row['bank_result'] : null;
        $isPending = ($bankResult === null || $bankResult === '') ? 1 : 0;

        $insert->execute([
            (int) ($row['id'] ?? 0),
            $orderId,
            (string) ($row['public_code'] ?? ''),
            (string) ($row['status'] ?? 'submitted'),
            analytics_orders_channel($order),
            (string) ($row['received_on'] ?? date('Y-m-d')),
            $dueOn,
            isset($row['serial']) ? (string) $row['serial'] : null,
            isset($row['amount_text']) ? (string) $row['amount_text'] : null,
            $amountToman,
            $bankResult,
            $daysToDue,
            ($isPending === 1 && $daysToDue <= 2) ? 1 : 0,
            $isPending,
            (string) ($row['order_created_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }
}

/**
 * @return array<string, int>
 */
function analytics_orders_counts(PDO $pdo): array
{
    return [
        'orders' => (int) $pdo->query('SELECT COUNT(*) FROM analytics_order_facts')->fetchColumn(),
        'lines' => (int) $pdo->query('SELECT COUNT(*) FROM analytics_order_line_facts')->fetchColumn(),
        'stages' => (int) $pdo->query('SELECT COUNT(*) FROM analytics_order_stage_times')->fetchColumn(),
        'cheques' => (int) $pdo->query('SELECT COUNT(*) FROM analytics_order_cheque_facts')->fetchColumn(),
    ];
}

/**
 * @return array<string, mixed>
 */
function analytics_orders_summary(PDO $pdo): array
{
    analytics_orders_ensure_schema($pdo);

    $lastRefresh = $pdo->query(
        'SELECT MAX(refreshed_at) FROM analytics_order_facts'
    )->fetchColumn();

    return [
        'last_refreshed_at' => $lastRefresh ? (string) $lastRefresh : null,
        'counts' => analytics_orders_counts($pdo),
    ];
}

/**
 * Aggregated dashboard payload for native admin app analytics.
 *
 * @return array<string, mixed>
 */
function analytics_orders_dashboard(PDO $pdo, bool $forceRefresh = false): array
{
    analytics_orders_ensure_schema($pdo);

    $factCount = (int) $pdo->query('SELECT COUNT(*) FROM analytics_order_facts')->fetchColumn();
    if ($factCount === 0 || $forceRefresh) {
        analytics_orders_refresh($pdo);
    }

    $summary = analytics_orders_summary($pdo);
    $statusLabels = orders_status_labels();

    $kpis = [
        'orders_today' => (int) $pdo->query(
            'SELECT COUNT(*) FROM analytics_order_facts WHERE DATE(created_at) = CURDATE()'
        )->fetchColumn(),
        'orders_7d' => (int) $pdo->query(
            'SELECT COUNT(*) FROM analytics_order_facts
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)'
        )->fetchColumn(),
        'revenue_mtd' => (int) $pdo->query(
            'SELECT COALESCE(SUM(revenue_toman), 0) FROM analytics_order_facts
             WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())'
        )->fetchColumn(),
        'open_backlog' => (int) $pdo->query(
            'SELECT COUNT(*) FROM analytics_order_facts WHERE is_open_backlog = 1'
        )->fetchColumn(),
        'cheques_due_2d' => (int) $pdo->query(
            'SELECT COUNT(*) FROM analytics_order_cheque_facts
             WHERE due_within_2_days = 1 AND is_pending = 1'
        )->fetchColumn(),
        'sla_accept_24h_pct' => (int) round((float) ($pdo->query(
            'SELECT AVG(accepted_within_24h) * 100
             FROM analytics_order_stage_times
             WHERE accepted_at IS NOT NULL'
        )->fetchColumn() ?: 0)),
    ];

    $dailyTrend = [];
    $trendStmt = $pdo->query(
        'SELECT DATE(created_at) AS day_key, COUNT(*) AS order_count,
                COALESCE(SUM(revenue_toman), 0) AS revenue_toman
         FROM analytics_order_facts
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
         GROUP BY DATE(created_at)
         ORDER BY day_key ASC'
    );
    foreach ($trendStmt->fetchAll() ?: [] as $row) {
        $dailyTrend[] = [
            'date' => (string) ($row['day_key'] ?? ''),
            'orders' => (int) ($row['order_count'] ?? 0),
            'revenue_toman' => (int) ($row['revenue_toman'] ?? 0),
        ];
    }

    $byChannel = [];
    $channelStmt = $pdo->query(
        'SELECT channel, COUNT(*) AS order_count, COALESCE(SUM(revenue_toman), 0) AS revenue_toman
         FROM analytics_order_facts
         GROUP BY channel
         ORDER BY order_count DESC'
    );
    foreach ($channelStmt->fetchAll() ?: [] as $row) {
        $channel = (string) ($row['channel'] ?? '');
        $byChannel[] = [
            'channel' => $channel,
            'label' => analytics_orders_channel_label($channel),
            'orders' => (int) ($row['order_count'] ?? 0),
            'revenue_toman' => (int) ($row['revenue_toman'] ?? 0),
        ];
    }

    $byStatus = [];
    $statusStmt = $pdo->query(
        'SELECT status, COUNT(*) AS order_count,
                SUM(CASE WHEN age_days <= 1 THEN 1 ELSE 0 END) AS age_0_1,
                SUM(CASE WHEN age_days BETWEEN 2 AND 3 THEN 1 ELSE 0 END) AS age_2_3,
                SUM(CASE WHEN age_days BETWEEN 4 AND 7 THEN 1 ELSE 0 END) AS age_4_7,
                SUM(CASE WHEN age_days > 7 THEN 1 ELSE 0 END) AS age_7_plus
         FROM analytics_order_facts
         GROUP BY status
         ORDER BY order_count DESC'
    );
    foreach ($statusStmt->fetchAll() ?: [] as $row) {
        $status = (string) ($row['status'] ?? '');
        $byStatus[] = [
            'status' => $status,
            'label' => $statusLabels[$status] ?? $status,
            'orders' => (int) ($row['order_count'] ?? 0),
            'age_0_1' => (int) ($row['age_0_1'] ?? 0),
            'age_2_3' => (int) ($row['age_2_3'] ?? 0),
            'age_4_7' => (int) ($row['age_4_7'] ?? 0),
            'age_7_plus' => (int) ($row['age_7_plus'] ?? 0),
        ];
    }

    $funnelStages = [
        'submitted' => 'submitted_at',
        'accepted' => 'accepted_at',
        'payment_proof_sent' => 'payment_proof_sent_at',
        'paid' => 'paid_at',
        'shipped' => 'shipped_at',
        'received' => 'received_at',
    ];
    $funnel = [];
    foreach ($funnelStages as $stage => $column) {
        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM analytics_order_stage_times WHERE {$column} IS NOT NULL"
        )->fetchColumn();
        $funnel[] = [
            'stage' => $stage,
            'label' => $statusLabels[$stage] ?? $stage,
            'count' => $count,
        ];
    }

    $rejectedCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM analytics_order_facts WHERE status = \'rejected\''
    )->fetchColumn();
    $rejectedRevenue = (int) $pdo->query(
        'SELECT COALESCE(SUM(revenue_toman), 0) FROM analytics_order_facts WHERE status = \'rejected\''
    )->fetchColumn();
    $cancelledCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM analytics_order_facts WHERE status = \'cancelled\''
    )->fetchColumn();
    $cancelledRevenue = (int) $pdo->query(
        'SELECT COALESCE(SUM(revenue_toman), 0) FROM analytics_order_facts WHERE status = \'cancelled\''
    )->fetchColumn();
    $completedRevenue = (int) $pdo->query(
        'SELECT COALESCE(SUM(revenue_toman), 0) FROM analytics_order_facts WHERE status = \'received\''
    )->fetchColumn();

    $topSalesUsers = [];
    $salesStmt = $pdo->query(
        'SELECT sales_user_name, COUNT(*) AS order_count,
                COALESCE(SUM(revenue_toman), 0) AS revenue_toman,
                SUM(CASE WHEN status = \'received\' THEN 1 ELSE 0 END) AS completed_count
         FROM analytics_order_facts
         WHERE sales_user_id IS NOT NULL AND sales_user_name IS NOT NULL AND sales_user_name <> \'\'
         GROUP BY sales_user_id, sales_user_name
         ORDER BY revenue_toman DESC, order_count DESC
         LIMIT 10'
    );
    foreach ($salesStmt->fetchAll() ?: [] as $row) {
        $orderCount = (int) ($row['order_count'] ?? 0);
        $completed = (int) ($row['completed_count'] ?? 0);
        $topSalesUsers[] = [
            'name' => (string) ($row['sales_user_name'] ?? ''),
            'orders' => $orderCount,
            'revenue_toman' => (int) ($row['revenue_toman'] ?? 0),
            'completion_pct' => $orderCount > 0 ? (int) round($completed * 100 / $orderCount) : 0,
        ];
    }

    $topProvinces = [];
    $provinceStmt = $pdo->query(
        'SELECT branch_province_name, COUNT(*) AS order_count,
                COALESCE(SUM(revenue_toman), 0) AS revenue_toman
         FROM analytics_order_facts
         WHERE branch_province_name IS NOT NULL AND branch_province_name <> \'\'
         GROUP BY branch_province_name
         ORDER BY order_count DESC
         LIMIT 10'
    );
    foreach ($provinceStmt->fetchAll() ?: [] as $row) {
        $topProvinces[] = [
            'name' => (string) ($row['branch_province_name'] ?? ''),
            'orders' => (int) ($row['order_count'] ?? 0),
            'revenue_toman' => (int) ($row['revenue_toman'] ?? 0),
        ];
    }

    $topCategories = [];
    $categoryStmt = $pdo->query(
        'SELECT category_name, COALESCE(SUM(line_revenue_toman), 0) AS revenue_toman,
                COALESCE(SUM(quantity), 0) AS units
         FROM analytics_order_line_facts
         WHERE category_name IS NOT NULL AND category_name <> \'\'
         GROUP BY category_name
         ORDER BY revenue_toman DESC
         LIMIT 10'
    );
    foreach ($categoryStmt->fetchAll() ?: [] as $row) {
        $topCategories[] = [
            'name' => (string) ($row['category_name'] ?? ''),
            'revenue_toman' => (int) ($row['revenue_toman'] ?? 0),
            'units' => (int) ($row['units'] ?? 0),
        ];
    }

    $unitMix = [];
    $unitStmt = $pdo->query(
        'SELECT unit_type, COUNT(*) AS line_count, COALESCE(SUM(quantity), 0) AS units
         FROM analytics_order_line_facts
         GROUP BY unit_type'
    );
    foreach ($unitStmt->fetchAll() ?: [] as $row) {
        $unitType = (string) ($row['unit_type'] ?? 'piece');
        $unitMix[] = [
            'unit_type' => $unitType,
            'label' => $unitType === 'pack' ? 'بسته' : 'عدد',
            'lines' => (int) ($row['line_count'] ?? 0),
            'units' => (int) ($row['units'] ?? 0),
        ];
    }

    $chequeRow = $pdo->query(
        'SELECT
            SUM(CASE WHEN is_pending = 1 THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN bank_result = \'funded\' THEN 1 ELSE 0 END) AS funded_count,
            SUM(CASE WHEN bank_result = \'bounced\' THEN 1 ELSE 0 END) AS bounced_count,
            SUM(CASE WHEN due_within_2_days = 1 AND is_pending = 1 THEN 1 ELSE 0 END) AS due_soon_count,
            COUNT(*) AS total_count
         FROM analytics_order_cheque_facts'
    )->fetch() ?: [];

    $chequeTotal = (int) ($chequeRow['total_count'] ?? 0);
    $fundedCount = (int) ($chequeRow['funded_count'] ?? 0);
    $bouncedCount = (int) ($chequeRow['bounced_count'] ?? 0);
    $resolved = $fundedCount + $bouncedCount;

    return [
        'refreshed_at' => $summary['last_refreshed_at'],
        'kpis' => $kpis,
        'daily_trend' => $dailyTrend,
        'by_channel' => $byChannel,
        'by_status' => $byStatus,
        'funnel' => $funnel,
        'revenue_waterfall' => [
            'completed_toman' => $completedRevenue,
            'rejected_orders' => $rejectedCount,
            'rejected_toman' => $rejectedRevenue,
            'cancelled_orders' => $cancelledCount,
            'cancelled_toman' => $cancelledRevenue,
        ],
        'top_sales_users' => $topSalesUsers,
        'top_provinces' => $topProvinces,
        'top_categories' => $topCategories,
        'unit_mix' => $unitMix,
        'cheques' => [
            'pending' => (int) ($chequeRow['pending_count'] ?? 0),
            'funded' => $fundedCount,
            'bounced' => $bouncedCount,
            'due_soon' => (int) ($chequeRow['due_soon_count'] ?? 0),
            'funded_pct' => $resolved > 0 ? (int) round($fundedCount * 100 / $resolved) : 0,
            'bounce_pct' => $resolved > 0 ? (int) round($bouncedCount * 100 / $resolved) : 0,
        ],
    ];
}
