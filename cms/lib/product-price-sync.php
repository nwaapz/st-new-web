<?php
declare(strict_types=1);

const PRODUCT_PRICE_SYNC_TZ = 'Asia/Tehran';
const PRODUCT_PRICE_SYNC_WINDOW_START_HOUR = 8;
const PRODUCT_PRICE_SYNC_WINDOW_END_HOUR = 20;

function product_price_sync_ensure_schema(PDO $pdo): void
{
    $productCols = $pdo->query("SHOW COLUMNS FROM products LIKE 'price_updated_at'")->fetchAll();
    if (count($productCols) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN price_updated_at DATETIME NULL AFTER price_text');
    }

    $seriesCols = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'price_updated_at'")->fetchAll();
    if (count($seriesCols) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN price_updated_at DATETIME NULL AFTER price_text');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS client_price_sync_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sales_user_id INT UNSIGNED NOT NULL,
            sync_date DATE NOT NULL,
            synced_at DATETIME NOT NULL,
            product_count INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_sales_user_sync_date (sales_user_id, sync_date),
            KEY idx_sync_date (sync_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function product_price_sync_now_tehran(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(PRODUCT_PRICE_SYNC_TZ));
}

function product_price_sync_today_date(): string
{
    return product_price_sync_now_tehran()->format('Y-m-d');
}

function product_price_sync_within_window(?DateTimeImmutable $now = null): bool
{
    $now = $now ?? product_price_sync_now_tehran();
    $hour = (int) $now->format('G');
    return $hour >= PRODUCT_PRICE_SYNC_WINDOW_START_HOUR && $hour < PRODUCT_PRICE_SYNC_WINDOW_END_HOUR;
}

function product_price_sync_mark_product(PDO $pdo, int $productId): void
{
    if ($productId <= 0) {
        return;
    }
    product_price_sync_ensure_schema($pdo);
    $stmt = $pdo->prepare('UPDATE products SET price_updated_at = NOW() WHERE id = ?');
    $stmt->execute([$productId]);
}

function product_price_sync_mark_series(PDO $pdo, int $seriesId): void
{
    if ($seriesId <= 0) {
        return;
    }
    product_price_sync_ensure_schema($pdo);
    $stmt = $pdo->prepare('UPDATE product_series SET price_updated_at = NOW() WHERE id = ?');
    $stmt->execute([$seriesId]);
}

function product_price_sync_user_synced_today(PDO $pdo, int $salesUserId): bool
{
    product_price_sync_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT 1 FROM client_price_sync_log WHERE sales_user_id = ? AND sync_date = ? LIMIT 1'
    );
    $stmt->execute([$salesUserId, product_price_sync_today_date()]);
    return (bool) $stmt->fetchColumn();
}

/**
 * @return array{synced_at:string,product_count:int}|null
 */
function product_price_sync_log_user_sync(PDO $pdo, int $salesUserId, int $productCount): array
{
    product_price_sync_ensure_schema($pdo);
    $now = product_price_sync_now_tehran();
    $syncDate = $now->format('Y-m-d');
    $syncedAt = $now->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO client_price_sync_log (sales_user_id, sync_date, synced_at, product_count)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE synced_at = VALUES(synced_at), product_count = VALUES(product_count)'
    );
    $stmt->execute([$salesUserId, $syncDate, $syncedAt, max(0, $productCount)]);

    return [
        'synced_at' => $syncedAt,
        'product_count' => max(0, $productCount),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function product_price_sync_fetch_delta(PDO $pdo, ?string $since): array
{
    product_price_sync_ensure_schema($pdo);
    require_once __DIR__ . '/product-categories.php';
    require_once __DIR__ . '/product-car-models.php';

    $modelNamesSql = cms_product_model_names_sql('p');
    $categoryNamesSql = cms_product_category_names_sql('p');
    $primaryCategoryJoinSql = cms_product_primary_category_join_sql('p');

    $where = ['p.published = 1'];
    $params = [];

    if ($since !== null && $since !== '') {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $since, new DateTimeZone(PRODUCT_PRICE_SYNC_TZ));
        if ($parsed === false) {
            $parsed = date_create_immutable($since, new DateTimeZone(PRODUCT_PRICE_SYNC_TZ));
        }
        if ($parsed instanceof DateTimeImmutable) {
            $where[] = '(p.price_updated_at IS NOT NULL AND p.price_updated_at > ?)';
            $params[] = $parsed->format('Y-m-d H:i:s');
        } else {
            $fallback = product_price_sync_now_tehran()->modify('-30 days')->format('Y-m-d H:i:s');
            $where[] = '(p.price_updated_at IS NOT NULL AND p.price_updated_at > ?)';
            $params[] = $fallback;
        }
    } else {
        $fallback = product_price_sync_now_tehran()->modify('-30 days')->format('Y-m-d H:i:s');
        $where[] = '(p.price_updated_at IS NOT NULL AND p.price_updated_at > ?)';
        $params[] = $fallback;
    }

    $sql = 'SELECT p.id, p.visual_id, p.price_text, p.pack_size, p.price_updated_at,
                   ' . $categoryNamesSql . ' AS category_name,
                   ' . $modelNamesSql . ' AS model_name
            FROM products p
            ' . $primaryCategoryJoinSql . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.price_updated_at DESC, p.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $callForPrice = cms_call_for_price_enabled();
    $callLabel = cms_call_for_price_label();

    $items = [];
    foreach ($rows as $row) {
        $priceText = trim((string) ($row['price_text'] ?? ''));
        if ($callForPrice) {
            $priceText = $callLabel;
        }
        $updatedAt = $row['price_updated_at'] ?? null;
        $items[] = [
            'id' => (int) $row['id'],
            'visual_id' => trim((string) ($row['visual_id'] ?? '')),
            'category_name' => trim((string) ($row['category_name'] ?? '')),
            'model_name' => trim((string) ($row['model_name'] ?? '')),
            'price_text' => $priceText,
            'pack_size' => isset($row['pack_size']) && (int) $row['pack_size'] > 0
                ? (int) $row['pack_size']
                : null,
            'price_updated_at' => $updatedAt !== null ? (string) $updatedAt : null,
        ];
    }

    return $items;
}

/**
 * @param list<array<string, mixed>> $updates
 * @return list<int>
 */
function product_price_sync_apply_admin_delta(PDO $pdo, array $updates): array
{
    product_price_sync_ensure_schema($pdo);
    $updatedIds = [];

    foreach ($updates as $update) {
        if (!is_array($update)) {
            continue;
        }
        $productId = (int) ($update['product_id'] ?? $update['id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        $priceText = trim((string) ($update['price_text'] ?? ''));
        $packSize = isset($update['pack_size']) ? (int) $update['pack_size'] : null;

        $setParts = ['price_updated_at = NOW()'];
        $values = [];
        if ($priceText !== '') {
            $setParts[] = 'price_text = ?';
            $values[] = $priceText;
        }
        if ($packSize !== null && $packSize > 0) {
            $setParts[] = 'pack_size = ?';
            $values[] = $packSize;
        }
        if (count($setParts) <= 1) {
            continue;
        }
        $values[] = $productId;
        $stmt = $pdo->prepare('UPDATE products SET ' . implode(', ', $setParts) . ' WHERE id = ?');
        $stmt->execute($values);
        if ($stmt->rowCount() > 0) {
            $updatedIds[] = $productId;
        } else {
            $check = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
            $check->execute([$productId]);
            if ($check->fetchColumn()) {
                product_price_sync_mark_product($pdo, $productId);
                $updatedIds[] = $productId;
            }
        }
    }

    return array_values(array_unique($updatedIds));
}
