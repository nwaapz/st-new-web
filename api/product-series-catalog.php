<?php
declare(strict_types=1);

/**
 * Shop kit list only — uses product_series + product_series_categories.
 * Kept separate from product-series.php so hosting/schema issues cannot break the catalog.
 */
require_once __DIR__ . '/_common.php';

try {
    $pdo = cms_pdo();

    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
    $categoryIds = [];
    if (isset($_GET['category_ids'])) {
        foreach (explode(',', (string) $_GET['category_ids']) as $part) {
            $idPart = (int) trim($part);
            if ($idPart > 0) {
                $categoryIds[] = $idPart;
            }
        }
    }
    if ($categoryId > 0) {
        $categoryIds[] = $categoryId;
    }
    $categoryIds = array_values(array_unique($categoryIds));

    $banner = isset($_GET['banner']) ? trim((string) $_GET['banner']) : '';
    if ($banner !== '' && !in_array($banner, ['none', 'new', 'off'], true)) {
        api_error('Invalid banner filter', 400);
    }

    $where = ['ps.published = 1'];
    $params = [];
    if ($categoryIds !== []) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $where[] = "EXISTS (
            SELECT 1 FROM product_series_categories sc
            WHERE sc.series_id = ps.id
              AND sc.category_id IN ({$placeholders})
        )";
        foreach ($categoryIds as $catId) {
            $params[] = $catId;
        }
    }
    if ($banner !== '') {
        $where[] = 'ps.banner = ?';
        $params[] = $banner;
    }

    $stmt = $pdo->prepare(
        'SELECT ps.id, ps.name, ps.slug, ps.visual_id, ps.description, ps.price_text,
                ps.pack_size, ps.banner, ps.image, ps.detail_lead_image,
                ps.image_setup_override, ps.sort_order
         FROM product_series ps
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY ps.sort_order ASC, ps.name ASC'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $seriesIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $categoryNamesBySeries = [];
    $productIdsBySeries = [];

    if ($seriesIds !== []) {
        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $catStmt = $pdo->prepare(
            "SELECT sc.series_id, c.name
             FROM product_series_categories sc
             JOIN categories c ON c.id = sc.category_id
             WHERE sc.series_id IN ({$placeholders})
             ORDER BY sc.sort_order ASC, c.name ASC"
        );
        $catStmt->execute($seriesIds);
        foreach ($catStmt->fetchAll() ?: [] as $catRow) {
            $sid = (int) $catRow['series_id'];
            $name = trim((string) ($catRow['name'] ?? ''));
            if ($name !== '') {
                $categoryNamesBySeries[$sid][] = $name;
            }
        }

        $itemStmt = $pdo->prepare(
            "SELECT series_id, product_id
             FROM product_series_items
             WHERE series_id IN ({$placeholders})
             ORDER BY sort_order ASC, product_id ASC"
        );
        $itemStmt->execute($seriesIds);
        foreach ($itemStmt->fetchAll() ?: [] as $link) {
            $productIdsBySeries[(int) $link['series_id']][] = (int) $link['product_id'];
        }
    }

    $callForPrice = cms_call_for_price_enabled();
    $callLabel = cms_call_for_price_label();
    $items = [];
    foreach ($rows as $row) {
        $sid = (int) $row['id'];
        $names = $categoryNamesBySeries[$sid] ?? [];
        $productIds = $productIdsBySeries[$sid] ?? [];
        $bannerVal = strtolower(trim((string) ($row['banner'] ?? 'none')));
        if (!in_array($bannerVal, ['new', 'off'], true)) {
            $bannerVal = 'none';
        }
        $priceText = $row['price_text'] !== null && trim((string) $row['price_text']) !== ''
            ? (string) $row['price_text']
            : null;
        if ($callForPrice && $priceText !== null) {
            $priceText = $callLabel;
        }
        $items[] = [
            'id' => $sid,
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
            'detail_lead_image' => null,
            'image_setup_override' => null,
            'price_text' => $priceText,
            'pack_size' => isset($row['pack_size']) && (int) $row['pack_size'] > 0
                ? (int) $row['pack_size']
                : null,
            'banner' => $bannerVal,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'product_ids' => $productIds,
            'category_name' => $names !== [] ? implode(' · ', $names) : null,
            'category_names' => $names,
            'orderable' => false,
        ];
    }

    api_json(['items' => $items, 'call_for_price' => $callForPrice]);
} catch (Throwable $e) {
    error_log('[product-series-catalog] ' . $e->getMessage());
    api_error('Product series unavailable', 503);
}
