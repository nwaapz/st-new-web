<?php
declare(strict_types=1);

/**
 * Product series (kits) can be linked to up to two categories via product_series_categories.
 */

function cms_series_ensure_categories_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $table = $pdo->query("SHOW TABLES LIKE 'product_series_categories'")->fetchAll();
    if (count($table) === 0) {
        $pdo->exec(
            'CREATE TABLE product_series_categories (
              series_id INT UNSIGNED NOT NULL,
              category_id INT UNSIGNED NOT NULL,
              sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (series_id, category_id),
              KEY idx_scat_category (category_id),
              CONSTRAINT fk_scat_series FOREIGN KEY (series_id) REFERENCES product_series (id) ON DELETE CASCADE,
              CONSTRAINT fk_scat_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    $ready = true;
}

function cms_series_category_names_sql(string $seriesAlias = 's'): string
{
    return '(SELECT GROUP_CONCAT(c2.name ORDER BY sc.sort_order ASC, c2.sort_order ASC, c2.name ASC SEPARATOR \' · \')
             FROM product_series_categories sc
             JOIN categories c2 ON c2.id = sc.category_id
             WHERE sc.series_id = ' . $seriesAlias . '.id)';
}

function cms_series_category_filter_sql(string $seriesAlias = 's'): string
{
    return cms_series_category_in_filter_sql($seriesAlias, 1);
}

function cms_series_category_in_filter_sql(string $seriesAlias, int $count): string
{
    if ($count <= 0) {
        return '1=1';
    }
    $placeholders = implode(',', array_fill(0, $count, '?'));

    return 'EXISTS (
        SELECT 1 FROM product_series_categories sc_filter
        WHERE sc_filter.series_id = ' . $seriesAlias . '.id
          AND sc_filter.category_id IN (' . $placeholders . ')
    )';
}

/**
 * One-time helper: assign primary member-product category to series with no categories.
 */
function cms_series_backfill_categories_from_members(PDO $pdo): int
{
    cms_series_ensure_categories_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    $seriesRows = $pdo->query(
        'SELECT s.id
         FROM product_series s
         WHERE NOT EXISTS (
             SELECT 1 FROM product_series_categories sc WHERE sc.series_id = s.id
         )'
    )->fetchAll() ?: [];

    if ($seriesRows === []) {
        return 0;
    }

    $categoryStmt = $pdo->prepare(
        'SELECT pc.category_id
         FROM product_series_items psi
         JOIN product_categories pc ON pc.product_id = psi.product_id
         WHERE psi.series_id = ?
         ORDER BY psi.sort_order ASC, psi.product_id ASC, pc.sort_order ASC, pc.category_id ASC
         LIMIT 1'
    );
    $insertStmt = $pdo->prepare(
        'INSERT IGNORE INTO product_series_categories (series_id, category_id, sort_order) VALUES (?, ?, 0)'
    );

    $count = 0;
    foreach ($seriesRows as $row) {
        $seriesId = (int) ($row['id'] ?? 0);
        if ($seriesId <= 0) {
            continue;
        }
        $categoryStmt->execute([$seriesId]);
        $categoryId = (int) $categoryStmt->fetchColumn();
        if ($categoryId <= 0) {
            continue;
        }
        $insertStmt->execute([$seriesId, $categoryId]);
        if ($insertStmt->rowCount() > 0) {
            $count++;
        }
    }

    return $count;
}

/** @return list<int> */
function cms_series_load_category_ids(PDO $pdo, int $seriesId): array
{
    cms_series_ensure_categories_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT category_id FROM product_series_categories
         WHERE series_id = ?
         ORDER BY sort_order ASC, category_id ASC'
    );
    $stmt->execute([$seriesId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** @param list<int|string> $categoryIds */
function cms_series_save_category_ids(PDO $pdo, int $seriesId, array $categoryIds): void
{
    cms_series_ensure_categories_schema($pdo);
    $unique = [];
    foreach ($categoryIds as $categoryId) {
        $categoryId = (int) $categoryId;
        if ($categoryId > 0 && !in_array($categoryId, $unique, true)) {
            $unique[] = $categoryId;
        }
    }
    if ($unique === []) {
        throw new RuntimeException('حداقل یک دسته برای کیت الزامی است');
    }
    if (count($unique) > 2) {
        throw new RuntimeException('هر کیت حداکثر دو دسته می‌تواند داشته باشد');
    }

    $pdo->prepare('DELETE FROM product_series_categories WHERE series_id = ?')->execute([$seriesId]);
    $stmt = $pdo->prepare(
        'INSERT INTO product_series_categories (series_id, category_id, sort_order) VALUES (?, ?, ?)'
    );
    foreach ($unique as $sortOrder => $categoryId) {
        $stmt->execute([$seriesId, $categoryId, $sortOrder]);
    }
}

/** @return array<int, list<int>> series_id => category_ids */
function cms_series_load_category_ids_map(PDO $pdo, array $seriesIds): array
{
    cms_series_ensure_categories_schema($pdo);
    $seriesIds = array_values(array_unique(array_filter(array_map('intval', $seriesIds), static fn (int $id): bool => $id > 0)));
    if ($seriesIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT series_id, category_id
         FROM product_series_categories
         WHERE series_id IN ({$placeholders})
         ORDER BY series_id ASC, sort_order ASC, category_id ASC"
    );
    $stmt->execute($seriesIds);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $sid = (int) $row['series_id'];
        if (!isset($map[$sid])) {
            $map[$sid] = [];
        }
        $map[$sid][] = (int) $row['category_id'];
    }
    return $map;
}
