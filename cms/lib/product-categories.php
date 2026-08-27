<?php
declare(strict_types=1);

/**
 * Products can be linked to up to two categories via product_categories.
 */

function cms_product_category_names_sql(string $productAlias = 'p'): string
{
    return '(SELECT GROUP_CONCAT(c2.name ORDER BY pc.sort_order ASC, c2.sort_order ASC, c2.name ASC SEPARATOR \' · \')
             FROM product_categories pc
             JOIN categories c2 ON c2.id = pc.category_id
             WHERE pc.product_id = ' . $productAlias . '.id)';
}

function cms_product_primary_category_id_sql(string $productAlias = 'p'): string
{
    return '(SELECT pc.category_id
             FROM product_categories pc
             WHERE pc.product_id = ' . $productAlias . '.id
             ORDER BY pc.sort_order ASC, pc.category_id ASC
             LIMIT 1)';
}

function cms_product_primary_category_join_sql(string $productAlias = 'p', string $categoryAlias = 'c'): string
{
    return 'LEFT JOIN categories ' . $categoryAlias
        . ' ON ' . $categoryAlias . '.id = ' . cms_product_primary_category_id_sql($productAlias);
}

function cms_product_category_filter_sql(string $productAlias = 'p'): string
{
    return 'EXISTS (
        SELECT 1 FROM product_categories pc_filter
        WHERE pc_filter.product_id = ' . $productAlias . '.id
          AND pc_filter.category_id = ?
    )';
}

function cms_product_category_in_filter_sql(string $productAlias, int $count): string
{
    if ($count <= 0) {
        return '0=1';
    }
    $placeholders = implode(',', array_fill(0, $count, '?'));
    return 'EXISTS (
        SELECT 1 FROM product_categories pc_in
        WHERE pc_in.product_id = ' . $productAlias . '.id
          AND pc_in.category_id IN (' . $placeholders . ')
    )';
}

/**
 * Category-only filter honoring per-car overrides: matches when the product has
 * a product-level category in the set, or any of its car assignments overrides
 * to a category in the set. Binds $count params twice (product-level, then overrides).
 */
function cms_product_effective_category_in_filter_sql(string $productAlias, int $count): string
{
    if ($count <= 0) {
        return '0=1';
    }
    $placeholders = implode(',', array_fill(0, $count, '?'));
    return '(EXISTS (
        SELECT 1 FROM product_categories pc_eff
        WHERE pc_eff.product_id = ' . $productAlias . '.id
          AND pc_eff.category_id IN (' . $placeholders . ')
    ) OR EXISTS (
        SELECT 1 FROM product_car_models pcm_eff
        WHERE pcm_eff.product_id = ' . $productAlias . '.id
          AND pcm_eff.category_id IN (' . $placeholders . ')
    ))';
}

/**
 * Exact (car, category) pair filter: the car must be assigned to the product and
 * its effective category (override, else product categories) must be in the set.
 * Binds 1 + 2*$count params: car_model_id, override IN(...), product-level IN(...).
 */
function cms_product_car_category_pair_filter_sql(string $productAlias, int $count): string
{
    if ($count <= 0) {
        return '0=1';
    }
    $placeholders = implode(',', array_fill(0, $count, '?'));
    return 'EXISTS (
        SELECT 1 FROM product_car_models pcm_pair
        WHERE pcm_pair.product_id = ' . $productAlias . '.id
          AND pcm_pair.car_model_id = ?
          AND (pcm_pair.category_id IN (' . $placeholders . ')
               OR (pcm_pair.category_id IS NULL
                   AND EXISTS (
                       SELECT 1 FROM product_categories pc_pair
                       WHERE pc_pair.product_id = ' . $productAlias . '.id
                         AND pc_pair.category_id IN (' . $placeholders . ')
                   )))
    )';
}

function cms_product_related_category_filter_sql(string $productAlias, int $sourceProductId): string
{
    return 'EXISTS (
        SELECT 1 FROM product_categories pc_rel
        WHERE pc_rel.product_id = ' . $productAlias . '.id
          AND pc_rel.category_id IN (
              SELECT category_id FROM product_categories WHERE product_id = ' . (int) $sourceProductId . '
          )
    )';
}

function cms_product_any_category_name_search_sql(string $productAlias, string $nameSql): string
{
    return 'EXISTS (
        SELECT 1 FROM product_categories pc_s
        JOIN categories c_s ON c_s.id = pc_s.category_id
        WHERE pc_s.product_id = ' . $productAlias . '.id
          AND ' . $nameSql . '
    )';
}

/** @return list<int> */
function cms_product_load_category_ids(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare(
        'SELECT category_id FROM product_categories
         WHERE product_id = ?
         ORDER BY sort_order ASC, category_id ASC'
    );
    $stmt->execute([$productId]);
    $ids = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $ids[] = (int) $row['category_id'];
    }
    return $ids;
}

/** @param list<int|string> $categoryIds */
function cms_product_save_category_ids(PDO $pdo, int $productId, array $categoryIds): void
{
    $unique = [];
    foreach ($categoryIds as $categoryId) {
        $categoryId = (int) $categoryId;
        if ($categoryId > 0 && !in_array($categoryId, $unique, true)) {
            $unique[] = $categoryId;
        }
    }
    if ($unique === []) {
        throw new RuntimeException('حداقل یک دسته محصول الزامی است');
    }
    if (count($unique) > 2) {
        throw new RuntimeException('هر محصول حداکثر دو دسته می‌تواند داشته باشد');
    }

    $pdo->prepare('DELETE FROM product_categories WHERE product_id = ?')->execute([$productId]);
    $stmt = $pdo->prepare(
        'INSERT INTO product_categories (product_id, category_id, sort_order) VALUES (?, ?, ?)'
    );
    foreach ($unique as $sortOrder => $categoryId) {
        $stmt->execute([$productId, $categoryId, $sortOrder]);
    }
}

/** @return array<int, list<int>> product_id => category_ids */
function cms_product_load_category_ids_map(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
    if ($productIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT product_id, category_id
         FROM product_categories
         WHERE product_id IN ({$placeholders})
         ORDER BY product_id ASC, sort_order ASC, category_id ASC"
    );
    $stmt->execute($productIds);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $pid = (int) $row['product_id'];
        if (!isset($map[$pid])) {
            $map[$pid] = [];
        }
        $map[$pid][] = (int) $row['category_id'];
    }
    return $map;
}

function cms_ensure_product_categories_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_categories (
          product_id INT UNSIGNED NOT NULL,
          category_id INT UNSIGNED NOT NULL,
          sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (product_id, category_id),
          KEY idx_pcat_category (category_id),
          CONSTRAINT fk_pcat_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
          CONSTRAINT fk_pcat_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $legacyCol = $pdo->query('SHOW COLUMNS FROM products LIKE \'category_id\'')->fetchAll();
    if (count($legacyCol) > 0) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec(
            'INSERT IGNORE INTO product_categories (product_id, category_id, sort_order)
             SELECT id, category_id, 0 FROM products WHERE category_id IS NOT NULL AND category_id > 0'
        );
        try {
            $pdo->exec('ALTER TABLE products DROP FOREIGN KEY fk_prod_cat');
        } catch (Throwable $e) {
            /* already dropped */
        }
        try {
            $pdo->exec('ALTER TABLE products DROP INDEX idx_prod_cat');
        } catch (Throwable $e) {
            /* */
        }
        $pdo->exec('ALTER TABLE products DROP COLUMN category_id');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    $ready = true;
}
