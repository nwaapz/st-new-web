<?php
declare(strict_types=1);

/**
 * Shared order schema + helpers for CMS and public API.
 */

require_once __DIR__ . '/order-cheques.php';
require_once __DIR__ . '/schema-guard.php';

function orders_notify_sales_client(PDO $pdo, int $orderId, string $notifyType, string $message = ''): void
{
    if (!function_exists('orders_admin_notify_sales_client')) {
        $pushLib = __DIR__ . '/sales-push.php';
        if (is_readable($pushLib)) {
            require_once $pushLib;
        }
    }
    if (!function_exists('orders_admin_notify_sales_client')) {
        return;
    }
    orders_admin_notify_sales_client($pdo, $orderId, $notifyType, $message);
}

function orders_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (cms_schema_guard_done('orders', [__FILE__])) {
        $ready = true;
        order_cheques_ensure_schema($pdo);
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS orders (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          public_code VARCHAR(32) NOT NULL,
          user_id INT UNSIGNED NOT NULL,
          phone VARCHAR(20) NOT NULL,
          status ENUM('submitted','accepted','rejected','cancelled','payment_proof_sent','paid','shipped','not_received','returned_to_origin','lost','received') NOT NULL DEFAULT 'submitted',
          payment_note TEXT NULL,
          payment_file VARCHAR(512) NULL,
          payment_files TEXT NULL,
          payment_warning TEXT NULL,
          payment_warning_state VARCHAR(16) NULL,
          payment_submitted_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_orders_public_code (public_code),
          KEY idx_orders_user (user_id),
          KEY idx_orders_status (status),
          KEY idx_orders_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_items (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          order_id INT UNSIGNED NOT NULL,
          product_id INT UNSIGNED NULL,
          name VARCHAR(191) NOT NULL,
          slug VARCHAR(191) NOT NULL DEFAULT '',
          price_text VARCHAR(128) NULL,
          image VARCHAR(512) NULL,
          quantity INT UNSIGNED NOT NULL DEFAULT 1,
          unit_type ENUM('piece','pack') NOT NULL DEFAULT 'piece',
          pack_size INT UNSIGNED NULL,
          factory_name VARCHAR(191) NULL,
          model_name VARCHAR(191) NULL,
          category_name VARCHAR(191) NULL,
          PRIMARY KEY (id),
          KEY idx_order_items_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_events (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          order_id INT UNSIGNED NOT NULL,
          from_status VARCHAR(32) NULL,
          to_status VARCHAR(32) NOT NULL,
          message TEXT NULL,
          actor ENUM('client','admin') NOT NULL DEFAULT 'client',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_order_events_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    try {
        $eventCols = $pdo->query('SHOW COLUMNS FROM order_events')->fetchAll() ?: [];
        $eventColNames = [];
        foreach ($eventCols as $row) {
            $eventColNames[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($eventColNames['admin_user_id'])) {
            $pdo->exec(
                'ALTER TABLE order_events ADD COLUMN admin_user_id INT UNSIGNED NULL AFTER actor'
            );
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    // Upgrade existing installs: ENUM + payment columns.
    try {
        $pdo->exec(
            "ALTER TABLE orders
             MODIFY COLUMN status ENUM(
               'submitted','accepted','rejected','cancelled','payment_proof_sent','paid','shipped',
               'not_received','returned_to_origin','lost','received'
             ) NOT NULL DEFAULT 'submitted'"
        );
    } catch (Throwable $e) {
        /* ignore if already current */
    }

    $cols = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM orders');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cols[(string) ($row['Field'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        $cols = [];
    }

    if (!isset($cols['payment_note'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_note TEXT NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_file'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_file VARCHAR(512) NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_files'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_files TEXT NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_warning'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_warning TEXT NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_warning_state'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_warning_state VARCHAR(16) NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_submitted_at'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_submitted_at TIMESTAMP NULL DEFAULT NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_method'])) {
        try {
            $pdo->exec("ALTER TABLE orders ADD COLUMN payment_method ENUM('cash','cheque') NULL");
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['payment_reference'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_reference VARCHAR(64) NULL');
        } catch (Throwable $e) {
            /* ignore */
        }
    }

    foreach (
        [
            'pre_invoice_file' => 'VARCHAR(512) NULL',
            'pre_invoice_created_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'pre_invoice_due_at' => 'DATE NULL',
            'final_invoice_file' => 'VARCHAR(512) NULL',
            'final_invoice_created_at' => 'TIMESTAMP NULL DEFAULT NULL',
        ] as $col => $def
    ) {
        if (!isset($cols[$col])) {
            try {
                $pdo->exec("ALTER TABLE orders ADD COLUMN {$col} {$def}");
            } catch (Throwable $e) {
                /* ignore */
            }
        }
    }

    // Refresh cols after invoice adds (branch cols may still be missing).
    try {
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM orders');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $cols[(string) ($row['Field'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        /* keep previous */
    }

    foreach (
        [
            'branch_id' => 'INT UNSIGNED NULL',
            'branch_name' => 'VARCHAR(191) NULL',
            'branch_city' => 'VARCHAR(191) NULL',
            'branch_province_name' => 'VARCHAR(191) NULL',
            'branch_phone' => 'VARCHAR(20) NULL',
        ] as $col => $def
    ) {
        if (!isset($cols[$col])) {
            try {
                $pdo->exec("ALTER TABLE orders ADD COLUMN {$col} {$def}");
            } catch (Throwable $e) {
                /* ignore */
            }
        }
    }
    try {
        $pdo->exec('ALTER TABLE orders ADD KEY idx_orders_branch (branch_id)');
    } catch (Throwable $e) {
        /* exists */
    }

    if (!isset($cols['customer_name'])) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN customer_name VARCHAR(191) NULL AFTER phone');
            $cols['customer_name'] = true;
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['items_adjustment_pending'])) {
        try {
            $pdo->exec(
                'ALTER TABLE orders ADD COLUMN items_adjustment_pending TINYINT(1) NOT NULL DEFAULT 0'
            );
            $cols['items_adjustment_pending'] = true;
        } catch (Throwable $e) {
            /* ignore */
        }
    }
    if (!isset($cols['items_adjustment_confirmed_at'])) {
        try {
            $pdo->exec(
                'ALTER TABLE orders ADD COLUMN items_adjustment_confirmed_at TIMESTAMP NULL DEFAULT NULL'
            );
            $cols['items_adjustment_confirmed_at'] = true;
        } catch (Throwable $e) {
            /* ignore */
        }
    }

    // Backfill: existing warning text without state counts as open.
    try {
        $pdo->exec(
            "UPDATE orders
             SET payment_warning_state = 'open'
             WHERE payment_warning IS NOT NULL
               AND TRIM(payment_warning) <> ''
               AND (payment_warning_state IS NULL OR payment_warning_state = '')"
        );
    } catch (Throwable $e) {
        /* ignore */
    }

    // Migrate legacy single payment_file into payment_files JSON when empty.
    try {
        $pdo->exec(
            "UPDATE orders
             SET payment_files = CONCAT('[\"', REPLACE(payment_file, '\"', '\\\\\"'), '\"]')
             WHERE (payment_files IS NULL OR payment_files = '' OR payment_files = '[]')
               AND payment_file IS NOT NULL
               AND TRIM(payment_file) <> ''"
        );
    } catch (Throwable $e) {
        /* ignore */
    }

    // products.pack_size
    try {
        $prodCols = [];
        $ps = $pdo->query('SHOW COLUMNS FROM products');
        foreach ($ps->fetchAll() ?: [] as $row) {
            $prodCols[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($prodCols['pack_size'])) {
            $pdo->exec('ALTER TABLE products ADD COLUMN pack_size INT UNSIGNED NULL AFTER price_text');
        }
        if (!isset($prodCols['shop_display_image'])) {
            $pdo->exec('ALTER TABLE products ADD COLUMN shop_display_image VARCHAR(512) NULL');
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    // order_items.unit_type + pack_size
    try {
        $itemCols = [];
        $is = $pdo->query('SHOW COLUMNS FROM order_items');
        foreach ($is->fetchAll() ?: [] as $row) {
            $itemCols[(string) ($row['Field'] ?? '')] = true;
        }
        if (!isset($itemCols['unit_type'])) {
            $pdo->exec(
                "ALTER TABLE order_items
                 ADD COLUMN unit_type ENUM('piece','pack') NOT NULL DEFAULT 'piece' AFTER quantity"
            );
        }
        if (!isset($itemCols['pack_size'])) {
            $pdo->exec('ALTER TABLE order_items ADD COLUMN pack_size INT UNSIGNED NULL AFTER unit_type');
        }
        if (!isset($itemCols['visual_id'])) {
            $pdo->exec('ALTER TABLE order_items ADD COLUMN visual_id VARCHAR(64) NULL AFTER category_name');
        }
        if (!isset($itemCols['original_quantity'])) {
            $pdo->exec(
                'ALTER TABLE order_items ADD COLUMN original_quantity INT UNSIGNED NULL AFTER pack_size'
            );
        }
        if (!isset($itemCols['original_unit_type'])) {
            $pdo->exec(
                "ALTER TABLE order_items ADD COLUMN original_unit_type ENUM('piece','pack') NULL AFTER original_quantity"
            );
        }
        if (!isset($itemCols['original_pack_size'])) {
            $pdo->exec(
                'ALTER TABLE order_items ADD COLUMN original_pack_size INT UNSIGNED NULL AFTER original_unit_type'
            );
        }
        $pdo->exec(
            'UPDATE order_items
             SET original_quantity = quantity,
                 original_unit_type = unit_type,
                 original_pack_size = pack_size
             WHERE original_quantity IS NULL'
        );
    } catch (Throwable $e) {
        /* ignore */
    }

    try {
        $prodVisual = $pdo->query("SHOW COLUMNS FROM products LIKE 'visual_id'")->fetchAll();
        if (count($prodVisual) === 0) {
            $pdo->exec('ALTER TABLE products ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');
        }
        $visualIdx = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'uq_prod_visual_id'")->fetchAll();
        if (count($visualIdx) === 0) {
            $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_prod_visual_id (visual_id)');
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    try {
        $pdo->exec('ALTER TABLE orders ADD COLUMN sales_user_id INT UNSIGNED NULL AFTER user_id');
    } catch (Throwable $e) {
        /* ignore if exists */
    }
    try {
        $pdo->exec('ALTER TABLE orders ADD KEY idx_orders_sales_user (sales_user_id)');
    } catch (Throwable $e) {
        /* ignore if exists */
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM orders LIKE 'sales_user_name'")->fetchAll();
        if (count($col) === 0) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN sales_user_name VARCHAR(128) NULL AFTER sales_user_id');
        }
    } catch (Throwable $e) {
        /* ignore */
    }

    require_once __DIR__ . '/order-cheques.php';
    order_cheques_ensure_schema($pdo);

    cms_schema_guard_mark('orders', [__FILE__]);
    $ready = true;
}

/** @return array<string, string> */
function orders_status_labels(): array
{
    return [
        'submitted' => 'ارسال‌شده از مشتری',
        'accepted' => 'تأیید انبار',
        'rejected' => 'بایگانی — رد انبار',
        'cancelled' => 'لغو شده',
        'payment_proof_sent' => 'مدارک پرداخت ارسال شد',
        'paid' => 'پرداخت شده',
        'shipped' => 'ارسال مرسوله',
        'not_received' => 'هنوز دریافت نشده',
        'returned_to_origin' => 'برگشت به مبدأ',
        'lost' => 'مفقود',
        'received' => 'دریافت‌شده — تمام',
        'cheque_received' => 'دریافت چک',
        'cheque_due_soon' => 'یادآوری سررسید چک',
        'cheque_funded' => 'وصول چک',
        'cheque_bounced' => 'برگشت چک',
    ];
}

/** @return list<string> */
function orders_all_statuses(): array
{
    return [
        'submitted',
        'accepted',
        'rejected',
        'cancelled',
        'payment_proof_sent',
        'paid',
        'shipped',
        'not_received',
        'returned_to_origin',
        'lost',
        'received',
    ];
}

/**
 * Admin-allowed transitions (client moves accepted → payment_proof_sent via API).
 * After ship, order stays open until admin confirms received (finished).
 * @return array<string, list<string>>
 */
function orders_allowed_transitions(): array
{
    return [
        'submitted' => ['accepted', 'rejected', 'cancelled'],
        'accepted' => ['cancelled'],
        'rejected' => [],
        'cancelled' => [],
        'payment_proof_sent' => ['paid'],
        'paid' => ['shipped'],
        'shipped' => ['not_received', 'returned_to_origin', 'lost', 'received'],
        'not_received' => ['returned_to_origin', 'lost', 'received', 'shipped'],
        'returned_to_origin' => ['shipped', 'lost', 'received'],
        'lost' => ['shipped', 'returned_to_origin', 'received'],
        'received' => [],
    ];
}

/** Rejected or cancelled orders are closed/archived (no further actions). */
function orders_is_archived(string $status): bool
{
    return $status === 'rejected' || $status === 'cancelled';
}

/** @return list<string> */
function orders_cancel_statuses(): array
{
    return ['submitted', 'accepted'];
}

function orders_can_cancel(string $status): bool
{
    return in_array($status, orders_cancel_statuses(), true);
}

/** Hard-delete is only allowed for closed archived orders (cancelled / rejected). */
function orders_can_delete(string $status): bool
{
    return orders_is_archived($status);
}

function orders_chat_send_allowed(string $status): bool
{
    return !in_array($status, ['cancelled', 'rejected', 'received'], true);
}

/** Delivery confirmed by admin — order is finished. */
function orders_is_finished(string $status): bool
{
    return $status === 'received';
}

/** Parcel problem / in-transit after ship (still open until received). */
function orders_is_parcel_open(string $status): bool
{
    return in_array($status, ['shipped', 'not_received', 'returned_to_origin', 'lost'], true);
}

/** Statuses shown as active work queue in CMS (archived / finished kept separate). */
function orders_active_statuses(): array
{
    return [
        'submitted',
        'accepted',
        'payment_proof_sent',
        'paid',
        'shipped',
        'not_received',
        'returned_to_origin',
        'lost',
    ];
}

/** @return array<string, string> */
function orders_list_bucket_labels(): array
{
    return [
        'ongoing' => 'در حال انجام',
        'finished' => 'تمام‌شده',
        'canceled' => 'لغو شده',
    ];
}

/** @return list<string> */
function orders_list_bucket_keys(): array
{
    return array_keys(orders_list_bucket_labels());
}

/** @return array<string, string> */
function orders_ongoing_mode_labels(): array
{
    return [
        'new_order' => 'سفارش جدید',
        'awaiting_payment' => 'در انتظار مدارک پرداخت',
        'paid' => 'پرداخت دریافت شد',
        'package_sent' => 'مرسوله ارسال شد',
        'delivery_issue' => 'تحویل / برگشت به انبار',
        'all' => 'همه در حال انجام',
    ];
}

function orders_normalize_ongoing_mode(string $mode): string
{
    $mode = trim($mode);
    $aliases = [
        'awaiting_admin' => 'new_order',
        'accepted' => 'awaiting_payment',
        'payment_proof' => 'awaiting_payment',
        'shipping' => 'package_sent',
    ];
    if (isset($aliases[$mode])) {
        $mode = $aliases[$mode];
    }
    $labels = orders_ongoing_mode_labels();

    return isset($labels[$mode]) ? $mode : 'new_order';
}

/**
 * @param mixed $raw
 */
function orders_normalize_created_since_days($raw): int
{
    $days = (int) $raw;
    if ($days <= 0) {
        return 0;
    }

    return min($days, 3650);
}

/**
 * Sub-filters within the ongoing work queue (admin mobile + CMS).
 *
 * @return array{sql: ?string, params: list<mixed>}
 */
function orders_list_ongoing_mode_clause(string $mode): array
{
    $mode = orders_normalize_ongoing_mode($mode);
    if ($mode === 'all') {
        return orders_list_status_clause('ongoing');
    }
    if ($mode === 'new_order') {
        return ['sql' => "o.status = 'submitted'", 'params' => []];
    }
    if ($mode === 'awaiting_payment') {
        return ['sql' => "o.status IN ('accepted', 'payment_proof_sent')", 'params' => []];
    }
    if ($mode === 'paid') {
        return ['sql' => "o.status = 'paid'", 'params' => []];
    }
    if ($mode === 'package_sent') {
        return ['sql' => "o.status = 'shipped'", 'params' => []];
    }
    if ($mode === 'delivery_issue') {
        $statuses = ['not_received', 'returned_to_origin', 'lost'];
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        return ['sql' => "o.status IN ({$placeholders})", 'params' => $statuses];
    }

    return orders_list_status_clause('ongoing');
}

function orders_normalize_search_query(string $q): string
{
    $q = trim($q);
    $q = strtr($q, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    $q = ltrim($q, "# \t");

    return trim($q);
}

function orders_search_is_identifier(string $q): bool
{
    if ($q === '') {
        return false;
    }
    if (preg_match('/^ST[-_]?[A-Za-z0-9_-]+$/i', $q)) {
        return true;
    }
    // Order ids are short; 10–11 digit values are treated as phone numbers.
    return ctype_digit($q) && strlen($q) <= 9;
}

function orders_normalize_list_status(string $statusFilter, string $default = 'all'): string
{
    $statusFilter = trim($statusFilter);
    if ($statusFilter === '') {
        return $default;
    }
    if ($statusFilter === 'all' || in_array($statusFilter, orders_list_bucket_keys(), true)) {
        return $statusFilter;
    }
    if (in_array($statusFilter, orders_all_statuses(), true)) {
        return $statusFilter;
    }

    return $default;
}

/**
 * @return array{sql: ?string, params: list<mixed>}
 */
function orders_list_status_clause(string $statusFilter): array
{
    if ($statusFilter === 'all' || $statusFilter === '') {
        return ['sql' => null, 'params' => []];
    }
    if ($statusFilter === 'ongoing') {
        $statuses = orders_active_statuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        return ['sql' => "o.status IN ({$placeholders})", 'params' => $statuses];
    }
    if ($statusFilter === 'finished') {
        return ['sql' => 'o.status = ?', 'params' => ['received']];
    }
    if ($statusFilter === 'canceled') {
        return ['sql' => 'o.status = ?', 'params' => ['cancelled']];
    }
    if (in_array($statusFilter, orders_all_statuses(), true)) {
        return ['sql' => 'o.status = ?', 'params' => [$statusFilter]];
    }

    return ['sql' => null, 'params' => []];
}

/**
 * @return array{sql: ?string, params: list<mixed>, is_identifier: bool, q: string}
 */
function orders_list_search_clause(string $searchQ): array
{
    $searchQ = orders_normalize_search_query($searchQ);
    if ($searchQ === '') {
        return ['sql' => null, 'params' => [], 'is_identifier' => false, 'q' => ''];
    }
    $like = '%' . $searchQ . '%';
    $parts = [
        'o.phone LIKE ?',
        'o.public_code LIKE ?',
        "COALESCE(o.branch_phone, '') LIKE ?",
        "COALESCE(o.sales_user_name, '') LIKE ?",
        'CAST(o.id AS CHAR) LIKE ?',
    ];
    $params = [$like, $like, $like, $like, $like];
    if (ctype_digit($searchQ)) {
        $parts[] = 'o.id = ?';
        $params[] = (int) $searchQ;
    }

    return [
        'sql' => '(' . implode(' OR ', $parts) . ')',
        'params' => $params,
        'is_identifier' => orders_search_is_identifier($searchQ),
        'q' => $searchQ,
    ];
}

/**
 * @return array{
 *   sql: string,
 *   params: list<mixed>,
 *   scope: string,
 *   scope_sql: string,
 *   status_filter: string,
 *   search_q: string
 * }
 */
function orders_admin_list_where(
    string $scope,
    string $statusFilter,
    string $searchQ,
    string $defaultStatus = 'all',
    string $ongoingMode = 'new_order',
    string $clientPhone = '',
    int $branchId = 0,
    int $createdSinceDays = 0
): array {
    if ($scope !== 'branches' && $scope !== 'customers' && $scope !== 'all') {
        $scope = 'customers';
    }
    $statusFilter = orders_normalize_list_status($statusFilter, $defaultStatus);
    $ongoingMode = orders_normalize_ongoing_mode($ongoingMode);
    $search = orders_list_search_clause($searchQ);
    $clientPhone = orders_normalize_search_query($clientPhone);
    $createdSinceDays = orders_normalize_created_since_days($createdSinceDays);
    if ($scope === 'all') {
        $scopeSql = '1=1';
        $where = ['1=1'];
    } elseif ($scope === 'branches') {
        $scopeSql = 'branch_id IS NOT NULL';
        $where = [$scopeSql];
    } else {
        $scope = 'customers';
        $scopeSql = 'branch_id IS NULL';
        $where = [$scopeSql];
    }
    $params = [];

    if ($clientPhone !== '') {
        $where[] = 'o.phone = ?';
        $params[] = $clientPhone;
    }
    if ($branchId > 0) {
        $where[] = 'o.branch_id = ?';
        $params[] = $branchId;
    }
    if ($createdSinceDays > 0) {
        $where[] = 'o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params[] = $createdSinceDays;
    }

    if (!$search['is_identifier']) {
        if ($statusFilter === 'ongoing') {
            $status = orders_list_ongoing_mode_clause($ongoingMode);
        } else {
            $status = orders_list_status_clause($statusFilter);
        }
        if ($status['sql'] !== null) {
            $where[] = $status['sql'];
            $params = array_merge($params, $status['params']);
        }
    }
    if ($search['sql'] !== null) {
        $where[] = $search['sql'];
        $params = array_merge($params, $search['params']);
    }

    return [
        'sql' => implode(' AND ', $where),
        'params' => $params,
        'scope' => $scope,
        'scope_sql' => $scopeSql,
        'status_filter' => $statusFilter,
        'ongoing_mode' => $ongoingMode,
        'search_q' => $search['q'],
    ];
}

function orders_generate_public_code(PDO $pdo): string
{
    $prefix = 'ST-' . date('ymd') . '-';
    for ($i = 0; $i < 12; $i++) {
        $code = $prefix . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE public_code = ? LIMIT 1');
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    return $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function orders_item_unit_type(array $item): string
{
    return isset($item['unit_type']) && (string) $item['unit_type'] === 'pack' ? 'pack' : 'piece';
}

/**
 * @return array{quantity: int, unit_type: string, pack_size: ?int}
 */
function orders_item_effective_original(array $item): array
{
    $qty = max(1, (int) ($item['quantity'] ?? 1));
    $unit = orders_item_unit_type($item);
    $pack = isset($item['pack_size']) && $item['pack_size'] !== null && (int) $item['pack_size'] > 0
        ? (int) $item['pack_size']
        : null;

    if (isset($item['original_quantity']) && $item['original_quantity'] !== null) {
        $origQty = max(1, (int) $item['original_quantity']);
        $origUnit = isset($item['original_unit_type']) && (string) $item['original_unit_type'] === 'pack'
            ? 'pack'
            : 'piece';
        $origPack = isset($item['original_pack_size']) && $item['original_pack_size'] !== null
            && (int) $item['original_pack_size'] > 0
            ? (int) $item['original_pack_size']
            : null;

        return [
            'quantity' => $origQty,
            'unit_type' => $origUnit,
            'pack_size' => $origPack,
        ];
    }

    return [
        'quantity' => $qty,
        'unit_type' => $unit,
        'pack_size' => $pack,
    ];
}

function orders_item_is_adjusted(array $item): bool
{
    $orig = orders_item_effective_original($item);
    $qty = max(0, (int) ($item['quantity'] ?? 1));
    $unit = orders_item_unit_type($item);

    return $qty !== $orig['quantity'] || $unit !== $orig['unit_type'];
}

function orders_format_item_quantity_summary(array $item): string
{
    if (!function_exists('cms_to_persian_digits')) {
        require_once __DIR__ . '/jalali.php';
    }

    $qty = (int) ($item['quantity'] ?? 1);
    if ($qty <= 0) {
        return '۰ (ناموجود)';
    }
    $unit = orders_item_unit_type($item);
    $pack = isset($item['pack_size']) && $item['pack_size'] !== null && (int) $item['pack_size'] > 0
        ? (int) $item['pack_size']
        : 0;
    $qtyFa = cms_to_persian_digits((string) $qty);

    if ($unit === 'pack') {
        if ($pack > 0) {
            $total = $qty * $pack;

            return $qtyFa . ' بسته (' . cms_to_persian_digits((string) $total) . ' عدد)';
        }

        return $qtyFa . ' بسته';
    }

    return $qtyFa . ' عدد';
}

function orders_format_item_adjustment_line(array $item): string
{
    $orig = orders_item_effective_original($item);
    $fromLabel = orders_format_item_quantity_summary(array_merge($item, [
        'quantity' => $orig['quantity'],
        'unit_type' => $orig['unit_type'],
        'pack_size' => $orig['pack_size'],
    ]));
    $toLabel = orders_format_item_quantity_summary($item);
    $name = trim((string) ($item['name'] ?? 'قلم'));

    return '«' . $name . '»: از ' . $fromLabel . ' به ' . $toLabel;
}

/**
 * @param list<array<string, mixed>> $items
 */
function orders_quantity_adjustment_notice(array $items): ?string
{
    $lines = [];
    foreach ($items as $item) {
        if (!is_array($item) || !orders_item_is_adjusted($item)) {
            continue;
        }
        $lines[] = orders_format_item_adjustment_line($item);
    }
    if ($lines === []) {
        return null;
    }

    return 'تعداد برخی اقلام به دلیل وضعیت انبار تغییر یافت:' . "\n• " . implode("\n• ", $lines);
}

/**
 * @param list<array<string, mixed>> $items
 */
function orders_order_has_item_adjustments(array $items): bool
{
    foreach ($items as $item) {
        if (is_array($item) && orders_item_is_adjusted($item)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function orders_active_order_items(array $items): array
{
    return array_values(array_filter(
        $items,
        static fn($item): bool => is_array($item) && (int) ($item['quantity'] ?? 0) > 0
    ));
}

function orders_items_adjustment_pending_flag(array $order): bool
{
    return (int) ($order['items_adjustment_pending'] ?? 0) === 1;
}

function orders_items_adjustment_confirmed_at(array $order): ?string
{
    $at = isset($order['items_adjustment_confirmed_at']) && $order['items_adjustment_confirmed_at'] !== null
        ? trim((string) $order['items_adjustment_confirmed_at'])
        : '';

    return $at !== '' ? $at : null;
}

/**
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 */
function orders_can_proceed_to_pre_invoice(array $order, array $items): bool
{
    if (!orders_order_has_item_adjustments($items)) {
        return true;
    }
    if (!orders_items_adjustment_pending_flag($order)) {
        return true;
    }

    return orders_items_adjustment_confirmed_at($order) !== null;
}

function orders_mark_items_adjustment_pending(PDO $pdo, int $orderId): void
{
    $upd = $pdo->prepare(
        'UPDATE orders SET items_adjustment_pending = 1, items_adjustment_confirmed_at = NULL WHERE id = ?'
    );
    $upd->execute([$orderId]);
}

function orders_clear_items_adjustment_pending(PDO $pdo, int $orderId): void
{
    $upd = $pdo->prepare(
        'UPDATE orders SET items_adjustment_pending = 0, items_adjustment_confirmed_at = NULL WHERE id = ?'
    );
    $upd->execute([$orderId]);
}

/**
 * @param array<string, mixed> $order
 */
function orders_mark_items_adjustment_confirmed(PDO $pdo, array $order, string $actor = 'client'): void
{
    $orderId = (int) $order['id'];
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            'UPDATE orders SET items_adjustment_pending = 0, items_adjustment_confirmed_at = NOW() WHERE id = ?'
        );
        $upd->execute([$orderId]);
        orders_add_event(
            $pdo,
            $orderId,
            'submitted',
            'submitted',
            $actor,
            'مشتری تعداد اقلام اعلام‌شده را تأیید کرد'
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if (!function_exists('admin_push_notify_order_activity')) {
        $pushLib = __DIR__ . '/admin-push.php';
        if (is_readable($pushLib)) {
            require_once $pushLib;
        }
    }
    if (function_exists('admin_push_notify_order_activity')) {
        try {
            admin_push_notify_order_activity(
                $pdo,
                $orderId,
                'items_adjustment_confirmed',
                'مشتری تعداد اقلام را تأیید کرد'
            );
        } catch (Throwable $e) {
            error_log('[orders] admin push on confirm failed: ' . $e->getMessage());
        }
    }
}

/**
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 */
function orders_require_items_adjustment_confirmed(array $order, array $items): void
{
    if (orders_can_proceed_to_pre_invoice($order, $items)) {
        return;
    }

    throw new RuntimeException('در انتظار تأیید مشتری در وب');
}

/**
 * @param list<array<string, mixed>> $items
 */
function orders_sync_items_adjustment_state(PDO $pdo, int $orderId, array $items, int $qtyChanged): void
{
    if (!orders_order_has_item_adjustments($items)) {
        orders_clear_items_adjustment_pending($pdo, $orderId);

        return;
    }
    if ($qtyChanged > 0) {
        orders_mark_items_adjustment_pending($pdo, $orderId);
    }
}

/**
 * @param array{id: int, branch_id?: int|null} $user
 * @param array<string, mixed> $order
 */
function orders_client_can_access_order(array $user, array $order): bool
{
    if ((int) ($order['user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return true;
    }
    $userBranchId = isset($user['branch_id']) && $user['branch_id'] !== null
        ? (int) $user['branch_id']
        : 0;
    $orderBranchId = isset($order['branch_id']) && $order['branch_id'] !== null
        ? (int) $order['branch_id']
        : 0;

    return $userBranchId > 0 && $orderBranchId > 0 && $userBranchId === $orderBranchId;
}

/**
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 * @return array<string, mixed>
 */
function orders_serialize_adjustment_gate_fields(array $order, array $items): array
{
    $pending = orders_items_adjustment_pending_flag($order);
    $confirmedAt = orders_items_adjustment_confirmed_at($order);

    return [
        'items_adjustment_pending' => $pending,
        'items_adjustment_confirmed_at' => $confirmedAt,
        'can_proceed_to_pre_invoice' => orders_can_proceed_to_pre_invoice($order, $items),
    ];
}

/**
 * @return array<string, mixed>
 */
function orders_serialize_item_adjustment_fields(array $item): array
{
    $orig = orders_item_effective_original($item);
    $adjusted = orders_item_is_adjusted($item);

    return [
        'original_quantity' => $orig['quantity'],
        'original_unit_type' => $orig['unit_type'],
        'original_pack_size' => $orig['pack_size'],
        'quantity_adjusted' => $adjusted,
    ];
}

/**
 * @param mixed $raw
 */
function orders_parse_quantity_input($raw): int
{
    $s = trim((string) $raw);
    $s = strtr($s, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    $s = preg_replace('/\D/u', '', $s) ?? '';
    if ($s === '') {
        throw new RuntimeException('تعداد نامعتبر است');
    }

    return (int) $s;
}

/**
 * @param array<string, mixed> $item
 */
function orders_validate_item_adjustment(array $item, int $newQty, string $newUnit): void
{
    $orig = orders_item_effective_original($item);
    $origQty = $orig['quantity'];
    $origUnit = $orig['unit_type'];
    $origPack = (int) ($orig['pack_size'] ?? 0);

    if ($newQty < 0) {
        throw new RuntimeException('تعداد نامعتبر است');
    }

    if ($newUnit === 'pack' && $origUnit === 'piece') {
        throw new RuntimeException('تبدیل عدد به بسته مجاز نیست');
    }

    if ($newUnit === 'pack') {
        if ($origPack <= 0) {
            throw new RuntimeException('اندازه بسته برای این قلم مشخص نیست');
        }
        if ($newQty > $origQty) {
            throw new RuntimeException('فقط کاهش تعداد مجاز است');
        }

        return;
    }

    if ($origUnit === 'pack') {
        $origTotalPieces = $origQty * max(1, $origPack);
        if ($newQty >= $origTotalPieces) {
            throw new RuntimeException('فقط کاهش تعداد مجاز است');
        }

        return;
    }

    if ($newQty > $origQty) {
        throw new RuntimeException('فقط کاهش تعداد مجاز است');
    }
}

/**
 * @param array<string, mixed> $adjustments item_id => {quantity, unit_type?, pack_size?}
 */
function orders_apply_item_adjustments(PDO $pdo, int $orderId, array $adjustments, string $currentStatus): int
{
    if ($adjustments === []) {
        return 0;
    }
    if ($currentStatus !== 'submitted') {
        throw new RuntimeException('تغییر تعداد فقط در مرحله بررسی انبار مجاز است');
    }

    $items = orders_fetch_items($pdo, $orderId);
    $byId = [];
    foreach ($items as $item) {
        $byId[(int) $item['id']] = $item;
    }

    $ensureOrig = $pdo->prepare(
        'UPDATE order_items SET
            original_quantity = COALESCE(original_quantity, quantity),
            original_unit_type = COALESCE(original_unit_type, unit_type),
            original_pack_size = COALESCE(original_pack_size, pack_size)
         WHERE id = ? AND order_id = ?'
    );
    $upd = $pdo->prepare(
        'UPDATE order_items SET quantity = ?, unit_type = ?, pack_size = ? WHERE id = ? AND order_id = ?'
    );

    $changed = 0;
    foreach ($adjustments as $itemIdRaw => $payload) {
        if (!is_array($payload)) {
            continue;
        }
        $itemId = (int) $itemIdRaw;
        if ($itemId <= 0 || !isset($byId[$itemId])) {
            throw new RuntimeException('قلم #' . $itemId . ' یافت نشد');
        }

        $item = $byId[$itemId];
        $ensureOrig->execute([$itemId, $orderId]);

        $newQty = orders_parse_quantity_input($payload['quantity'] ?? 0);
        $newUnit = isset($payload['unit_type']) && (string) $payload['unit_type'] === 'pack'
            ? 'pack'
            : 'piece';
        orders_validate_item_adjustment($item, $newQty, $newUnit);

        $orig = orders_item_effective_original($item);
        $currentQty = max(0, (int) ($item['quantity'] ?? 1));
        $currentUnit = orders_item_unit_type($item);
        if ($newQty === $currentQty && $newUnit === $currentUnit) {
            continue;
        }

        $newPackSize = null;
        if ($newUnit === 'pack') {
            $newPackSize = $orig['pack_size'] ?? (
                isset($item['pack_size']) && $item['pack_size'] !== null && (int) $item['pack_size'] > 0
                    ? (int) $item['pack_size']
                    : null
            );
        } elseif ($orig['unit_type'] === 'pack') {
            $newPackSize = $orig['pack_size'] ?? (
                isset($item['pack_size']) && $item['pack_size'] !== null && (int) $item['pack_size'] > 0
                    ? (int) $item['pack_size']
                    : null
            );
        }

        $upd->execute([$newQty, $newUnit, $newPackSize, $itemId, $orderId]);
        $changed += $upd->rowCount() > 0 ? 1 : 0;
    }

    return $changed;
}

/**
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function orders_serialize(array $order, array $items, array $events): array
{
    $serializedItems = [];
    foreach ($items as $item) {
        $serializedItems[] = array_merge([
            'id' => (int) $item['id'],
            'product_id' => isset($item['product_id']) && $item['product_id'] !== null
                ? (int) $item['product_id']
                : null,
            'name' => (string) $item['name'],
            'slug' => (string) ($item['slug'] ?? ''),
            'price_text' => cms_call_for_price_enabled()
                ? null
                : ($item['price_text'] !== null ? (string) $item['price_text'] : null),
            'image' => $item['image'] !== null ? (string) $item['image'] : null,
            'quantity' => (int) $item['quantity'],
            'unit_type' => orders_item_unit_type($item),
            'pack_size' => isset($item['pack_size']) && $item['pack_size'] !== null && (int) $item['pack_size'] > 0
                ? (int) $item['pack_size']
                : null,
            'factory_name' => $item['factory_name'] !== null ? (string) $item['factory_name'] : null,
            'model_name' => $item['model_name'] !== null ? (string) $item['model_name'] : null,
            'category_name' => $item['category_name'] !== null ? (string) $item['category_name'] : null,
            'visual_id' => isset($item['visual_id']) && $item['visual_id'] !== null && trim((string) $item['visual_id']) !== ''
                ? (string) $item['visual_id']
                : null,
        ], orders_serialize_item_adjustment_fields($item));
    }

    $serializedEvents = [];
    foreach ($events as $event) {
        $serializedEvents[] = [
            'id' => (int) $event['id'],
            'from_status' => $event['from_status'] !== null ? (string) $event['from_status'] : null,
            'to_status' => (string) $event['to_status'],
            'message' => $event['message'] !== null && trim((string) $event['message']) !== ''
                ? (string) $event['message']
                : null,
            'actor' => (string) $event['actor'],
            'admin_username' => isset($event['admin_username']) && $event['admin_username'] !== null
                ? (string) $event['admin_username']
                : null,
            'created_at' => (string) $event['created_at'],
        ];
    }

    $paymentNote = isset($order['payment_note']) && $order['payment_note'] !== null
        ? trim((string) $order['payment_note'])
        : '';
    $paymentFiles = orders_payment_files_list($order);
    $warningText = isset($order['payment_warning']) && $order['payment_warning'] !== null
        ? trim((string) $order['payment_warning'])
        : '';
    $warningState = isset($order['payment_warning_state']) && $order['payment_warning_state'] !== null
        ? trim((string) $order['payment_warning_state'])
        : '';
    if ($warningState === '' && $warningText !== '') {
        $warningState = 'open';
    }
    if (!in_array($warningState, ['open', 'answered'], true)) {
        $warningState = '';
    }

    $cheques = [];
    try {
        $cheques = order_cheques_fetch(cms_pdo(), (int) $order['id']);
    } catch (Throwable $e) {
        $cheques = [];
    }

    return [
        'id' => (int) $order['id'],
        'public_code' => (string) $order['public_code'],
        'sms_ref' => isset($order['sms_ref']) && trim((string) $order['sms_ref']) !== ''
            ? strtoupper(trim((string) $order['sms_ref']))
            : null,
        'sms_channel' => !empty($order['sms_channel']),
        'user_id' => (int) $order['user_id'],
        'phone' => (string) $order['phone'],
        'customer_name' => isset($order['customer_name']) && $order['customer_name'] !== null
            && trim((string) $order['customer_name']) !== ''
            ? trim((string) $order['customer_name'])
            : null,
        'branch_id' => isset($order['branch_id']) && $order['branch_id'] !== null
            ? (int) $order['branch_id']
            : null,
        'branch_name' => isset($order['branch_name']) && $order['branch_name'] !== null
            ? (string) $order['branch_name']
            : null,
        'branch_city' => isset($order['branch_city']) && $order['branch_city'] !== null
            ? (string) $order['branch_city']
            : null,
        'branch_province_name' => isset($order['branch_province_name']) && $order['branch_province_name'] !== null
            ? (string) $order['branch_province_name']
            : null,
        'branch_phone' => isset($order['branch_phone']) && $order['branch_phone'] !== null
            ? (string) $order['branch_phone']
            : null,
        'sales_user_id' => isset($order['sales_user_id']) && $order['sales_user_id'] !== null
            ? (int) $order['sales_user_id']
            : null,
        'sales_user_name' => isset($order['sales_user_name']) && $order['sales_user_name'] !== null
            && trim((string) $order['sales_user_name']) !== ''
            ? (string) $order['sales_user_name']
            : null,
        'status' => (string) $order['status'],
        'payment_note' => $paymentNote !== '' ? $paymentNote : null,
        'payment_file' => $paymentFiles[0] ?? null,
        'payment_files' => $paymentFiles,
        // Client only sees an open (unanswered) warning.
        'payment_warning' => ($warningState === 'open' && $warningText !== '') ? $warningText : null,
        'payment_warning_state' => $warningState !== '' ? $warningState : null,
        'payment_submitted_at' => isset($order['payment_submitted_at']) && $order['payment_submitted_at'] !== null
            ? (string) $order['payment_submitted_at']
            : null,
        'pre_invoice_file' => isset($order['pre_invoice_file']) && $order['pre_invoice_file'] !== null
            && trim((string) $order['pre_invoice_file']) !== ''
            ? (string) $order['pre_invoice_file']
            : null,
        'pre_invoice_created_at' => isset($order['pre_invoice_created_at']) && $order['pre_invoice_created_at'] !== null
            ? (string) $order['pre_invoice_created_at']
            : null,
        'pre_invoice_due_at' => isset($order['pre_invoice_due_at']) && $order['pre_invoice_due_at'] !== null
            ? (string) $order['pre_invoice_due_at']
            : null,
        'final_invoice_file' => isset($order['final_invoice_file']) && $order['final_invoice_file'] !== null
            && trim((string) $order['final_invoice_file']) !== ''
            ? (string) $order['final_invoice_file']
            : null,
        'final_invoice_created_at' => isset($order['final_invoice_created_at']) && $order['final_invoice_created_at'] !== null
            ? (string) $order['final_invoice_created_at']
            : null,
        'created_at' => (string) $order['created_at'],
        'updated_at' => (string) $order['updated_at'],
        'items' => $serializedItems,
        'events' => $serializedEvents,
        'item_count' => array_sum(array_map(
            static fn(array $row): int => (int) $row['quantity'],
            $serializedItems
        )),
        'payment_method' => orders_normalize_payment_method(
            isset($order['payment_method']) ? (string) $order['payment_method'] : null
        ),
        'payment_reference' => isset($order['payment_reference']) && trim((string) $order['payment_reference']) !== ''
            ? trim((string) $order['payment_reference'])
            : null,
        'cheques' => $cheques,
        'cheques_all_funded' => order_cheques_all_funded($cheques),
        'quantity_adjustment_notice' => orders_quantity_adjustment_notice($items),
    ] + orders_serialize_adjustment_gate_fields($order, $items);
}

/**
 * Lightweight client list row — avoids loading items, events, and cheques per order.
 *
 * @param array<string, mixed> $order
 * @return array<string, mixed>
 */
function orders_serialize_client_list_row(
    array $order,
    int $itemCount = 0,
    int $unreadAdminEvents = 0
): array {
    return [
        'id' => (int) $order['id'],
        'public_code' => (string) $order['public_code'],
        'user_id' => (int) $order['user_id'],
        'phone' => (string) $order['phone'],
        'status' => (string) $order['status'],
        'payment_note' => null,
        'payment_method' => null,
        'payment_reference' => null,
        'payment_file' => null,
        'payment_files' => [],
        'payment_warning' => null,
        'payment_warning_state' => null,
        'payment_submitted_at' => null,
        'pre_invoice_file' => null,
        'pre_invoice_created_at' => null,
        'pre_invoice_due_at' => null,
        'final_invoice_file' => null,
        'final_invoice_created_at' => null,
        'created_at' => (string) $order['created_at'],
        'updated_at' => (string) $order['updated_at'],
        'items' => [],
        'events' => [],
        'item_count' => $itemCount,
        'unread_admin_events' => $unreadAdminEvents,
    ];
}

function orders_normalize_payment_method(?string $raw): ?string
{
    $value = strtolower(trim((string) $raw));
    if ($value === 'cash' || $value === 'cheque') {
        return $value;
    }

    return null;
}

/**
 * @return array{can: bool, reason: ?string}
 */
function orders_mark_paid_readiness(array $order, array $cheques = [], ?int $orderTotalToman = null): array
{
    $status = (string) ($order['status'] ?? '');
    if ($status !== 'payment_proof_sent') {
        return ['can' => false, 'reason' => null];
    }

    $method = orders_normalize_payment_method(
        isset($order['payment_method']) ? (string) $order['payment_method'] : null
    );
    if ($method === null) {
        return ['can' => false, 'reason' => 'ابتدا روش پرداخت (نقد/چک) را انتخاب کنید'];
    }

    if ($method === 'cheque') {
        if ($cheques === []) {
            return ['can' => false, 'reason' => 'حداقل یک چک در دفتر ثبت کنید'];
        }

        // Physical receipt in office is enough to confirm payment and ship;
        // bank cashing (funded) is tracked separately and does not block shipping.
        return ['can' => true, 'reason' => null];
    }

    $files = orders_payment_files_list($order);
    $reference = isset($order['payment_reference']) ? trim((string) $order['payment_reference']) : '';
    if ($files === []) {
        return ['can' => false, 'reason' => 'رسید پرداخت توسط مشتری ارسال نشده است'];
    }
    if ($reference === '') {
        return ['can' => false, 'reason' => 'شماره پیگیری پرداخت ثبت نشده است'];
    }

    return ['can' => true, 'reason' => null];
}

function orders_admin_pricing_helpers_ready(): void
{
    if (!function_exists('invoices_normalize_price_text')) {
        require_once __DIR__ . '/invoices.php';
    }
}

function orders_admin_is_call_for_price(?string $text): bool
{
    $text = trim((string) $text);
    if ($text === '') {
        return true;
    }

    $label = function_exists('cms_call_for_price_label')
        ? cms_call_for_price_label()
        : 'تماس برای قیمت';
    if ($text === $label) {
        return true;
    }

    $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return str_contains($lower, 'تماس') && str_contains($lower, 'قیمت');
}

/**
 * Normalize catalog / order price text to stored toman label when possible.
 */
function orders_admin_try_normalize_price(?string $raw): ?string
{
    orders_admin_pricing_helpers_ready();

    $raw = trim((string) $raw);
    if ($raw === '' || orders_admin_is_call_for_price($raw)) {
        return null;
    }

    foreach ([$raw, str_replace('.', '', $raw)] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        try {
            $normalized = invoices_normalize_price_text($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        } catch (Throwable $e) {
            // try next candidate
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function orders_admin_slug_variants(string $slug): array
{
    $slug = trim($slug);
    if ($slug === '') {
        return [];
    }

    $variants = [$slug];
    $spaced = str_replace('-', ' ', $slug);
    if ($spaced !== $slug) {
        $variants[] = $spaced;
    }
    $dashed = preg_replace('/\s+/u', '-', $slug) ?? $slug;
    if ($dashed !== $slug) {
        $variants[] = $dashed;
    }

    return array_values(array_unique($variants));
}

/**
 * @param array<string, mixed>|null $row
 */
function orders_admin_pick_catalog_price(?array $row): ?string
{
    if (!$row) {
        return null;
    }

    $price = isset($row['price_text']) ? trim((string) $row['price_text']) : '';
    if ($price === '' || orders_admin_is_call_for_price($price)) {
        return null;
    }

    return orders_admin_try_normalize_price($price) ?? $price;
}

/**
 * Default price for admin order pricing UI (catalog first, then parseable saved line).
 */
function orders_admin_lookup_catalog_price(PDO $pdo, array $item): ?string
{
    $lookupProduct = static function (PDO $pdo, string $sql, array $params): ?string {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return orders_admin_pick_catalog_price(is_array($row) ? $row : null);
    };

    $productId = isset($item['product_id']) && $item['product_id'] !== null
        ? (int) $item['product_id']
        : 0;
    if ($productId > 0) {
        $price = $lookupProduct(
            $pdo,
            'SELECT price_text FROM products WHERE id = ? LIMIT 1',
            [$productId]
        );
        if ($price !== null) {
            return $price;
        }
    }

    $slug = isset($item['slug']) ? trim((string) $item['slug']) : '';
    foreach (orders_admin_slug_variants($slug) as $slugVariant) {
        $price = $lookupProduct(
            $pdo,
            'SELECT price_text FROM products WHERE slug = ? LIMIT 1',
            [$slugVariant]
        );
        if ($price !== null) {
            return $price;
        }

        try {
            $price = $lookupProduct(
                $pdo,
                'SELECT price_text FROM product_series WHERE slug = ? LIMIT 1',
                [$slugVariant]
            );
            if ($price !== null) {
                return $price;
            }
        } catch (Throwable $e) {
            // product_series may be unavailable on older installs
        }
    }

    if ($slug !== '' && (function_exists('mb_strlen') ? mb_strlen($slug, 'UTF-8') : strlen($slug)) >= 3) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $slug) . '%';
        $price = $lookupProduct(
            $pdo,
            'SELECT price_text FROM products
             WHERE slug LIKE ? ESCAPE \'\\\\\' OR name LIKE ? ESCAPE \'\\\\\'
             ORDER BY published DESC, id DESC
             LIMIT 1',
            [$like, $like]
        );
        if ($price !== null) {
            return $price;
        }
    }

    $visualId = isset($item['visual_id']) ? trim((string) $item['visual_id']) : '';
    if ($visualId !== '') {
        $price = $lookupProduct(
            $pdo,
            'SELECT price_text FROM products WHERE visual_id = ? LIMIT 1',
            [$visualId]
        );
        if ($price !== null) {
            return $price;
        }

        try {
            $price = $lookupProduct(
                $pdo,
                'SELECT price_text FROM product_series WHERE visual_id = ? LIMIT 1',
                [$visualId]
            );
            if ($price !== null) {
                return $price;
            }
        } catch (Throwable $e) {
            // product_series may be unavailable on older installs
        }
    }

    $name = isset($item['name']) ? trim((string) $item['name']) : '';
    if ($name !== '') {
        $price = $lookupProduct(
            $pdo,
            'SELECT price_text FROM products WHERE name = ? LIMIT 1',
            [$name]
        );
        if ($price !== null) {
            return $price;
        }

        try {
            $price = $lookupProduct(
                $pdo,
                'SELECT price_text FROM product_series WHERE name = ? LIMIT 1',
                [$name]
            );
            if ($price !== null) {
                return $price;
            }
        } catch (Throwable $e) {
            // ignore
        }

        if ((function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) >= 3) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name) . '%';
            $price = $lookupProduct(
                $pdo,
                'SELECT price_text FROM products
                 WHERE name LIKE ? ESCAPE \'\\\\\'
                 ORDER BY published DESC, id DESC
                 LIMIT 1',
                [$like]
            );
            if ($price !== null) {
                return $price;
            }
        }
    }

    $savedLinePrice = isset($item['price_text']) ? trim((string) $item['price_text']) : '';
    if ($savedLinePrice !== '') {
        $fallback = orders_admin_try_normalize_price($savedLinePrice);
        if ($fallback !== null) {
            return $fallback;
        }
    }

    return null;
}

/**
 * Attach live catalog default prices for admin pricing UI.
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function orders_admin_enrich_items(PDO $pdo, array $items): array
{
    $enriched = [];
    foreach ($items as $item) {
        $row = $item;
        $row['catalog_price_text'] = orders_admin_lookup_catalog_price($pdo, $item);
        $enriched[] = $row;
    }

    return $enriched;
}

/**
 * Admin API serialization — always exposes saved prices and catalog defaults.
 *
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function orders_admin_serialize(array $order, array $items, array $events): array
{
    $pdo = cms_pdo();
    $enrichedItems = orders_admin_enrich_items($pdo, $items);

    $serializedItems = [];
    foreach ($enrichedItems as $item) {
        $priceText = $item['price_text'] !== null && trim((string) $item['price_text']) !== ''
            ? (string) $item['price_text']
            : null;
        $catalogPriceText = isset($item['catalog_price_text'])
            && $item['catalog_price_text'] !== null
            && trim((string) $item['catalog_price_text']) !== ''
            ? (string) $item['catalog_price_text']
            : null;

        $serializedItems[] = array_merge([
            'id' => (int) $item['id'],
            'product_id' => isset($item['product_id']) && $item['product_id'] !== null
                ? (int) $item['product_id']
                : null,
            'name' => (string) $item['name'],
            'slug' => (string) ($item['slug'] ?? ''),
            'price_text' => $priceText,
            'catalog_price_text' => $catalogPriceText,
            'image' => $item['image'] !== null ? (string) $item['image'] : null,
            'quantity' => (int) $item['quantity'],
            'unit_type' => orders_item_unit_type($item),
            'pack_size' => isset($item['pack_size']) && $item['pack_size'] !== null && (int) $item['pack_size'] > 0
                ? (int) $item['pack_size']
                : null,
            'factory_name' => $item['factory_name'] !== null ? (string) $item['factory_name'] : null,
            'model_name' => $item['model_name'] !== null ? (string) $item['model_name'] : null,
            'category_name' => $item['category_name'] !== null ? (string) $item['category_name'] : null,
            'visual_id' => isset($item['visual_id']) && $item['visual_id'] !== null && trim((string) $item['visual_id']) !== ''
                ? (string) $item['visual_id']
                : null,
        ], orders_serialize_item_adjustment_fields($item));
    }

    $payload = orders_serialize($order, $items, $events);
    $payload['items'] = $serializedItems;
    $payload['item_count'] = array_sum(array_map(
        static fn(array $row): int => (int) $row['quantity'],
        $serializedItems
    ));
    $payload['pricing_api_version'] = 2;
    $payload['can_delete'] = orders_can_delete((string) $order['status']);

    if (!function_exists('invoices_totals_from_items')) {
        require_once __DIR__ . '/invoices.php';
    }
    if (!function_exists('order_cheques_registered_total_toman')) {
        require_once __DIR__ . '/order-cheques.php';
    }
    $cheques = is_array($payload['cheques'] ?? null) ? $payload['cheques'] : [];
    $orderTotal = (int) (($totals = invoices_totals_from_items($serializedItems))['total'] ?? 0);
    $registered = order_cheques_registered_total_toman($cheques);
    $remaining = order_cheques_remaining_toman($orderTotal, $cheques);
    $payload['cheque_registered_total_label'] = order_cheques_format_toman_label($registered);
    $payload['cheque_remaining_label'] = order_cheques_format_toman_label($remaining);
    $readiness = orders_mark_paid_readiness($order, $cheques, $orderTotal);
    $payload['can_mark_paid'] = $readiness['can'];
    $payload['mark_paid_block_reason'] = $readiness['reason'];

    return $payload;
}

/** Max payment proof attachments per order. */
function orders_payment_files_max(): int
{
    return 9;
}

/**
 * @param array<string, mixed> $order
 * @return list<string>
 */
function orders_payment_files_list(array $order): array
{
    $raw = isset($order['payment_files']) ? trim((string) $order['payment_files']) : '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $path = trim($item);
                if ($path !== '' && strpos($path, '/uploads/') === 0) {
                    $out[] = $path;
                }
            }
            return array_values(array_unique($out));
        }
    }

    $legacy = isset($order['payment_file']) && $order['payment_file'] !== null
        ? trim((string) $order['payment_file'])
        : '';
    if ($legacy !== '' && strpos($legacy, '/uploads/') === 0) {
        return [$legacy];
    }
    return [];
}

/**
 * @param list<string> $files
 */
function orders_payment_files_encode(array $files): ?string
{
    $clean = [];
    foreach ($files as $item) {
        $path = trim((string) $item);
        if ($path !== '' && strpos($path, '/uploads/') === 0) {
            $clean[] = $path;
        }
    }
    $clean = array_values(array_unique($clean));
    if ($clean === []) {
        return null;
    }
    return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @return list<array<string, mixed>>
 */
function orders_fetch_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function orders_fetch_events(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT e.*, a.username AS admin_username
         FROM order_events e
         LEFT JOIN admin_users a ON a.id = e.admin_user_id
         WHERE e.order_id = ?
         ORDER BY e.id ASC'
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll() ?: [];
}

function orders_add_event(
    PDO $pdo,
    int $orderId,
    ?string $fromStatus,
    string $toStatus,
    string $actor,
    ?string $message,
    ?int $adminUserId = null
): void {
    if ($actor === 'admin' && $adminUserId === null) {
        if (!function_exists('cms_current_admin_id')) {
            $authPath = dirname(__DIR__) . '/auth.php';
            if (is_readable($authPath)) {
                require_once $authPath;
            }
        }
        if (function_exists('cms_current_admin_id')) {
            $currentId = cms_current_admin_id();
            $adminUserId = $currentId > 0 ? $currentId : null;
        }
    }

    $msg = $message !== null ? trim($message) : '';
    $stmt = $pdo->prepare(
        'INSERT INTO order_events (order_id, from_status, to_status, message, actor, admin_user_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $orderId,
        $fromStatus,
        $toStatus,
        $msg !== '' ? $msg : null,
        $actor,
        $adminUserId,
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function orders_get_by_id(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Absolute path under site public/uploads for a /uploads/... web path. */
function orders_uploads_abs_path(string $webPath): ?string
{
    $webPath = trim($webPath);
    if ($webPath === '' || strpos($webPath, '/uploads/') !== 0) {
        return null;
    }
    $base = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads');
    if ($base === false) {
        $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';
    }
    $rel = substr($webPath, strlen('/uploads/'));
    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    if ($rel === '' || strpos($rel, '..') !== false) {
        return null;
    }
    return $base . DIRECTORY_SEPARATOR . $rel;
}

function orders_delete_upload_file(?string $webPath): void
{
    if ($webPath === null || trim($webPath) === '') {
        return;
    }
    $abs = orders_uploads_abs_path($webPath);
    if ($abs !== null && is_file($abs)) {
        @unlink($abs);
    }
}

/**
 * Save payment proof upload (JPEG/PNG/WebP/PDF, max 5MB).
 * @return string web path /uploads/...
 */
function orders_save_payment_upload(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('آپلود فایل ناموفق بود');
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('فایل آپلود معتبر نیست');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('حداکثر حجم فایل ۵ مگابایت است');
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $file['tmp_name']) ?: '';
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type((string) $file['tmp_name']);
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $extGuess = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        ];
        $mime = $extMap[$extGuess] ?? $mime;
    }

    $map = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
        'application/pdf' => '.pdf',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('فقط تصویر (JPEG/PNG/WebP) یا PDF مجاز است');
    }

    $uploadsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
        throw new RuntimeException('ساخت پوشه uploads ممکن نیست');
    }
    if (!is_writable($uploadsDir)) {
        throw new RuntimeException('پوشه uploads قابل نوشتن نیست');
    }

    $name = 'payment-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . $map[$mime];
    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره فایل ناموفق بود');
    }

    return '/uploads/' . $name;
}

/**
 * Normalize cart line items from client JSON (shared by web + sales app APIs).
 *
 * @param list<mixed> $rawItems
 * @return array<string, array<string, mixed>>
 */
function orders_normalize_cart_items(PDO $pdo, array $rawItems): array
{
    require_once __DIR__ . '/car-model-factories.php';
    require_once __DIR__ . '/product-car-models.php';
    require_once __DIR__ . '/product-categories.php';
    require_once __DIR__ . '/product-series-categories.php';
    require_once __DIR__ . '/product-stock.php';

    products_ensure_stock_schema($pdo);
    cms_ensure_car_model_factories_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    $normalized = [];
    foreach ($rawItems as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $productId = isset($raw['id']) ? (int) $raw['id'] : 0;
        $name = isset($raw['name']) ? trim((string) $raw['name']) : '';
        $quantity = isset($raw['quantity']) ? (int) $raw['quantity'] : 1;
        if ($productId === 0 || $name === '' || $quantity < 1) {
            continue;
        }
        $quantity = min(99, $quantity);
        $unitType = isset($raw['unit_type']) && (string) $raw['unit_type'] === 'pack'
            ? 'pack'
            : 'piece';
        $clientPack = isset($raw['pack_size']) ? (int) $raw['pack_size'] : 0;

        $isSeriesKit = $productId < 0;
        $snapshot = [
            'product_id' => $isSeriesKit ? null : $productId,
            'name' => mb_substr($name, 0, 191),
            'slug' => isset($raw['slug']) ? mb_substr(trim((string) $raw['slug']), 0, 191) : '',
            'price_text' => null,
            'image' => null,
            'quantity' => $quantity,
            'unit_type' => $unitType,
            'pack_size' => $clientPack > 0 ? $clientPack : null,
            'factory_name' => null,
            'model_name' => null,
            'category_name' => null,
            'visual_id' => null,
        ];

        if (isset($raw['price_text']) && is_string($raw['price_text']) && trim($raw['price_text']) !== '') {
            $snapshot['price_text'] = mb_substr(trim($raw['price_text']), 0, 128);
        }
        if (isset($raw['image']) && is_string($raw['image']) && trim($raw['image']) !== '') {
            $snapshot['image'] = mb_substr(trim($raw['image']), 0, 512);
        }
        foreach (['factory_name', 'model_name', 'category_name'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                $snapshot[$key] = mb_substr(trim($raw[$key]), 0, 191);
            }
        }
        if (isset($raw['visual_id']) && is_string($raw['visual_id']) && trim($raw['visual_id']) !== '') {
            $snapshot['visual_id'] = mb_substr(trim($raw['visual_id']), 0, 64);
        }

        if ($isSeriesKit) {
            $seriesId = -$productId;
            $categoryNamesSql = cms_series_category_names_sql('s');
            $seriesStmt = $pdo->prepare(
                'SELECT s.id, s.name, s.slug, s.visual_id, s.price_text, s.pack_size, s.image,
                        ' . $categoryNamesSql . ' AS category_name
                 FROM product_series s
                 WHERE s.id = ? AND s.published = 1
                 LIMIT 1'
            );
            $seriesStmt->execute([$seriesId]);
            $series = $seriesStmt->fetch();
            if ($series) {
                if (!products_series_is_orderable($pdo, $seriesId)) {
                    throw new RuntimeException(products_unavailable_order_message());
                }
                $snapshot['name'] = (string) $series['name'];
                $snapshot['slug'] = (string) ($series['slug'] ?? '');
                $snapshot['price_text'] = $series['price_text'] !== null && trim((string) $series['price_text']) !== ''
                    ? (string) $series['price_text']
                    : $snapshot['price_text'];
                $snapshot['image'] = $series['image'] !== null && trim((string) $series['image']) !== ''
                    ? (string) $series['image']
                    : $snapshot['image'];
                $liveCategory = trim((string) ($series['category_name'] ?? ''));
                $snapshot['category_name'] = $liveCategory !== '' ? $liveCategory : 'سری کیت';
                if ($series['visual_id'] !== null && trim((string) $series['visual_id']) !== '') {
                    $snapshot['visual_id'] = (string) $series['visual_id'];
                }
                $livePack = isset($series['pack_size']) && $series['pack_size'] !== null
                    ? (int) $series['pack_size']
                    : 0;
                if ($livePack > 0) {
                    $snapshot['pack_size'] = $livePack;
                } else {
                    $snapshot['pack_size'] = null;
                    $snapshot['unit_type'] = 'piece';
                }
                if ($snapshot['unit_type'] === 'pack' && (!$snapshot['pack_size'] || (int) $snapshot['pack_size'] <= 0)) {
                    $snapshot['unit_type'] = 'piece';
                }
            } elseif ($unitType === 'pack' && $clientPack <= 0) {
                $snapshot['unit_type'] = 'piece';
                $snapshot['pack_size'] = null;
            }
        }

        if (!$isSeriesKit) {
            $factoryNamesSql = cms_product_factory_names_sql('p');
            $modelNamesSql = cms_product_model_names_sql('p');
            $categoryNamesSql = cms_product_category_names_sql('p');
            $primaryCategoryJoinSql = cms_product_primary_category_join_sql('p');
            $prodStmt = $pdo->prepare(
                'SELECT p.id, p.name, p.slug, p.visual_id, p.price_text, p.image, p.pack_size, p.published, p.stock_qty, p.shop_display_image,
                        COALESCE(NULLIF(p.shop_display_image, \'\'), NULLIF(p.image, \'\'), NULLIF(c.image, \'\')) AS display_image,
                        ' . $factoryNamesSql . ' AS factory_name,
                        ' . $modelNamesSql . ' AS model_name,
                        ' . $categoryNamesSql . ' AS category_name
                 FROM products p
                 ' . $primaryCategoryJoinSql . '
                 WHERE p.id = ?
                 LIMIT 1'
            );
            $prodStmt->execute([$productId]);
            $prod = $prodStmt->fetch();
            if ($prod) {
                if ((int) ($prod['published'] ?? 0) !== 1 || (int) ($prod['stock_qty'] ?? 0) <= 0) {
                    throw new RuntimeException(products_unavailable_order_message());
                }
                $snapshot['name'] = (string) $prod['name'];
                $snapshot['slug'] = (string) ($prod['slug'] ?? '');
                $snapshot['price_text'] = $prod['price_text'] !== null ? (string) $prod['price_text'] : $snapshot['price_text'];
                $snapshot['image'] = $prod['display_image'] !== null && (string) $prod['display_image'] !== ''
                    ? (string) $prod['display_image']
                    : ($prod['image'] !== null ? (string) $prod['image'] : $snapshot['image']);
                $livePack = isset($prod['pack_size']) && $prod['pack_size'] !== null
                    ? (int) $prod['pack_size']
                    : 0;
                if ($livePack > 0) {
                    $snapshot['pack_size'] = $livePack;
                } else {
                    $snapshot['pack_size'] = null;
                    $snapshot['unit_type'] = 'piece';
                }
                if ($snapshot['unit_type'] === 'pack' && (!$snapshot['pack_size'] || (int) $snapshot['pack_size'] <= 0)) {
                    $snapshot['unit_type'] = 'piece';
                }
                $snapshot['factory_name'] = $prod['factory_name'] !== null
                    ? (string) $prod['factory_name']
                    : $snapshot['factory_name'];
                $snapshot['model_name'] = $prod['model_name'] !== null
                    ? (string) $prod['model_name']
                    : $snapshot['model_name'];
                $snapshot['category_name'] = $prod['category_name'] !== null
                    ? (string) $prod['category_name']
                    : $snapshot['category_name'];
                if ($prod['visual_id'] !== null && trim((string) $prod['visual_id']) !== '') {
                    $snapshot['visual_id'] = (string) $prod['visual_id'];
                }
            } elseif ($unitType === 'pack' && $clientPack <= 0) {
                $snapshot['unit_type'] = 'piece';
                $snapshot['pack_size'] = null;
            }
        }

        if (cms_call_for_price_enabled()) {
            $snapshot['price_text'] = null;
        }

        $mergeKey = ($isSeriesKit ? 'series:' . (-$productId) : (string) $productId)
            . ':' . $snapshot['unit_type'];
        if (isset($normalized[$mergeKey])) {
            $normalized[$mergeKey]['quantity'] = min(
                99,
                (int) $normalized[$mergeKey]['quantity'] + $quantity
            );
        } else {
            $normalized[$mergeKey] = $snapshot;
        }
    }

    return $normalized;
}

/**
 * @return array{
 *   branch_id: ?int,
 *   branch_name: ?string,
 *   branch_city: ?string,
 *   branch_province_name: ?string,
 *   branch_phone: ?string
 * }
 */
function orders_branch_snapshot_for_branch_id(PDO $pdo, int $branchId, string $fallbackPhone = ''): array
{
    $snap = [
        'branch_id' => null,
        'branch_name' => null,
        'branch_city' => null,
        'branch_province_name' => null,
        'branch_phone' => null,
    ];
    if ($branchId <= 0) {
        return $snap;
    }

    require_once __DIR__ . '/branches.php';
    branches_ensure_schema($pdo);
    $bStmt = $pdo->prepare(
        'SELECT id, name, city, province_name, phone FROM branches WHERE id = ? LIMIT 1'
    );
    $bStmt->execute([$branchId]);
    $bRow = $bStmt->fetch();
    if ($bRow) {
        $snap = [
            'branch_id' => (int) $bRow['id'],
            'branch_name' => (string) $bRow['name'],
            'branch_city' => (string) ($bRow['city'] ?? ''),
            'branch_province_name' => (string) ($bRow['province_name'] ?? ''),
            'branch_phone' => (string) ($bRow['phone'] ?? $fallbackPhone),
        ];
    }
    return $snap;
}

/**
 * Resolve a site_users row for sales app order FK.
 *
 * @param array{id:int,username:string,display_name:string,branch_id:?int} $salesUser
 * @return array{id:int,phone:string}
 */
function orders_resolve_site_user_for_sales(PDO $pdo, array $salesUser): array
{
    require_once dirname(__DIR__, 2) . '/api/_auth.php';
    require_once __DIR__ . '/branches.php';

    site_auth_ensure_schema($pdo);
    branches_ensure_schema($pdo);

    $phone = '';
    $branchId = isset($salesUser['branch_id']) && $salesUser['branch_id'] !== null
        ? (int) $salesUser['branch_id']
        : 0;
    if ($branchId > 0) {
        $bStmt = $pdo->prepare('SELECT phone FROM branches WHERE id = ? LIMIT 1');
        $bStmt->execute([$branchId]);
        $branchPhone = trim((string) ($bStmt->fetchColumn() ?: ''));
        if ($branchPhone !== '') {
            $phone = $branchPhone;
        }
    }
    if ($phone === '') {
        $phone = 'sales' . str_pad((string) (int) $salesUser['id'], 10, '0', STR_PAD_LEFT);
    }

    $find = $pdo->prepare('SELECT id, phone FROM site_users WHERE phone = ? LIMIT 1');
    $find->execute([$phone]);
    $row = $find->fetch();
    if ($row) {
        if ($branchId > 0) {
            $pdo->prepare('UPDATE site_users SET branch_id = ? WHERE id = ?')
                ->execute([$branchId, (int) $row['id']]);
        }
        return ['id' => (int) $row['id'], 'phone' => (string) $row['phone']];
    }

    $pdo->prepare('INSERT INTO site_users (phone, branch_id) VALUES (?, ?)')
        ->execute([$phone, $branchId > 0 ? $branchId : null]);
    $userId = (int) $pdo->lastInsertId();
    return ['id' => $userId, 'phone' => $phone];
}

/**
 * @param array<string, array<string, mixed>> $normalized
 * @param array{
 *   branch_id: ?int,
 *   branch_name: ?string,
 *   branch_city: ?string,
 *   branch_province_name: ?string,
 *   branch_phone: ?string
 * } $branchSnap
 */
function orders_create_from_normalized(
    PDO $pdo,
    int $userId,
    string $phone,
    array $normalized,
    array $branchSnap,
    ?int $salesUserId,
    string $submitNote,
    array $createOptions = []
): int {
    if ($normalized === []) {
        throw new InvalidArgumentException('اقلام سفارش نامعتبر است');
    }

    $eventActor = trim((string) ($createOptions['event_actor'] ?? 'client'));
    if ($eventActor === '') {
        $eventActor = 'client';
    }
    $customerName = trim((string) ($createOptions['customer_name'] ?? ''));
    $notifyAdmins = !array_key_exists('notify_admins', $createOptions) || (bool) $createOptions['notify_admins'];
    $adminUserId = isset($createOptions['admin_user_id']) ? (int) $createOptions['admin_user_id'] : null;

    $salesUserName = null;
    if ($salesUserId !== null && $salesUserId > 0) {
        if (!function_exists('sales_users_display_name_for_id')) {
            require_once __DIR__ . '/sales-users.php';
        }
        $salesUserName = sales_users_display_name_for_id($pdo, $salesUserId);
    }

    $smsRef = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($createOptions['sms_ref'] ?? '')) ?? '');
    if (strlen($smsRef) < 2 || strlen($smsRef) > 16) {
        $smsRef = '';
    }
    $smsChannel = !empty($createOptions['sms_channel']) ? 1 : 0;
    if (is_file(__DIR__ . '/gsm-sms.php')) {
        require_once __DIR__ . '/gsm-sms.php';
        if (function_exists('gsm_sms_ensure_schema')) {
            gsm_sms_ensure_schema($pdo);
        }
    }

    $pdo->beginTransaction();
    try {
        $publicCode = orders_generate_public_code($pdo);
        $ins = $pdo->prepare(
            'INSERT INTO orders (
               public_code, sms_ref, sms_channel, user_id, sales_user_id, sales_user_name, phone, customer_name, status,
               branch_id, branch_name, branch_city, branch_province_name, branch_phone
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'submitted\', ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $publicCode,
            $smsRef !== '' ? $smsRef : null,
            $smsChannel,
            $userId,
            $salesUserId,
            $salesUserName,
            $phone,
            $customerName !== '' ? $customerName : null,
            $branchSnap['branch_id'],
            $branchSnap['branch_name'],
            $branchSnap['branch_city'],
            $branchSnap['branch_province_name'],
            $branchSnap['branch_phone'],
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemIns = $pdo->prepare(
            'INSERT INTO order_items
              (order_id, product_id, name, slug, price_text, image, quantity,
               unit_type, pack_size, original_quantity, original_unit_type, original_pack_size,
               factory_name, model_name, category_name, visual_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($normalized as $item) {
            $itemIns->execute([
                $orderId,
                $item['product_id'],
                $item['name'],
                $item['slug'],
                $item['price_text'],
                $item['image'],
                $item['quantity'],
                $item['unit_type'],
                $item['pack_size'],
                $item['quantity'],
                $item['unit_type'],
                $item['pack_size'],
                $item['factory_name'],
                $item['model_name'],
                $item['category_name'],
                $item['visual_id'] ?? null,
            ]);
        }

        orders_add_event($pdo, $orderId, null, 'submitted', $eventActor, $submitNote, $adminUserId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($notifyAdmins) {
        if (!function_exists('admin_push_notify_new_order')) {
            $pushLib = __DIR__ . '/admin-push.php';
            if (is_readable($pushLib)) {
                require_once $pushLib;
            }
        }
        if (function_exists('admin_push_notify_new_order')) {
            try {
                admin_push_notify_new_order($pdo, $orderId);
            } catch (Throwable $pushErr) {
                error_log('[orders_create_from_normalized] push failed: ' . $pushErr->getMessage());
            }
        }
    }

    return $orderId;
}

/**
 * @return array{id:int,phone:string}
 */
function orders_resolve_site_user_for_phone(PDO $pdo, string $phone, ?int $branchId = null): array
{
    require_once dirname(__DIR__, 2) . '/api/_auth.php';
    require_once __DIR__ . '/melipayamak.php';

    site_auth_ensure_schema($pdo);
    $phone = cms_sms_normalize_phone($phone);
    if (!site_auth_is_valid_mobile($phone)) {
        throw new RuntimeException('شماره موبایل نامعتبر است');
    }

    $find = $pdo->prepare('SELECT id, phone FROM site_users WHERE phone = ? LIMIT 1');
    $find->execute([$phone]);
    $row = $find->fetch();
    if ($row) {
        if ($branchId !== null && $branchId > 0) {
            $pdo->prepare('UPDATE site_users SET branch_id = ? WHERE id = ?')
                ->execute([$branchId, (int) $row['id']]);
        }
        return ['id' => (int) $row['id'], 'phone' => (string) $row['phone']];
    }

    $pdo->prepare('INSERT INTO site_users (phone, branch_id) VALUES (?, ?)')
        ->execute([$phone, ($branchId !== null && $branchId > 0) ? $branchId : null]);

    return ['id' => (int) $pdo->lastInsertId(), 'phone' => $phone];
}

function orders_sales_user_id_for_branch(PDO $pdo, int $branchId): ?int
{
    if ($branchId <= 0) {
        return null;
    }
    require_once __DIR__ . '/sales-users.php';
    sales_users_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT id FROM sales_users WHERE branch_id = ? AND published = 1 ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([$branchId]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

/**
 * @return array{branches:list<array{id:int,name:string,city:string}>,series_supported:bool}
 */
function orders_admin_manual_sale_meta(PDO $pdo): array
{
    require_once __DIR__ . '/sales-users.php';
    sales_users_ensure_schema($pdo);

    return [
        'branches' => sales_users_branch_options($pdo),
        'series_supported' => true,
    ];
}

/**
 * @param array<string, mixed> $body
 * @param array{id:int,username:string}|null $adminUser
 * @return array<string, mixed>
 */
function orders_admin_create_manual(PDO $pdo, array $body, ?array $adminUser): array
{
    $customerType = trim((string) ($body['customer_type'] ?? ''));
    $rawItems = $body['items'] ?? null;
    if (!is_array($rawItems) || $rawItems === []) {
        throw new RuntimeException('اقلام سفارش الزامی است');
    }

    $normalized = orders_normalize_cart_items($pdo, $rawItems);
    if ($normalized === []) {
        throw new RuntimeException('اقلام سفارش نامعتبر است');
    }

    $note = trim((string) ($body['note'] ?? ''));
    if ($note === '') {
        $note = 'فروش دستی توسط مدیر';
    }

    require_once __DIR__ . '/melipayamak.php';
    require_once dirname(__DIR__, 2) . '/api/_auth.php';

    $salesUserId = null;
    $customerName = null;
    $branchSnap = orders_branch_snapshot_for_branch_id($pdo, 0);

    if ($customerType === 'branch') {
        $branchId = (int) ($body['branch_id'] ?? 0);
        if ($branchId <= 0) {
            throw new RuntimeException('نماینده انتخاب نشده است');
        }
        $branchSnap = orders_branch_snapshot_for_branch_id($pdo, $branchId);
        if ($branchSnap['branch_id'] === null) {
            throw new RuntimeException('نماینده یافت نشد');
        }
        $phone = trim((string) ($branchSnap['branch_phone'] ?? ''));
        if ($phone === '') {
            throw new RuntimeException('شماره نماینده ثبت نشده است');
        }
        $phone = cms_sms_normalize_phone($phone);
        $siteUser = orders_resolve_site_user_for_phone($pdo, $phone, $branchId);
        $salesUserId = orders_sales_user_id_for_branch($pdo, $branchId);
    } elseif ($customerType === 'external') {
        $phone = cms_sms_normalize_phone(trim((string) ($body['phone'] ?? '')));
        if (!site_auth_is_valid_mobile($phone)) {
            throw new RuntimeException('شماره موبایل مشتری نامعتبر است');
        }
        $customerName = trim((string) ($body['customer_name'] ?? ''));
        if ($customerName === '') {
            throw new RuntimeException('نام مشتری الزامی است');
        }
        $siteUser = orders_resolve_site_user_for_phone($pdo, $phone, null);
    } else {
        throw new RuntimeException('نوع مشتری نامعتبر است');
    }

    $orderId = orders_create_from_normalized(
        $pdo,
        (int) $siteUser['id'],
        (string) $siteUser['phone'],
        $normalized,
        $branchSnap,
        $salesUserId,
        $note,
        [
            'event_actor' => 'admin',
            'customer_name' => $customerName,
            'notify_admins' => false,
            'admin_user_id' => $adminUser !== null ? (int) $adminUser['id'] : null,
        ]
    );

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        throw new RuntimeException('خطا در ایجاد سفارش');
    }

    $items = orders_fetch_items($pdo, $orderId);
    $events = orders_fetch_events($pdo, $orderId);
    $orderPayload = orders_admin_serialize($order, $items, $events);

    if (!function_exists('invoices_display_totals_from_items')) {
        require_once __DIR__ . '/invoices.php';
    }

    return [
        'ok' => true,
        'order_id' => $orderId,
        'pricing_api_version' => 2,
        'order' => $orderPayload,
        'totals' => invoices_display_totals_from_items($orderPayload['items'] ?? $items),
        'status_labels' => orders_status_labels(),
        'allowed_transitions' => orders_allowed_transitions()[(string) $order['status']] ?? [],
        'can_delete' => orders_can_delete((string) $order['status']),
    ];
}

/**
 * @return array{
 *   items: list<array<string, mixed>>,
 *   total: int,
 *   page: int,
 *   per_page: int,
 *   total_pages: int,
 *   submitted_count: int
 * }
 */
function orders_admin_list(
    PDO $pdo,
    string $scope = 'customers',
    string $statusFilter = 'all',
    string $searchQ = '',
    int $page = 1,
    int $pageSize = 20,
    string $ongoingMode = 'new_order',
    string $clientPhone = '',
    int $branchId = 0,
    int $createdSinceDays = 0
): array {
    $listWhere = orders_admin_list_where(
        $scope,
        $statusFilter,
        $searchQ,
        'all',
        $ongoingMode,
        $clientPhone,
        $branchId,
        $createdSinceDays
    );
    $scope = $listWhere['scope'];
    $scopeSql = $listWhere['scope_sql'];
    $whereSql = $listWhere['sql'];
    $params = $listWhere['params'];

    if ($page < 1) {
        $page = 1;
    }
    if ($pageSize < 1) {
        $pageSize = 20;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT o.*,
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
            FROM orders o
            WHERE {$whereSql}
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT " . (int) $pageSize . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $submittedStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders o WHERE {$scopeSql} AND o.status = 'submitted'"
    );
    $submittedStmt->execute();
    $submittedCount = (int) $submittedStmt->fetchColumn();

    $branchSubmittedStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders o WHERE o.branch_id IS NOT NULL AND o.status = 'submitted'"
    );
    $branchSubmittedStmt->execute();
    $branchSubmittedCount = (int) $branchSubmittedStmt->fetchColumn();

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'public_code' => (string) $row['public_code'],
            'phone' => (string) $row['phone'],
            'customer_name' => isset($row['customer_name']) && $row['customer_name'] !== null
                && trim((string) $row['customer_name']) !== ''
                ? trim((string) $row['customer_name'])
                : null,
            'status' => (string) $row['status'],
            'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== null
                ? (int) $row['branch_id']
                : null,
            'branch_name' => isset($row['branch_name']) && $row['branch_name'] !== null
                ? (string) $row['branch_name']
                : null,
            'branch_phone' => isset($row['branch_phone']) && $row['branch_phone'] !== null
                ? (string) $row['branch_phone']
                : null,
            'sales_user_id' => isset($row['sales_user_id']) && $row['sales_user_id'] !== null
                ? (int) $row['sales_user_id']
                : null,
            'sales_user_name' => isset($row['sales_user_name']) && $row['sales_user_name'] !== null
                ? (string) $row['sales_user_name']
                : null,
            'item_count' => (int) ($row['item_count'] ?? 0),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    return [
        'items' => $items,
        'total' => $totalRows,
        'page' => $page,
        'per_page' => $pageSize,
        'total_pages' => $totalPages,
        'submitted_count' => $submittedCount,
        'branch_submitted_count' => $branchSubmittedCount,
        'list_scope' => $scope,
    ];
}

/**
 * Unique independent customers (branch_id IS NULL) who have placed at least one order.
 *
 * @return array{
 *   items: list<array<string, mixed>>,
 *   total: int,
 *   page: int,
 *   per_page: int,
 *   total_pages: int
 * }
 */
function orders_admin_clients_list(
    PDO $pdo,
    string $searchQ = '',
    int $page = 1,
    int $pageSize = 30,
    string $scope = 'customers'
): array {
    if ($scope !== 'branches') {
        $scope = 'customers';
    }
    $searchQ = orders_normalize_search_query($searchQ);
    $params = [];
    $isBranches = $scope === 'branches';

    if ($isBranches) {
        $where = ['o.branch_id IS NOT NULL'];
        if ($searchQ !== '') {
            $like = '%' . $searchQ . '%';
            $where[] = '(COALESCE(o.branch_name, \'\') LIKE ?
                OR COALESCE(o.branch_phone, \'\') LIKE ?
                OR o.phone LIKE ?
                OR COALESCE(o.branch_city, \'\') LIKE ?
                OR COALESCE(o.branch_province_name, \'\') LIKE ?)';
            $params = [$like, $like, $like, $like, $like];
        }
        $groupExpr = 'o.branch_id';
        $groupSelect = 'o.branch_id AS party_key';
        $joinSelect = 'g.party_key AS branch_id,
                       o.phone,
                       o.branch_name,
                       o.branch_phone,
                       o.branch_city,
                       o.branch_province_name,
                       o.sales_user_id,
                       o.sales_user_name';
    } else {
        $where = ['o.branch_id IS NULL', "TRIM(o.phone) <> ''"];
        if ($searchQ !== '') {
            $like = '%' . $searchQ . '%';
            $where[] = '(o.phone LIKE ? OR COALESCE(o.sales_user_name, \'\') LIKE ?)';
            $params = [$like, $like];
        }
        $groupExpr = 'o.phone';
        $groupSelect = 'o.phone AS party_key';
        $joinSelect = 'g.party_key AS phone,
                       o.sales_user_id,
                       o.sales_user_name';
    }
    $whereSql = implode(' AND ', $where);

    if ($page < 1) {
        $page = 1;
    }
    if ($pageSize < 1) {
        $pageSize = 30;
    }

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (SELECT {$groupExpr} FROM orders o WHERE {$whereSql} GROUP BY {$groupExpr}) grouped"
    );
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT g.order_count,
                   g.last_order_at,
                   g.last_order_id,
                   {$joinSelect}
            FROM (
                SELECT {$groupSelect},
                       COUNT(*) AS order_count,
                       MAX(o.created_at) AS last_order_at,
                       MAX(o.id) AS last_order_id
                FROM orders o
                WHERE {$whereSql}
                GROUP BY {$groupExpr}
            ) g
            INNER JOIN orders o ON o.id = g.last_order_id
            ORDER BY g.last_order_at DESC, g.last_order_id DESC
            LIMIT " . (int) $pageSize . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $items = [];
    foreach ($rows as $row) {
        $salesName = isset($row['sales_user_name']) && $row['sales_user_name'] !== null
            ? trim((string) $row['sales_user_name'])
            : '';
        $branchName = isset($row['branch_name']) && $row['branch_name'] !== null
            ? trim((string) $row['branch_name'])
            : '';
        $branchPhone = isset($row['branch_phone']) && $row['branch_phone'] !== null
            ? trim((string) $row['branch_phone'])
            : '';
        $branchCity = isset($row['branch_city']) && $row['branch_city'] !== null
            ? trim((string) $row['branch_city'])
            : '';
        $branchProvince = isset($row['branch_province_name']) && $row['branch_province_name'] !== null
            ? trim((string) $row['branch_province_name'])
            : '';
        $items[] = [
            'phone' => (string) ($row['phone'] ?? ''),
            'order_count' => (int) ($row['order_count'] ?? 0),
            'last_order_at' => (string) ($row['last_order_at'] ?? ''),
            'last_order_id' => (int) ($row['last_order_id'] ?? 0),
            'sales_user_id' => isset($row['sales_user_id']) && $row['sales_user_id'] !== null
                ? (int) $row['sales_user_id']
                : null,
            'sales_user_name' => $salesName !== '' ? $salesName : null,
            'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== null
                ? (int) $row['branch_id']
                : null,
            'branch_name' => $branchName !== '' ? $branchName : null,
            'branch_phone' => $branchPhone !== '' ? $branchPhone : null,
            'branch_city' => $branchCity !== '' ? $branchCity : null,
            'branch_province_name' => $branchProvince !== '' ? $branchProvince : null,
        ];
    }

    return [
        'items' => $items,
        'total' => $totalRows,
        'page' => $page,
        'per_page' => $pageSize,
        'total_pages' => $totalPages,
    ];
}

/**
 * Remove uploaded payment proof and invoice files for an order.
 *
 * @param array<string, mixed> $order
 */
function orders_purge_order_files(array $order): void
{
    foreach (orders_payment_files_list($order) as $path) {
        orders_delete_upload_file($path);
    }
    $legacy = isset($order['payment_file']) ? trim((string) $order['payment_file']) : '';
    if ($legacy !== '') {
        orders_delete_upload_file($legacy);
    }
    foreach (['pre_invoice_file', 'final_invoice_file'] as $key) {
        $path = isset($order[$key]) ? trim((string) $order[$key]) : '';
        if ($path !== '') {
            orders_delete_upload_file($path);
        }
    }
}

function orders_purge_analytics(PDO $pdo, int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }

    $analyticsLib = __DIR__ . '/analytics-orders.php';
    if (!is_readable($analyticsLib)) {
        return;
    }

    require_once $analyticsLib;

    try {
        analytics_orders_ensure_schema($pdo);
        $tables = [
            'analytics_order_line_facts',
            'analytics_order_cheque_facts',
            'analytics_order_stage_times',
            'analytics_order_facts',
        ];
        foreach ($tables as $table) {
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE order_id = ?");
            $stmt->execute([$orderId]);
        }
    } catch (Throwable $e) {
        error_log('[orders_delete] analytics purge failed: ' . $e->getMessage());
    }
}

/**
 * Permanently delete an archived order and all related rows/files.
 *
 * @param array<string, mixed> $order
 * @return array{message: string, deleted: true}
 */
function orders_delete(PDO $pdo, array $order, string $message = '', bool $adminForce = false): array
{
    require_once __DIR__ . '/admin-audit.php';
    require_once __DIR__ . '/order-messages.php';

    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('سفارش نامعتبر');
    }

    $current = (string) ($order['status'] ?? '');
    if (!$adminForce && !orders_can_delete($current)) {
        throw new RuntimeException('فقط سفارش‌های لغو یا رد شده قابل حذف دائمی هستند');
    }

    orders_purge_order_files($order);

    $pdo->beginTransaction();
    try {
        orders_purge_analytics($pdo, $orderId);

        try {
            $pdo->prepare('DELETE FROM site_order_reads WHERE order_id = ?')->execute([$orderId]);
        } catch (Throwable $e) {
            /* optional table */
        }

        order_cheques_ensure_schema($pdo);
        $pdo->prepare('DELETE FROM order_cheques WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM order_events WHERE order_id = ?')->execute([$orderId]);
        order_messages_ensure_schema($pdo);
        $pdo->prepare('DELETE FROM order_messages WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $detail = null;
    $message = trim($message);
    if ($message !== '') {
        $detail = ['reason' => $message];
    }
    orders_admin_audit($pdo, $order, 'delete', $detail);

    return [
        'message' => 'سفارش به‌طور دائمی از پایگاه داده حذف شد',
        'deleted' => true,
    ];
}

/**
 * Cancel an order before payment (client or admin).
 *
 * @return array{message: string}
 */
function orders_cancel(PDO $pdo, array $order, string $actor, string $message = ''): array
{
    require_once __DIR__ . '/admin-audit.php';

    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('سفارش نامعتبر');
    }

    $current = (string) ($order['status'] ?? '');
    if (!orders_can_cancel($current)) {
        throw new RuntimeException('در این وضعیت امکان لغو سفارش نیست');
    }

    if ($actor === 'admin' && trim($message) === '') {
        throw new RuntimeException('علت لغو سفارش الزامی است');
    }

    $message = trim($message);
    $eventMessage = $message !== '' ? $message : null;
    if ($actor === 'client' && $eventMessage === null) {
        $eventMessage = 'سفارش توسط مشتری لغو شد';
    }

    $pdo->beginTransaction();
    require_once __DIR__ . '/product-stock.php';
    if (products_order_stock_applied($pdo, $orderId)) {
        products_restore_for_order($pdo, $orderId);
    }
    $upd = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $upd->execute(['cancelled', $orderId]);
    orders_add_event($pdo, $orderId, $current, 'cancelled', $actor, $eventMessage);
    $pdo->commit();

    if ($actor === 'admin') {
        orders_admin_audit($pdo, $order, 'cancel');
    }

    orders_notify_sales_client($pdo, $orderId, 'cancelled', $message);

    if ($actor === 'client') {
        if (!function_exists('admin_push_notify_order_activity')) {
            $pushLib = __DIR__ . '/admin-push.php';
            if (is_readable($pushLib)) {
                require_once $pushLib;
            }
        }
        if (function_exists('admin_push_notify_order_activity')) {
            try {
                $preview = $message !== '' ? $message : 'مشتری سفارش را لغو کرد';
                admin_push_notify_order_activity($pdo, $orderId, 'order_cancelled', $preview);
            } catch (Throwable $e) {
                error_log('[orders_cancel] admin push failed: ' . $e->getMessage());
            }
        }
    }

    return ['message' => 'سفارش لغو شد'];
}

/**
 * Apply a CMS admin order action (shared by CMS page and mobile API).
 *
 * @param array<string, string> $prices item_id => price_text
 * @param array<string, mixed> $itemAdjustments item_id => {quantity, unit_type?, pack_size?}
 * @return array{message: string, invoice_warning: ?string}
 */
function orders_admin_apply_action(
    PDO $pdo,
    int $orderId,
    string $action,
    string $message = '',
    array $prices = [],
    string $preInvoiceDueAt = '',
    array $cheque = [],
    string $paymentMethod = '',
    array $itemAdjustments = [],
    bool $advancePricingStep = false
): array {
    require_once __DIR__ . '/invoices.php';
    require_once __DIR__ . '/order-cheques.php';
    require_once __DIR__ . '/admin-audit.php';

    if ($orderId <= 0) {
        throw new RuntimeException('سفارش نامعتبر');
    }
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        throw new RuntimeException('سفارش یافت نشد');
    }

    if ($action === 'delete') {
        return orders_delete($pdo, $order, $message, false);
    }

    if ($action === 'remove') {
        return orders_delete($pdo, $order, $message, true);
    }

    if ($action === 'add_cheque') {
        $result = order_cheques_add($pdo, $order, $cheque);
        orders_admin_audit($pdo, $order, 'add_cheque');

        return $result;
    }
    if ($action === 'update_cheque') {
        $result = order_cheques_update($pdo, $order, $cheque);
        orders_admin_audit($pdo, $order, 'update_cheque');

        return $result;
    }
    if ($action === 'set_cheque_result') {
        $chequeId = (int) ($cheque['id'] ?? 0);
        $result = trim((string) ($cheque['bank_result'] ?? ''));

        $out = order_cheques_set_result($pdo, $order, $chequeId, $result);
        orders_admin_audit($pdo, $order, 'set_cheque_result', ['bank_result' => $result]);

        return $out;
    }
    if ($action === 'delete_cheque') {
        $chequeId = (int) ($cheque['id'] ?? 0);

        $out = order_cheques_delete($pdo, $order, $chequeId);
        orders_admin_audit($pdo, $order, 'delete_cheque');

        return $out;
    }

    $current = (string) $order['status'];
    $allowed = orders_allowed_transitions();
    $invoiceWarning = null;
    $resultMessage = 'وضعیت سفارش به‌روز شد';

    if ($action === 'set_payment_method') {
        if (!in_array($current, ['accepted', 'payment_proof_sent'], true)) {
            throw new RuntimeException('تعیین روش پرداخت فقط در مرحله پرداخت مجاز است');
        }
        $method = orders_normalize_payment_method($paymentMethod);
        if ($method === null) {
            throw new RuntimeException('روش پرداخت نامعتبر است');
        }
        $label = $method === 'cash' ? 'نقد' : 'چک';
        $pdo->beginTransaction();
        if ($current === 'accepted') {
            $upd = $pdo->prepare('UPDATE orders SET payment_method = ?, status = ? WHERE id = ?');
            $upd->execute([$method, 'payment_proof_sent', $orderId]);
            orders_add_event(
                $pdo,
                $orderId,
                'accepted',
                'payment_proof_sent',
                'admin',
                'روش پرداخت: ' . $label
            );
        } else {
            $upd = $pdo->prepare('UPDATE orders SET payment_method = ? WHERE id = ?');
            $upd->execute([$method, $orderId]);
            orders_add_event(
                $pdo,
                $orderId,
                $current,
                $current,
                'admin',
                'روش پرداخت: ' . $label
            );
        }
        $pdo->commit();
        orders_notify_sales_client($pdo, $orderId, 'payment_proof_sent', 'روش پرداخت: ' . $label);
        orders_admin_audit($pdo, $order, 'set_payment_method', ['payment_method' => $method]);

        return ['message' => 'روش پرداخت ثبت شد: ' . $label, 'invoice_warning' => null];
    }

    if ($action === 'warn_payment') {
        if ($current !== 'payment_proof_sent') {
            throw new RuntimeException('هشدار فقط برای سفارش‌های دارای مدارک پرداخت مجاز است');
        }
        if ($message === '') {
            throw new RuntimeException('متن هشدار درباره نقص مدارک الزامی است');
        }
        $pdo->beginTransaction();
        $upd = $pdo->prepare(
            "UPDATE orders SET payment_warning = ?, payment_warning_state = 'open' WHERE id = ?"
        );
        $upd->execute([$message, $orderId]);
        orders_add_event(
            $pdo,
            $orderId,
            'payment_proof_sent',
            'payment_proof_sent',
            'admin',
            'هشدار نقص مدارک: ' . $message
        );
        $pdo->commit();
        orders_notify_sales_client($pdo, $orderId, 'warn_payment', $message);
        orders_admin_audit($pdo, $order, 'warn_payment');

        return ['message' => 'هشدار نقص مدارک برای مشتری ارسال شد', 'invoice_warning' => null];
    }

    if ($action === 'save_prices') {
        if (!in_array($current, ['submitted', 'accepted', 'payment_proof_sent'], true)) {
            throw new RuntimeException('در این وضعیت امکان ویرایش قیمت نیست');
        }
        if ($prices === [] && $itemAdjustments === []) {
            throw new RuntimeException('قیمت‌ها نامعتبر است');
        }
        $qtyChanged = orders_apply_item_adjustments($pdo, $orderId, $itemAdjustments, $current);
        $upd = $pdo->prepare('UPDATE order_items SET price_text = ? WHERE id = ? AND order_id = ?');
        $changed = 0;
        foreach ($prices as $itemId => $priceRaw) {
            $itemId = (int) $itemId;
            if ($itemId <= 0) {
                continue;
            }
            try {
                $normalized = invoices_normalize_price_text((string) $priceRaw);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException('قلم #' . $itemId . ': ' . $e->getMessage());
            }
            $upd->execute([$normalized, $itemId, $orderId]);
            $changed += $upd->rowCount() > 0 ? 1 : 0;
        }
        orders_admin_audit($pdo, $order, 'save_prices', ['changed' => $changed, 'qty_changed' => $qtyChanged]);

        $savedItems = orders_fetch_items($pdo, $orderId);
        orders_sync_items_adjustment_state($pdo, $orderId, $savedItems, $qtyChanged);
        $order = orders_get_by_id($pdo, $orderId) ?? $order;
        if ($advancePricingStep) {
            orders_require_items_adjustment_confirmed($order, $savedItems);
        }

        $parts = [];
        if ($changed > 0) {
            $parts[] = 'قیمت‌های تومان ذخیره شد';
        }
        if ($qtyChanged > 0) {
            $parts[] = 'تعداد اقلام به‌روزرسانی شد';
        }
        if ($parts === []) {
            $parts[] = 'تغییری ثبت نشد';
        }

        return [
            'message' => implode(' — ', $parts),
            'invoice_warning' => null,
        ];
    }

    if ($action === 'accept') {
        if ($current !== 'submitted') {
            throw new RuntimeException('تأیید انبار فقط برای سفارش تازه ثبت‌شده مجاز است');
        }
        $pdo->beginTransaction();
        $qtyChanged = orders_apply_item_adjustments($pdo, $orderId, $itemAdjustments, $current);
        if ($prices !== []) {
            $updPrice = $pdo->prepare('UPDATE order_items SET price_text = ? WHERE id = ? AND order_id = ?');
            foreach ($prices as $itemId => $priceRaw) {
                $itemId = (int) $itemId;
                if ($itemId <= 0) {
                    continue;
                }
                try {
                    $normalized = invoices_normalize_price_text((string) $priceRaw);
                } catch (InvalidArgumentException $e) {
                    throw new RuntimeException('قلم #' . $itemId . ': ' . $e->getMessage());
                }
                if ($normalized === null || $normalized === '') {
                    throw new RuntimeException('قیمت تومان همه اقلام برای تأیید انبار الزامی است');
                }
                $updPrice->execute([$normalized, $itemId, $orderId]);
            }
        }
        $pricedItems = orders_fetch_items($pdo, $orderId);
        if ($pricedItems === []) {
            throw new RuntimeException('سفارش بدون قلم است');
        }
        orders_sync_items_adjustment_state($pdo, $orderId, $pricedItems, $qtyChanged);
        $order = orders_get_by_id($pdo, $orderId) ?? $order;
        orders_require_items_adjustment_confirmed($order, $pricedItems);
        $activeItems = orders_active_order_items($pricedItems);
        if ($activeItems === []) {
            throw new RuntimeException('همه اقلام حذف شده‌اند؛ سفارش را رد یا لغو کنید');
        }
        foreach ($activeItems as $item) {
            $parsed = invoices_parse_toman_amount(
                isset($item['price_text']) ? (string) $item['price_text'] : null
            );
            if ($parsed === null) {
                throw new RuntimeException('قبل از تأیید انبار، قیمت تومان همه اقلام را وارد کنید');
            }
        }
        $dueAt = trim($preInvoiceDueAt);
        if ($dueAt === '') {
            $dueAt = date('Y-m-d', strtotime('+7 days'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueAt)) {
            throw new RuntimeException('تاریخ سررسید پیش‌فاکتور نامعتبر است');
        }
        require_once __DIR__ . '/product-stock.php';
        products_decrement_for_order($pdo, $orderId);
        $upd = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $upd->execute(['accepted', $orderId]);
        orders_add_event(
            $pdo,
            $orderId,
            $current,
            'accepted',
            'admin',
            $message !== '' ? $message : 'تأیید انبار و ارسال خودکار پیش‌فاکتور'
        );
        $pdo->commit();

        $invoiceWarning = null;
        $resultMessage = 'انبار تأیید شد و پیش‌فاکتور برای مشتری ارسال شد';
        try {
            invoices_issue_pre($pdo, $orderId, $dueAt);
        } catch (Throwable $invErr) {
            $invoiceWarning = $invErr->getMessage();
            $resultMessage = 'انبار تأیید شد اما صدور پیش‌فاکتور ناموفق بود';
        }
        orders_notify_sales_client($pdo, $orderId, 'accepted', $message);
        orders_admin_audit($pdo, $order, 'accept');

        return ['message' => $resultMessage, 'invoice_warning' => $invoiceWarning];
    }

    if ($action === 'issue_pre_invoice') {
        if (!in_array($current, ['accepted', 'payment_proof_sent'], true)) {
            throw new RuntimeException('ابتدا انبار را با قیمت‌گذاری تأیید کنید؛ پیش‌فاکتور هنگام تأیید انبار ارسال می‌شود');
        }
        if ($prices !== []) {
            $upd = $pdo->prepare('UPDATE order_items SET price_text = ? WHERE id = ? AND order_id = ?');
            foreach ($prices as $itemId => $priceRaw) {
                $itemId = (int) $itemId;
                if ($itemId <= 0) {
                    continue;
                }
                try {
                    $normalized = invoices_normalize_price_text((string) $priceRaw);
                } catch (InvalidArgumentException $e) {
                    throw new RuntimeException('قلم #' . $itemId . ': ' . $e->getMessage());
                }
                $upd->execute([$normalized, $itemId, $orderId]);
            }
        }
        $dueAt = trim($preInvoiceDueAt);
        invoices_issue_pre($pdo, $orderId, $dueAt);
        orders_admin_audit($pdo, $order, 'issue_pre_invoice');

        return [
            'message' => 'پیش‌فاکتور صادر شد و در پیگیری سفارش مشتری قابل دریافت است',
            'invoice_warning' => null,
        ];
    }

    if ($action === 'cancel') {
        return orders_cancel($pdo, $order, 'admin', $message);
    }

    $nextMap = [
        'reject' => 'rejected',
        'mark_paid' => 'paid',
        'mark_shipped' => 'shipped',
        'mark_not_received' => 'not_received',
        'mark_returned' => 'returned_to_origin',
        'mark_lost' => 'lost',
        'mark_received' => 'received',
    ];
    if (!isset($nextMap[$action])) {
        throw new RuntimeException('عملیات نامعتبر');
    }
    $next = $nextMap[$action];
    $allowedNext = $allowed[$current] ?? [];
    if (!in_array($next, $allowedNext, true)) {
        throw new RuntimeException('این تغییر وضعیت مجاز نیست');
    }
    if ($action === 'reject' && $message === '') {
        throw new RuntimeException('علت رد انبار (مثلاً موجود نبودن کالا) الزامی است');
    }
    if ($action === 'mark_paid') {
        $chequeRows = order_cheques_fetch($pdo, $orderId);
        $orderItems = orders_fetch_items($pdo, $orderId);
        $orderTotals = invoices_totals_from_items($orderItems);
        $readiness = orders_mark_paid_readiness(
            $order,
            $chequeRows,
            (int) ($orderTotals['total'] ?? 0)
        );
        if (!$readiness['can']) {
            throw new RuntimeException($readiness['reason'] ?? 'امکان تأیید پرداخت وجود ندارد');
        }
    }

    $pdo->beginTransaction();
    if ($next === 'paid') {
        $upd = $pdo->prepare(
            'UPDATE orders SET status = ?, payment_warning = NULL, payment_warning_state = NULL WHERE id = ?'
        );
    } else {
        $upd = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
    }
    $upd->execute([$next, $orderId]);
    orders_add_event($pdo, $orderId, $current, $next, 'admin', $message !== '' ? $message : null);
    $pdo->commit();

    if ($next === 'paid') {
        try {
            invoices_issue_final($pdo, $orderId);
            orders_admin_audit($pdo, $order, 'issue_final_invoice');
            $resultMessage = 'پرداخت تأیید و فاکتور نهایی صادر شد';
        } catch (Throwable $invErr) {
            $invoiceWarning = $invErr->getMessage();
            $resultMessage = 'پرداخت تأیید شد اما صدور فاکتور نهایی ناموفق بود';
        }
    } elseif ($next === 'rejected') {
        $resultMessage = 'سفارش رد و بایگانی شد — برای مشتری بسته شده است';
    } elseif ($next === 'cancelled') {
        $resultMessage = 'سفارش لغو شد';
    } elseif ($next === 'received') {
        $resultMessage = 'تحویل تأیید شد — سفارش تمام شد';
    }

    orders_admin_audit($pdo, $order, $action);

    orders_notify_sales_client($pdo, $orderId, $next, $message);

    return ['message' => $resultMessage, 'invoice_warning' => $invoiceWarning];
}

function orders_sales_user_owns_order(array $order, int $salesUserId): bool
{
    if ($salesUserId <= 0) {
        return false;
    }
    return isset($order['sales_user_id'])
        && $order['sales_user_id'] !== null
        && (int) $order['sales_user_id'] === $salesUserId;
}
