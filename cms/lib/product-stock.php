<?php
declare(strict_types=1);

const PRODUCTS_STOCK_MAX = 999999;
const PRODUCTS_STOCK_DEFAULT = 999;

function products_ensure_stock_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $exists = $pdo->query("SHOW COLUMNS FROM products LIKE 'stock_qty'")->fetchAll();
    if (count($exists) === 0) {
        $pdo->exec(
            'ALTER TABLE products ADD COLUMN stock_qty INT UNSIGNED NOT NULL DEFAULT '
            . PRODUCTS_STOCK_DEFAULT
            . ' AFTER pack_size'
        );
    }

    $orderCols = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM orders')->fetchAll() ?: [] as $row) {
            $orderCols[(string) ($row['Field'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        $orderCols = [];
    }
    if (!isset($orderCols['stock_applied'])) {
        try {
            $pdo->exec(
                'ALTER TABLE orders ADD COLUMN stock_applied TINYINT(1) NOT NULL DEFAULT 0 AFTER status'
            );
        } catch (Throwable $e) {
            /* ignore */
        }
    }

    $ready = true;
}

function products_normalize_stock_qty(mixed $raw, int $default = PRODUCTS_STOCK_DEFAULT): int
{
    if ($raw === null || $raw === '') {
        return $default;
    }
    $qty = (int) $raw;
    if ($qty < 0) {
        throw new RuntimeException('موجودی انبار نمی‌تواند منفی باشد');
    }
    if ($qty > PRODUCTS_STOCK_MAX) {
        throw new RuntimeException('موجودی انبار بیش از حد مجاز است');
    }

    return $qty;
}

function products_stock_qty(PDO $pdo, int $productId): int
{
    products_ensure_stock_schema($pdo);
    if ($productId <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT stock_qty FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 0;
    }

    return max(0, (int) ($row['stock_qty'] ?? 0));
}

function products_is_orderable(PDO $pdo, int $productId): bool
{
    products_ensure_stock_schema($pdo);
    if ($productId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT published, stock_qty FROM products WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    return (int) ($row['published'] ?? 0) === 1
        && (int) ($row['stock_qty'] ?? 0) > 0;
}

function products_orderable_sql(string $productAlias = 'p'): string
{
    return '(' . $productAlias . '.published = 1 AND ' . $productAlias . '.stock_qty > 0)';
}

/**
 * @return list<int>
 */
function products_series_member_ids(PDO $pdo, int $seriesId): array
{
    if ($seriesId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT psi.product_id
         FROM product_series_items psi
         INNER JOIN products p ON p.id = psi.product_id
         WHERE psi.series_id = ?
         ORDER BY psi.sort_order ASC, psi.product_id ASC'
    );
    $stmt->execute([$seriesId]);
    $ids = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $ids[] = (int) ($row['product_id'] ?? 0);
    }

    return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
}

function products_series_is_orderable(PDO $pdo, int $seriesId): bool
{
    products_ensure_stock_schema($pdo);
    if ($seriesId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT id FROM product_series WHERE id = ? AND published = 1 LIMIT 1'
    );
    $stmt->execute([$seriesId]);
    if (!$stmt->fetch()) {
        return false;
    }

    $memberIds = products_series_member_ids($pdo, $seriesId);
    if ($memberIds === []) {
        return false;
    }
    foreach ($memberIds as $productId) {
        if (!products_is_orderable($pdo, $productId)) {
            return false;
        }
    }

    return true;
}

function products_resolve_series_id_from_order_item(PDO $pdo, array $item): int
{
    $slug = trim((string) ($item['slug'] ?? ''));
    if ($slug !== '') {
        $stmt = $pdo->prepare('SELECT id FROM product_series WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) ($row['id'] ?? 0);
        }
    }

    $name = trim((string) ($item['name'] ?? ''));
    if ($name !== '') {
        $stmt = $pdo->prepare('SELECT id FROM product_series WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) ($row['id'] ?? 0);
        }
    }

    return 0;
}

/**
 * @return array<string, int> map of product_id => quantity to deduct
 */
function products_stock_deductions_for_order_items(PDO $pdo, array $items): array
{
    $deductions = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $productId = isset($item['product_id']) && $item['product_id'] !== null
            ? (int) $item['product_id']
            : 0;
        if ($productId > 0) {
            $deductions[(string) $productId] = ($deductions[(string) $productId] ?? 0) + $quantity;
            continue;
        }

        $seriesId = products_resolve_series_id_from_order_item($pdo, $item);
        if ($seriesId <= 0) {
            throw new RuntimeException('قلم سری کیت در سفارش شناسایی نشد');
        }
        foreach (products_series_member_ids($pdo, $seriesId) as $memberId) {
            $deductions[(string) $memberId] = ($deductions[(string) $memberId] ?? 0) + $quantity;
        }
    }

    return $deductions;
}

function products_assert_stock_available_for_deductions(PDO $pdo, array $deductions): void
{
    foreach ($deductions as $productIdRaw => $needed) {
        $productId = (int) $productIdRaw;
        $needed = (int) $needed;
        if ($productId <= 0 || $needed <= 0) {
            continue;
        }
        $available = products_stock_qty($pdo, $productId);
        if ($available < $needed) {
            $nameStmt = $pdo->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
            $nameStmt->execute([$productId]);
            $nameRow = $nameStmt->fetch();
            $label = $nameRow ? (string) ($nameRow['name'] ?? '') : ('#' . $productId);
            throw new RuntimeException(
                'موجودی انبار برای «' . $label . '» کافی نیست (نیاز: '
                . $needed . '، موجود: ' . $available . ')'
            );
        }
    }
}

function products_apply_stock_deductions(PDO $pdo, array $deductions): void
{
    products_ensure_stock_schema($pdo);
    $upd = $pdo->prepare(
        'UPDATE products SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?'
    );
    foreach ($deductions as $productIdRaw => $amount) {
        $productId = (int) $productIdRaw;
        $amount = (int) $amount;
        if ($productId <= 0 || $amount <= 0) {
            continue;
        }
        $upd->execute([$amount, $productId]);
    }
}

function products_apply_stock_restorations(PDO $pdo, array $deductions): void
{
    products_ensure_stock_schema($pdo);
    $upd = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?');
    foreach ($deductions as $productIdRaw => $amount) {
        $productId = (int) $productIdRaw;
        $amount = (int) $amount;
        if ($productId <= 0 || $amount <= 0) {
            continue;
        }
        $upd->execute([$amount, $productId]);
    }
}

function products_order_stock_applied(PDO $pdo, int $orderId): bool
{
    products_ensure_stock_schema($pdo);
    $stmt = $pdo->prepare('SELECT stock_applied FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();

    return $row && (int) ($row['stock_applied'] ?? 0) === 1;
}

function products_decrement_for_order(PDO $pdo, int $orderId): void
{
    products_ensure_stock_schema($pdo);
    if (products_order_stock_applied($pdo, $orderId)) {
        return;
    }

    if (!function_exists('orders_fetch_items')) {
        require_once __DIR__ . '/orders.php';
    }
    $items = orders_fetch_items($pdo, $orderId);
    if ($items === []) {
        throw new RuntimeException('سفارش بدون قلم است');
    }

    $deductions = products_stock_deductions_for_order_items($pdo, $items);
    products_assert_stock_available_for_deductions($pdo, $deductions);
    products_apply_stock_deductions($pdo, $deductions);

    $upd = $pdo->prepare('UPDATE orders SET stock_applied = 1 WHERE id = ?');
    $upd->execute([$orderId]);
}

function products_restore_for_order(PDO $pdo, int $orderId): void
{
    products_ensure_stock_schema($pdo);
    if (!products_order_stock_applied($pdo, $orderId)) {
        return;
    }

    if (!function_exists('orders_fetch_items')) {
        require_once __DIR__ . '/orders.php';
    }
    $items = orders_fetch_items($pdo, $orderId);
    $deductions = products_stock_deductions_for_order_items($pdo, $items);
    products_apply_stock_restorations($pdo, $deductions);

    $upd = $pdo->prepare('UPDATE orders SET stock_applied = 0 WHERE id = ?');
    $upd->execute([$orderId]);
}

function products_unavailable_order_message(): string
{
    return 'این محصول در حال حاضر قابل سفارش نیست';
}
