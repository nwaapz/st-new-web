<?php
declare(strict_types=1);

/**
 * Single kit for shop detail — same minimal bootstrap as product-series-catalog.php.
 */
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/product-stock.php';

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
        'detail_lead_image' => isset($row['detail_lead_image']) && trim((string) ($row['detail_lead_image'] ?? '')) !== ''
            ? (string) $row['detail_lead_image']
            : null,
        'image_setup_override' => isset($row['image_setup_override']) && trim((string) ($row['image_setup_override'] ?? '')) !== ''
            ? (string) $row['image_setup_override']
            : null,
        'price_text' => isset($row['price_text']) && trim((string) ($row['price_text'] ?? '')) !== ''
            ? (string) $row['price_text']
            : null,
        'pack_size' => isset($row['pack_size']) && (int) ($row['pack_size'] ?? 0) > 0
            ? (int) $row['pack_size']
            : null,
        'banner' => product_series_detail_normalize_banner($row['banner'] ?? 'none'),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'product_ids' => $productIds,
    ];
}

function product_series_detail_fetch_series(PDO $pdo, string $slug, int $id): ?array
{
    $selectAttempts = [
        'id, name, slug, visual_id, description, price_text, pack_size, banner, image, detail_lead_image, image_setup_override, sort_order',
        'id, name, slug, visual_id, description, price_text, pack_size, banner, image, sort_order',
    ];

    foreach ($selectAttempts as $selectCols) {
        try {
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
            if ($row) {
                return $row;
            }
            if ($slug !== '') {
                return null;
            }
        } catch (Throwable $e) {
            error_log('[product-series-detail] series select: ' . $e->getMessage());
        }
    }

    return null;
}

function product_series_detail_load_categories(PDO $pdo, int $seriesId): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT sc.category_id, c.name
             FROM product_series_categories sc
             JOIN categories c ON c.id = sc.category_id
             WHERE sc.series_id = ?
             ORDER BY sc.sort_order ASC, c.name ASC'
        );
        $stmt->execute([$seriesId]);
        $ids = [];
        $names = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $ids[] = (int) ($row['category_id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return ['ids' => $ids, 'names' => $names];
    } catch (Throwable $e) {
        error_log('[product-series-detail] categories: ' . $e->getMessage());
        return ['ids' => [], 'names' => []];
    }
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
    $sqlAttempts = [
        'SELECT p.id, p.name, p.slug, p.visual_id, p.price_text, p.image, p.shop_display_image
         FROM product_series_items psi
         JOIN products p ON p.id = psi.product_id
         WHERE psi.series_id = ? AND p.published = 1
         ORDER BY psi.sort_order ASC, psi.product_id ASC',
        'SELECT p.id, p.name, p.slug, p.visual_id, p.price_text, p.image
         FROM product_series_items psi
         JOIN products p ON p.id = psi.product_id
         WHERE psi.series_id = ? AND p.published = 1
         ORDER BY psi.sort_order ASC, psi.product_id ASC',
    ];

    foreach ($sqlAttempts as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
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
                    'category_name' => '',
                    'model_name' => '',
                ];
            }

            return $products;
        } catch (Throwable $e) {
            error_log('[product-series-detail] members: ' . $e->getMessage());
        }
    }

    return [];
}

/** @param list<array{name:string,model_name?:string}> $members */
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

function product_series_detail_series_car_lines(PDO $pdo, int $seriesId): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT GROUP_CONCAT(m.name ORDER BY pscm.sort_order ASC, m.sort_order ASC, m.name ASC SEPARATOR ' · ') AS model_name
             FROM product_series_car_models pscm
             JOIN car_models m ON m.id = pscm.car_model_id
             WHERE pscm.series_id = ?"
        );
        $stmt->execute([$seriesId]);
        $raw = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($raw === '') {
            return [];
        }
        $models = [];
        foreach (preg_split('/\s*·\s*|\s*•\s*/u', $raw) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '' && !in_array($part, $models, true)) {
                $models[] = $part;
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
    } catch (Throwable $e) {
        return [];
    }
}

/** @param list<array{model_name:string}> $members */
function product_series_detail_car_lines_from_members(array $members): array
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

try {
    $pdo = cms_pdo();
    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
    if ($slug !== '') {
        $slug = rawurldecode($slug);
    }
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($slug === '' && $id <= 0) {
        api_error('slug or id required', 400);
    }

    $row = product_series_detail_fetch_series($pdo, $slug, $id);
    if (!$row && $slug !== '') {
        $stmt = $pdo->prepare(
            'SELECT id FROM product_series WHERE published = 1 AND visual_id = ? LIMIT 1'
        );
        $stmt->execute([$slug]);
        $visualRow = $stmt->fetch();
        if ($visualRow) {
            $row = product_series_detail_fetch_series($pdo, '', (int) $visualRow['id']);
        }
    }
    if (!$row) {
        api_error('Series not found', 404);
    }

    $seriesId = (int) $row['id'];
    $itemStmt = $pdo->prepare(
        'SELECT product_id FROM product_series_items WHERE series_id = ? ORDER BY sort_order ASC, product_id ASC'
    );
    $itemStmt->execute([$seriesId]);
    $productIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $categories = product_series_detail_load_categories($pdo, $seriesId);
    $members = product_series_detail_load_members($pdo, $seriesId);
    $carLines = product_series_detail_car_lines_from_members($members);
    if ($carLines === []) {
        $carLines = product_series_detail_series_car_lines($pdo, $seriesId);
    }

    $item = product_series_detail_row($row, $productIds);
    $item['category_ids'] = $categories['ids'];
    $item['category_names'] = $categories['names'];
    $item['category_name'] = $categories['names'] !== [] ? implode(' · ', $categories['names']) : null;
    $item['images'] = product_series_detail_load_gallery($pdo, $seriesId);
    $item['parts_lines'] = product_series_detail_parts_lines($members);
    $item['car_lines'] = $carLines;
    $item['factory_ids'] = [];
    $item['factory_name'] = null;
    $item['products'] = $members;

    $callForPrice = cms_call_for_price_enabled();
    if ($callForPrice) {
        $label = cms_call_for_price_label();
        if (isset($item['price_text'])) {
            $item['price_text'] = $label;
        }
        foreach ($item['products'] as &$product) {
            if (isset($product['price_text'])) {
                $product['price_text'] = $label;
            }
        }
        unset($product);
    }

    $item['orderable'] = false;
    try {
        if (function_exists('products_series_orderable_map')) {
            $orderableMap = products_series_orderable_map($pdo, [$seriesId]);
            $item['orderable'] = $orderableMap[$seriesId] ?? false;
        } else {
            $item['orderable'] = products_series_is_orderable($pdo, $seriesId);
        }
    } catch (Throwable $e) {
        error_log('[product-series-detail] orderable: ' . $e->getMessage());
    }

    api_json(['item' => $item, 'call_for_price' => $callForPrice]);
} catch (Throwable $e) {
    error_log('[product-series-detail] ' . $e->getMessage());
    api_error('Product series unavailable', 503);
}
