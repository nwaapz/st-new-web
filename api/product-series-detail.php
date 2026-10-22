<?php
declare(strict_types=1);

/**
 * Single kit for shop detail. Same bootstrap as product-series-catalog.php.
 * v3 — no extra CMS libs, no PHP 8-only types, errors included in JSON.
 */
require_once __DIR__ . '/_common.php';

if (isset($_GET['ping'])) {
    api_json(['ok' => true, 'endpoint' => 'product-series-detail', 'v' => 3]);
}

$stockLib = dirname(__DIR__) . '/cms/lib/product-stock.php';
if (is_file($stockLib)) {
    require_once $stockLib;
}

try {
    $pdo = cms_pdo();

    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
    if ($slug !== '') {
        $decoded = rawurldecode($slug);
        if (is_string($decoded) && $decoded !== '') {
            $slug = trim($decoded);
        }
    }
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($slug === '' && $id <= 0) {
        api_error('slug or id required', 400);
    }

    $selectCols = 'id, name, slug, visual_id, description, price_text, pack_size, banner, image, sort_order';
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
        'SELECT ' . $selectCols . '
         FROM product_series
         WHERE ' . implode(' AND ', $where) . '
         LIMIT 1'
    );
    $stmt->execute($params);
    $row = $stmt->fetch();

    if (!$row && $slug !== '') {
        $stmt = $pdo->prepare(
            'SELECT ' . $selectCols . '
             FROM product_series
             WHERE published = 1 AND visual_id = ?
             LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
    }

    if (!$row) {
        api_error('Series not found', 404);
    }

    $seriesId = (int) $row['id'];
    $banner = strtolower(trim((string) ($row['banner'] ?? 'none')));
    if ($banner !== 'new' && $banner !== 'off') {
        $banner = 'none';
    }

    $productIds = [];
    $members = [];
    try {
        $itemStmt = $pdo->prepare(
            'SELECT product_id FROM product_series_items
             WHERE series_id = ?
             ORDER BY sort_order ASC, product_id ASC'
        );
        $itemStmt->execute([$seriesId]);
        foreach ($itemStmt->fetchAll() ?: [] as $link) {
            $pid = (int) ($link['product_id'] ?? 0);
            if ($pid > 0) {
                $productIds[] = $pid;
            }
        }
    } catch (Throwable $e) {
        error_log('[product-series-detail] items: ' . $e->getMessage());
    }

    try {
        $memberStmt = $pdo->prepare(
            'SELECT p.id, p.name, p.slug, p.visual_id, p.price_text, p.image
             FROM product_series_items psi
             JOIN products p ON p.id = psi.product_id
             WHERE psi.series_id = ? AND p.published = 1
             ORDER BY psi.sort_order ASC, psi.product_id ASC'
        );
        $memberStmt->execute([$seriesId]);
        foreach ($memberStmt->fetchAll() ?: [] as $memberRow) {
            $image = trim((string) ($memberRow['image'] ?? ''));
            $members[] = [
                'id' => (int) $memberRow['id'],
                'name' => (string) $memberRow['name'],
                'slug' => (string) $memberRow['slug'],
                'visual_id' => isset($memberRow['visual_id']) && trim((string) $memberRow['visual_id']) !== ''
                    ? (string) $memberRow['visual_id']
                    : null,
                'price_text' => isset($memberRow['price_text']) && trim((string) $memberRow['price_text']) !== ''
                    ? (string) $memberRow['price_text']
                    : null,
                'image' => $image !== '' ? $image : null,
                'category_name' => '',
                'model_name' => '',
            ];
        }
    } catch (Throwable $e) {
        error_log('[product-series-detail] members: ' . $e->getMessage());
    }

    $categoryIds = [];
    $categoryNames = [];
    try {
        $catStmt = $pdo->prepare(
            'SELECT sc.category_id, c.name
             FROM product_series_categories sc
             JOIN categories c ON c.id = sc.category_id
             WHERE sc.series_id = ?
             ORDER BY sc.sort_order ASC, c.name ASC'
        );
        $catStmt->execute([$seriesId]);
        foreach ($catStmt->fetchAll() ?: [] as $catRow) {
            $categoryIds[] = (int) ($catRow['category_id'] ?? 0);
            $name = trim((string) ($catRow['name'] ?? ''));
            if ($name !== '') {
                $categoryNames[] = $name;
            }
        }
    } catch (Throwable $e) {
        error_log('[product-series-detail] categories: ' . $e->getMessage());
    }

    $images = [];
    try {
        $galleryStmt = $pdo->prepare(
            'SELECT id, image, alt_text, sort_order
             FROM product_series_images
             WHERE series_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $galleryStmt->execute([$seriesId]);
        foreach ($galleryStmt->fetchAll() ?: [] as $imageRow) {
            $image = trim((string) ($imageRow['image'] ?? ''));
            if ($image === '') {
                continue;
            }
            $images[] = [
                'id' => (int) $imageRow['id'],
                'image' => $image,
                'alt_text' => (string) ($imageRow['alt_text'] ?? ''),
                'sort_order' => (int) ($imageRow['sort_order'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        /* table may not exist */
    }

    $partsLines = [];
    foreach ($members as $member) {
        $name = trim((string) ($member['name'] ?? ''));
        if ($name !== '' && !in_array($name, $partsLines, true)) {
            $partsLines[] = $name;
        }
    }

    $carLines = [];
    try {
        $carStmt = $pdo->prepare(
            "SELECT m.name
             FROM product_series_car_models pscm
             JOIN car_models m ON m.id = pscm.car_model_id
             WHERE pscm.series_id = ?
             ORDER BY pscm.sort_order ASC, m.sort_order ASC, m.name ASC"
        );
        $carStmt->execute([$seriesId]);
        $models = [];
        foreach ($carStmt->fetchAll() ?: [] as $carRow) {
            $part = trim((string) ($carRow['name'] ?? ''));
            if ($part !== '' && !in_array($part, $models, true)) {
                $models[] = $part;
            }
        }
        for ($i = 0, $count = count($models); $i < $count; $i += 2) {
            if (isset($models[$i + 1])) {
                $carLines[] = $models[$i] . ' - ' . $models[$i + 1];
            } else {
                $carLines[] = $models[$i];
            }
        }
    } catch (Throwable $e) {
        /* optional */
    }

    $priceText = isset($row['price_text']) && trim((string) $row['price_text']) !== ''
        ? (string) $row['price_text']
        : null;
    $callForPrice = function_exists('cms_call_for_price_enabled')
        ? cms_call_for_price_enabled()
        : false;
    if ($callForPrice && $priceText !== null && function_exists('cms_call_for_price_label')) {
        $label = cms_call_for_price_label();
        $priceText = $label;
        foreach ($members as $idx => $member) {
            if (isset($member['price_text'])) {
                $members[$idx]['price_text'] = $label;
            }
        }
    }

    $orderable = false;
    try {
        if (function_exists('products_series_orderable_map')) {
            $map = products_series_orderable_map($pdo, [$seriesId]);
            $orderable = !empty($map[$seriesId]);
        } elseif (function_exists('products_series_is_orderable')) {
            $orderable = products_series_is_orderable($pdo, $seriesId);
        } else {
            $orderable = true;
        }
    } catch (Throwable $e) {
        error_log('[product-series-detail] orderable: ' . $e->getMessage());
        $orderable = true;
    }

    api_json([
        'item' => [
            'id' => $seriesId,
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'visual_id' => isset($row['visual_id']) && trim((string) $row['visual_id']) !== ''
                ? (string) $row['visual_id']
                : null,
            'description' => isset($row['description']) && trim((string) $row['description']) !== ''
                ? (string) $row['description']
                : null,
            'image' => isset($row['image']) && trim((string) $row['image']) !== ''
                ? (string) $row['image']
                : null,
            'detail_lead_image' => null,
            'image_setup_override' => null,
            'price_text' => $priceText,
            'pack_size' => isset($row['pack_size']) && (int) $row['pack_size'] > 0
                ? (int) $row['pack_size']
                : null,
            'banner' => $banner,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'product_ids' => $productIds,
            'category_ids' => $categoryIds,
            'category_names' => $categoryNames,
            'category_name' => $categoryNames !== [] ? implode(' · ', $categoryNames) : null,
            'images' => $images,
            'parts_lines' => $partsLines,
            'car_lines' => $carLines,
            'factory_ids' => [],
            'factory_name' => null,
            'products' => $members,
            'orderable' => $orderable,
        ],
        'call_for_price' => $callForPrice,
    ]);
} catch (Throwable $e) {
    error_log('[product-series-detail] ' . $e->getMessage());
    api_json(
        [
            'ok' => false,
            'error' => 'Product series unavailable',
            'detail' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'v' => 3,
        ],
        503
    );
}
