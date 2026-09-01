<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';
require_once dirname(__DIR__) . '/cms/lib/product-categories.php';

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
    $visualIdx = $pdo->query("SHOW INDEX FROM product_series WHERE Key_name = 'uq_series_visual_id'")->fetchAll();
    if (count($visualIdx) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD UNIQUE KEY uq_series_visual_id (visual_id)');
    }
    $ready = true;
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
        'price_text' => isset($row['price_text']) && $row['price_text'] !== null && trim((string) $row['price_text']) !== ''
            ? (string) $row['price_text']
            : null,
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'product_ids' => $productIds,
    ];
}

try {
    $pdo = cms_pdo();
    product_series_api_ensure_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

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
            'SELECT id, name, slug, visual_id, description, price_text, image, sort_order
             FROM product_series
             WHERE ' . implode(' AND ', $where) . '
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
        $item['products'] = product_series_api_load_members($pdo, $seriesId);
        api_json(['item' => $item]);
    }

    $rows = $pdo->query(
        'SELECT id, name, slug, visual_id, description, price_text, image, sort_order
         FROM product_series
         WHERE published = 1
         ORDER BY sort_order ASC, name ASC'
    )->fetchAll();

    $itemStmt = $pdo->prepare(
        'SELECT product_id
         FROM product_series_items
         WHERE series_id = ?
         ORDER BY sort_order ASC, product_id ASC'
    );

    $items = [];
    foreach ($rows as $row) {
        $itemStmt->execute([(int) $row['id']]);
        $productIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN));
        $items[] = product_series_api_row($row, $productIds);
    }

    api_json(['items' => $items]);
} catch (Throwable $e) {
    api_error('Product series unavailable', 503);
}
