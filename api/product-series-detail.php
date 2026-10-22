<?php
declare(strict_types=1);

/**
 * Single kit (product series) for shop detail — minimal deps, no shop-search stack.
 */
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/product-stock.php';
require_once dirname(__DIR__) . '/cms/lib/product-series-categories.php';
require_once dirname(__DIR__) . '/cms/lib/product-categories.php';
require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';

function product_series_detail_normalize_banner(mixed $value): string
{
    $banner = strtolower(trim((string) $value));
    return in_array($banner, ['new', 'off'], true) ? $banner : 'none';
}

function product_series_detail_row(array $row, array $productIds = []): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'slug' => (string) $row['slug'],
        'visual_id' => isset($row['visual_id']) && trim((string) $row['visual_id']) !== ''
            ? (string) $row['visual_id']
            : null,
        'description' => $row['description'] !== null && trim((string) $row['description']) !== ''
            ? (string) $row['description']
            : null,
        'image' => $row['image'] !== null && trim((string) $row['image']) !== ''
            ? (string) $row['image']
            : null,
        'detail_lead_image' => isset($row['detail_lead_image']) && trim((string) $row['detail_lead_image']) !== ''
            ? (string) $row['detail_lead_image']
            : null,
        'image_setup_override' => isset($row['image_setup_override']) && trim((string) $row['image_setup_override']) !== ''
            ? (string) $row['image_setup_override']
            : null,
        'price_text' => isset($row['price_text']) && trim((string) $row['price_text']) !== ''
            ? (string) $row['price_text']
            : null,
        'pack_size' => isset($row['pack_size']) && (int) $row['pack_size'] > 0
            ? (int) $row['pack_size']
            : null,
        'banner' => product_series_detail_normalize_banner($row['banner'] ?? 'none'),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'product_ids' => $productIds,
    ];
}

function product_series_detail_load_gallery(PDO $pdo, int $seriesId): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT id, image, alt_text, sort_order
             FROM product_series_images
             WHERE series_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$seriesId]);
        $images = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
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
    } catch (Throwable $e) {
        return [];
    }
}

function product_series_detail_load_members(PDO $pdo, int $seriesId): array
{
    try {
        cms_ensure_product_car_models_schema($pdo);
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
        foreach ($stmt->fetchAll() ?: [] as $row) {
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
    } catch (Throwable $e) {
        error_log('[product-series-detail] members: ' . $e->getMessage());
        return [];
    }
}

/** @param list<array{name:string,model_name:string}> $members */
function product_series_detail_parts_lines(array $members): array
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
function product_series_detail_car_lines(array $members): array
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

function product_series_detail_enrich(PDO $pdo, array $item, int $seriesId): array
{
    cms_series_ensure_categories_schema($pdo);
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

    $members = product_series_detail_load_members($pdo, $seriesId);
    $factoryIds = [];
    $factoryNames = [];
    try {
        cms_ensure_product_car_models_schema($pdo);
        $factoryStmt = $pdo->prepare(
            'SELECT DISTINCT f.id, f.name
             FROM product_series_car_models pscm
             JOIN car_model_factories cmf ON cmf.car_model_id = pscm.car_model_id
             JOIN factories f ON f.id = cmf.factory_id
             WHERE pscm.series_id = ?
             ORDER BY f.sort_order ASC, f.name ASC'
        );
        $factoryStmt->execute([$seriesId]);
        foreach ($factoryStmt->fetchAll() ?: [] as $factoryRow) {
            $factoryIds[] = (int) $factoryRow['id'];
            $factoryNames[] = (string) $factoryRow['name'];
        }
    } catch (Throwable $e) {
        error_log('[product-series-detail] factories: ' . $e->getMessage());
    }

    $item['category_ids'] = $categoryIds;
    $item['category_names'] = $categoryNames;
    $item['category_name'] = $categoryNames !== [] ? implode(' · ', $categoryNames) : null;
    $item['images'] = product_series_detail_load_gallery($pdo, $seriesId);
    $item['parts_lines'] = product_series_detail_parts_lines($members);
    $item['car_lines'] = product_series_detail_car_lines($members);
    $item['factory_ids'] = $factoryIds;
    $item['factory_name'] = $factoryNames !== [] ? implode(' · ', $factoryNames) : null;
    $item['products'] = $members;

    return $item;
}

try {
    $pdo = cms_pdo();
    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($slug === '' && $id <= 0) {
        api_error('slug or id required', 400);
    }

    $selectCols = 'id, name, slug, visual_id, description, price_text, pack_size, banner, image, detail_lead_image, image_setup_override, sort_order';
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
    $productIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $item = product_series_detail_row($row, $productIds);
    $item = product_series_detail_enrich($pdo, $item, $seriesId);

    $callForPrice = cms_call_for_price_enabled();
    if ($callForPrice) {
        $label = cms_call_for_price_label();
        if (isset($item['price_text'])) {
            $item['price_text'] = $label;
        }
        if (isset($item['products']) && is_array($item['products'])) {
            foreach ($item['products'] as &$product) {
                if (isset($product['price_text'])) {
                    $product['price_text'] = $label;
                }
            }
            unset($product);
        }
    }

    $orderableMap = products_series_orderable_map($pdo, [$seriesId]);
    $item['orderable'] = $orderableMap[$seriesId] ?? false;

    api_json(['item' => $item, 'call_for_price' => $callForPrice]);
} catch (Throwable $e) {
    error_log('[product-series-detail] ' . $e->getMessage());
    api_error('Product series unavailable', 503);
}
