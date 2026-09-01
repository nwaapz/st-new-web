<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';
require_once dirname(__DIR__) . '/cms/lib/product-categories.php';
require_once dirname(__DIR__) . '/cms/lib/product-series-categories.php';

function product_series_api_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $visualCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'visual_id'")->fetchAll();
    if (count($visualCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');
    }
    $priceCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'price_text'")->fetchAll();
    if (count($priceCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN price_text VARCHAR(128) NULL AFTER description');
    }
    $detailCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'detail_lead_image'")->fetchAll();
    if (count($detailCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN detail_lead_image VARCHAR(512) NULL AFTER image');
    }
    $overrideCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'image_setup_override'")->fetchAll();
    if (count($overrideCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN image_setup_override VARCHAR(512) NULL AFTER detail_lead_image');
    }
    $visualIdx = $pdo->query("SHOW INDEX FROM product_series WHERE Key_name = 'uq_series_visual_id'")->fetchAll();
    if (count($visualIdx) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD UNIQUE KEY uq_series_visual_id (visual_id)');
    }
    cms_series_ensure_categories_schema($pdo);
    $imagesTable = $pdo->query("SHOW TABLES LIKE 'product_series_images'")->fetchAll();
    if (count($imagesTable) === 0) {
        $pdo->exec(
            'CREATE TABLE product_series_images (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              series_id INT UNSIGNED NOT NULL,
              image VARCHAR(512) NOT NULL,
              alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
              sort_order INT NOT NULL DEFAULT 0,
              PRIMARY KEY (id),
              KEY idx_series_images_series (series_id),
              CONSTRAINT fk_series_image_series
                FOREIGN KEY (series_id) REFERENCES product_series (id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    $ready = true;
}

function product_series_api_load_gallery(PDO $pdo, int $seriesId): array
{
    product_series_api_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, image, alt_text, sort_order
         FROM product_series_images
         WHERE series_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$seriesId]);
    $images = [];
    foreach ($stmt->fetchAll() as $row) {
        $image = trim((string) ($row['image'] ?? ''));
        if ($image === '') {
            continue;
        }
        $images[] = [
            'id' => (int) $row['id'],
            'image' => $image,
            'alt_text' => (string) ($row['alt_text'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }
    return $images;
}

function product_series_api_load_members(PDO $pdo, int $seriesId): array
{
    $modelNamesSql = cms_product_model_names_sql('p');
    $categoryNamesSql = cms_product_category_names_sql('p');
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.slug, p.visual_id, p.price_text, p.image,
                p.shop_display_image,
                {$categoryNamesSql} AS category_name,
                {$modelNamesSql} AS model_name
         FROM product_series_items psi
         JOIN products p ON p.id = psi.product_id
         WHERE psi.series_id = ? AND p.published = 1
         ORDER BY psi.sort_order ASC, psi.product_id ASC"
    );
    $stmt->execute([$seriesId]);
    $products = [];
    foreach ($stmt->fetchAll() as $row) {
        $image = trim((string) ($row['shop_display_image'] ?? ''));
        if ($image === '') {
            $image = trim((string) ($row['image'] ?? ''));
        }
        $products[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'visual_id' => $row['visual_id'] !== null && trim((string) $row['visual_id']) !== ''
                ? (string) $row['visual_id']
                : null,
            'price_text' => $row['price_text'] !== null && trim((string) $row['price_text']) !== ''
                ? (string) $row['price_text']
                : null,
            'image' => $image !== '' ? $image : null,
            'category_name' => (string) ($row['category_name'] ?? ''),
            'model_name' => (string) ($row['model_name'] ?? ''),
        ];
    }
    return $products;
}

/** @param list<array{name:string,model_name:string}> $members */
function product_series_api_parts_lines(array $members): array
{
    $lines = [];
    foreach ($members as $member) {
        $name = trim((string) ($member['name'] ?? ''));
        if ($name !== '' && !in_array($name, $lines, true)) {
            $lines[] = $name;
        }
    }
    return $lines;
}

/** @param list<array{model_name:string}> $members */
function product_series_api_car_lines(array $members): array
{
    $models = [];
    foreach ($members as $member) {
        $raw = (string) ($member['model_name'] ?? '');
        foreach (preg_split('/\s*·\s*|\s*•\s*/u', $raw) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '' && !in_array($part, $models, true)) {
                $models[] = $part;
            }
        }
    }
    $rows = [];
    for ($i = 0, $count = count($models); $i < $count; $i += 2) {
        if (isset($models[$i + 1])) {
            $rows[] = $models[$i] . ' - ' . $models[$i + 1];
        } else {
            $rows[] = $models[$i];
        }
    }
    return $rows;
}

function product_series_api_row(array $row, array $productIds = []): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'slug' => (string) $row['slug'],
        'visual_id' => isset($row['visual_id']) && $row['visual_id'] !== null && trim((string) $row['visual_id']) !== ''
            ? (string) $row['visual_id']
            : null,
        'description' => $row['description'] !== null && trim((string) $row['description']) !== ''
            ? (string) $row['description']
            : null,
        'image' => $row['image'] !== null && trim((string) $row['image']) !== ''
            ? (string) $row['image']
            : null,
        'detail_lead_image' => isset($row['detail_lead_image']) && $row['detail_lead_image'] !== null && trim((string) $row['detail_lead_image']) !== ''
            ? (string) $row['detail_lead_image']
            : null,
        'image_setup_override' => isset($row['image_setup_override']) && $row['image_setup_override'] !== null && trim((string) $row['image_setup_override']) !== ''
            ? (string) $row['image_setup_override']
            : null,
        'price_text' => isset($row['price_text']) && $row['price_text'] !== null && trim((string) $row['price_text']) !== ''
            ? (string) $row['price_text']
            : null,
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'product_ids' => $productIds,
    ];
}

function product_series_api_enrich(PDO $pdo, array $item, int $seriesId, bool $includeMembers = false): array
{
    $categoryIds = cms_series_load_category_ids($pdo, $seriesId);
    $categoryNames = [];
    if ($categoryIds !== []) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $catStmt = $pdo->prepare("SELECT id, name FROM categories WHERE id IN ({$placeholders})");
        $catStmt->execute($categoryIds);
        $byId = [];
        foreach ($catStmt->fetchAll() ?: [] as $catRow) {
            $byId[(int) $catRow['id']] = (string) $catRow['name'];
        }
        foreach ($categoryIds as $categoryId) {
            if (isset($byId[$categoryId])) {
                $categoryNames[] = $byId[$categoryId];
            }
        }
    }

    $item['category_ids'] = $categoryIds;
    $item['category_names'] = $categoryNames;
    $item['category_name'] = $categoryNames !== [] ? implode(' · ', $categoryNames) : null;
    $item['images'] = product_series_api_load_gallery($pdo, $seriesId);

    $members = product_series_api_load_members($pdo, $seriesId);
    $item['parts_lines'] = product_series_api_parts_lines($members);
    $item['car_lines'] = product_series_api_car_lines($members);
    if ($includeMembers) {
        $item['products'] = $members;
    }

    return $item;
}

try {
    $pdo = cms_pdo();
    product_series_api_ensure_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    $selectCols = 'id, name, slug, visual_id, description, price_text, image, detail_lead_image, image_setup_override, sort_order';

    if ($slug !== '' || $id > 0) {
        $where = ['published = 1'];
        $params = [];
        if ($slug !== '') {
            $where[] = 'slug = ?';
            $params[] = $slug;
        } else {
            $where[] = 'id = ?';
            $params[] = $id;
        }
        $stmt = $pdo->prepare(
            "SELECT {$selectCols}
             FROM product_series
             WHERE " . implode(' AND ', $where) . '
             LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            api_error('Series not found', 404);
        }
        $seriesId = (int) $row['id'];
        $itemStmt = $pdo->prepare(
            'SELECT product_id FROM product_series_items WHERE series_id = ? ORDER BY sort_order ASC, product_id ASC'
        );
        $itemStmt->execute([$seriesId]);
        $productIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN));
        $item = product_series_api_row($row, $productIds);
        $item = product_series_api_enrich($pdo, $item, $seriesId, true);
        api_json(['item' => $item]);
    }

    $rows = $pdo->query(
        "SELECT {$selectCols}
         FROM product_series
         WHERE published = 1
         ORDER BY sort_order ASC, name ASC"
    )->fetchAll();

    $itemStmt = $pdo->prepare(
        'SELECT product_id
         FROM product_series_items
         WHERE series_id = ?
         ORDER BY sort_order ASC, product_id ASC'
    );

    $items = [];
    foreach ($rows as $row) {
        $seriesId = (int) $row['id'];
        $itemStmt->execute([$seriesId]);
        $productIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN));
        $item = product_series_api_row($row, $productIds);
        $items[] = product_series_api_enrich($pdo, $item, $seriesId, false);
    }

    api_json(['items' => $items]);
} catch (Throwable $e) {
    api_error('Product series unavailable', 503);
}
