<?php
declare(strict_types=1);

/**
 * Products can be linked to multiple car models via product_car_models.
 */

function cms_product_model_names_sql(string $productAlias = 'p'): string
{
    return '(SELECT GROUP_CONCAT(m2.name ORDER BY pcm.sort_order ASC, m2.sort_order ASC, m2.name ASC SEPARATOR \' · \')
             FROM product_car_models pcm
             JOIN car_models m2 ON m2.id = pcm.car_model_id
             WHERE pcm.product_id = ' . $productAlias . '.id)';
}

function cms_product_factory_names_sql(string $productAlias = 'p'): string
{
    return '(SELECT GROUP_CONCAT(DISTINCT f2.name ORDER BY f2.sort_order ASC, f2.name ASC SEPARATOR \' · \')
             FROM product_car_models pcm_f
             JOIN car_model_factories cmf ON cmf.car_model_id = pcm_f.car_model_id
             JOIN factories f2 ON f2.id = cmf.factory_id
             WHERE pcm_f.product_id = ' . $productAlias . '.id)';
}

function cms_product_primary_car_model_id_sql(string $productAlias = 'p'): string
{
    return '(SELECT pcm.car_model_id
             FROM product_car_models pcm
             WHERE pcm.product_id = ' . $productAlias . '.id
             ORDER BY pcm.sort_order ASC, pcm.car_model_id ASC
             LIMIT 1)';
}

function cms_product_primary_factory_id_sql(string $productAlias = 'p'): string
{
    $primaryModelSql = cms_product_primary_car_model_id_sql($productAlias);
    return '(SELECT cmf.factory_id
             FROM car_model_factories cmf
             WHERE cmf.car_model_id = ' . $primaryModelSql . '
             ORDER BY cmf.sort_order ASC, cmf.factory_id ASC
             LIMIT 1)';
}

function cms_product_car_model_filter_sql(string $productAlias = 'p'): string
{
    return 'EXISTS (
        SELECT 1 FROM product_car_models pcm_filter
        WHERE pcm_filter.product_id = ' . $productAlias . '.id
          AND pcm_filter.car_model_id = ?
    )';
}

function cms_product_factory_filter_sql(string $productAlias = 'p'): string
{
    return 'EXISTS (
        SELECT 1 FROM product_car_models pcm_ff
        JOIN car_model_factories cmf_ff ON cmf_ff.car_model_id = pcm_ff.car_model_id
        WHERE pcm_ff.product_id = ' . $productAlias . '.id
          AND cmf_ff.factory_id = ?
    )';
}

function cms_product_related_car_model_filter_sql(string $productAlias = 'p', int $sourceProductId): string
{
    return 'EXISTS (
        SELECT 1 FROM product_car_models pcm_rel
        WHERE pcm_rel.product_id = ' . $productAlias . '.id
          AND pcm_rel.car_model_id IN (
              SELECT car_model_id FROM product_car_models WHERE product_id = ' . (int) $sourceProductId . '
          )
    )';
}

/** @return list<int> */
function cms_product_load_car_model_ids(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare(
        'SELECT car_model_id FROM product_car_models
         WHERE product_id = ?
         ORDER BY sort_order ASC, car_model_id ASC'
    );
    $stmt->execute([$productId]);
    $ids = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $ids[] = (int) $row['car_model_id'];
    }
    return $ids;
}

/** @return array<int, int|null> car_model_id => category override id (null = product categories) */
function cms_product_load_car_model_categories(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare(
        'SELECT car_model_id, category_id FROM product_car_models
         WHERE product_id = ?
         ORDER BY sort_order ASC, car_model_id ASC'
    );
    $stmt->execute([$productId]);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $map[(int) $row['car_model_id']] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
    }
    return $map;
}

/**
 * @param list<int|string> $carModelIds
 * @param array<int|string, int|string> $categoryByModel car_model_id => category override id (0/'' = none)
 */
function cms_product_save_car_model_ids(PDO $pdo, int $productId, array $carModelIds, array $categoryByModel = []): void
{
    $unique = [];
    foreach ($carModelIds as $carModelId) {
        $carModelId = (int) $carModelId;
        if ($carModelId > 0 && !in_array($carModelId, $unique, true)) {
            $unique[] = $carModelId;
        }
    }
    if ($unique === []) {
        throw new RuntimeException('حداقل یک مدل خودرو الزامی است');
    }

    $pdo->prepare('DELETE FROM product_car_models WHERE product_id = ?')->execute([$productId]);
    $stmt = $pdo->prepare(
        'INSERT INTO product_car_models (product_id, car_model_id, category_id, sort_order) VALUES (?, ?, ?, ?)'
    );
    foreach ($unique as $sortOrder => $carModelId) {
        $categoryId = (int) ($categoryByModel[$carModelId] ?? 0);
        $stmt->execute([$productId, $carModelId, $categoryId > 0 ? $categoryId : null, $sortOrder]);
    }
}

/** @return array<int, list<int>> product_id => car_model_ids */
function cms_product_load_car_model_ids_map(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
    if ($productIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT product_id, car_model_id
         FROM product_car_models
         WHERE product_id IN ({$placeholders})
         ORDER BY product_id ASC, sort_order ASC, car_model_id ASC"
    );
    $stmt->execute($productIds);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $pid = (int) $row['product_id'];
        if (!isset($map[$pid])) {
            $map[$pid] = [];
        }
        $map[$pid][] = (int) $row['car_model_id'];
    }
    return $map;
}

/** @return array<int, list<array{car_model_id: int, category_id: int}>> product_id => per-car category overrides */
function cms_product_load_car_model_categories_map(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
    if ($productIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT product_id, car_model_id, category_id
         FROM product_car_models
         WHERE product_id IN ({$placeholders}) AND category_id IS NOT NULL
         ORDER BY product_id ASC, sort_order ASC, car_model_id ASC"
    );
    $stmt->execute($productIds);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $pid = (int) $row['product_id'];
        if (!isset($map[$pid])) {
            $map[$pid] = [];
        }
        $map[$pid][] = [
            'car_model_id' => (int) $row['car_model_id'],
            'category_id' => (int) $row['category_id'],
        ];
    }
    return $map;
}

/**
 * Car models with effective category for product cards (override or primary product category).
 *
 * @return array<int, list<array{car_model_id: int, car_name: string, category_id: int, category_name: string}>>
 */
function cms_product_load_car_model_entries_map(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
    if ($productIds === []) {
        return [];
    }

    $categoryMap = cms_product_load_category_ids_map($pdo, $productIds);

    $categoryIdsNeeded = [];
    foreach ($productIds as $productId) {
        foreach ($categoryMap[$productId] ?? [] as $categoryId) {
            if ($categoryId > 0) {
                $categoryIdsNeeded[$categoryId] = true;
            }
        }
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT pcm.product_id, pcm.car_model_id, pcm.category_id AS override_category_id, m.name AS car_name
         FROM product_car_models pcm
         JOIN car_models m ON m.id = pcm.car_model_id
         WHERE pcm.product_id IN ({$placeholders})
         ORDER BY pcm.product_id ASC, pcm.sort_order ASC, pcm.car_model_id ASC"
    );
    $stmt->execute($productIds);

    $rowsByProduct = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $pid = (int) $row['product_id'];
        $overrideCategoryId = $row['override_category_id'] !== null ? (int) $row['override_category_id'] : 0;
        $productCategoryIds = $categoryMap[$pid] ?? [];
        $carIndex = isset($rowsByProduct[$pid]) ? count($rowsByProduct[$pid]) : 0;
        $effectiveCategoryId = $overrideCategoryId > 0
            ? $overrideCategoryId
            : (int) ($productCategoryIds[$carIndex] ?? $productCategoryIds[0] ?? 0);

        if ($effectiveCategoryId > 0) {
            $categoryIdsNeeded[$effectiveCategoryId] = true;
        }

        if (!isset($rowsByProduct[$pid])) {
            $rowsByProduct[$pid] = [];
        }
        $rowsByProduct[$pid][] = [
            'car_model_id' => (int) $row['car_model_id'],
            'car_name' => (string) $row['car_name'],
            'category_id' => $effectiveCategoryId,
        ];
    }

    $categoryNames = [];
    if ($categoryIdsNeeded !== []) {
        $catIds = array_keys($categoryIdsNeeded);
        $catPlaceholders = implode(',', array_fill(0, count($catIds), '?'));
        $catStmt = $pdo->prepare("SELECT id, name FROM categories WHERE id IN ({$catPlaceholders})");
        $catStmt->execute($catIds);
        foreach ($catStmt->fetchAll() ?: [] as $catRow) {
            $categoryNames[(int) $catRow['id']] = (string) $catRow['name'];
        }
    }

    $map = [];
    foreach ($rowsByProduct as $pid => $entries) {
        $map[$pid] = [];
        foreach ($entries as $entry) {
            $categoryId = (int) $entry['category_id'];
            $map[$pid][] = [
                'car_model_id' => (int) $entry['car_model_id'],
                'car_name' => (string) $entry['car_name'],
                'category_id' => $categoryId,
                'category_name' => $categoryNames[$categoryId] ?? '',
            ];
        }
    }

    return $map;
}

function cms_ensure_product_car_models_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_car_models (
          product_id INT UNSIGNED NOT NULL,
          car_model_id INT UNSIGNED NOT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (product_id, car_model_id),
          KEY idx_pcm_model (car_model_id),
          CONSTRAINT fk_pcm_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
          CONSTRAINT fk_pcm_model FOREIGN KEY (car_model_id) REFERENCES car_models (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $legacyCol = $pdo->query('SHOW COLUMNS FROM products LIKE \'car_model_id\'')->fetchAll();
    if (count($legacyCol) > 0) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec(
            'INSERT IGNORE INTO product_car_models (product_id, car_model_id, sort_order)
             SELECT id, car_model_id, 0 FROM products WHERE car_model_id IS NOT NULL AND car_model_id > 0'
        );
        try {
            $pdo->exec('ALTER TABLE products DROP FOREIGN KEY fk_prod_model');
        } catch (Throwable $e) {
            /* already dropped */
        }
        try {
            $pdo->exec('ALTER TABLE products DROP INDEX idx_prod_model');
        } catch (Throwable $e) {
            /* */
        }
        $pdo->exec('ALTER TABLE products DROP COLUMN car_model_id');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // Optional per-car category override (falls back to product_categories when NULL).
    $categoryCol = $pdo->query('SHOW COLUMNS FROM product_car_models LIKE \'category_id\'')->fetchAll();
    if (count($categoryCol) === 0) {
        $pdo->exec('ALTER TABLE product_car_models ADD COLUMN category_id INT UNSIGNED NULL AFTER car_model_id');
        try {
            $pdo->exec(
                'ALTER TABLE product_car_models ADD CONSTRAINT fk_pcm_category
                 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL'
            );
        } catch (Throwable $e) {
            /* exists */
        }
    }

    $ready = true;
}
