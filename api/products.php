<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/search-text.php';

try {
    $pdo = cms_pdo();
    $packExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'pack_size'")->fetchAll();
    if (count($packExists) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN pack_size INT UNSIGNED NULL AFTER price_text');
    }
    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
    $carModelId = isset($_GET['car_model_id']) ? (int) $_GET['car_model_id'] : 0;
    $factoryId = isset($_GET['factory_id']) ? (int) $_GET['factory_id'] : 0;
    $seriesId = isset($_GET['series_id']) ? (int) $_GET['series_id'] : 0;
    $relatedTo = isset($_GET['related_to']) ? (int) $_GET['related_to'] : 0;
    $banner = isset($_GET['banner']) ? trim((string) $_GET['banner']) : '';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 0;
    $q = search_normalize((string) ($_GET['q'] ?? ''));

    if ($banner !== '' && !in_array($banner, ['none', 'new', 'off'], true)) {
        api_error('Invalid banner filter', 400);
    }

    $where = ['p.published = 1'];
    $params = [];
    $seriesJoin = '';
    $orderBy = 'p.sort_order ASC, p.name ASC';

    // Related products: same category first, then same car model; exclude self.
    if ($relatedTo > 0) {
        $src = $pdo->prepare(
            'SELECT category_id, car_model_id FROM products WHERE id = ? AND published = 1'
        );
        $src->execute([$relatedTo]);
        $source = $src->fetch();
        if (!$source) {
            api_json(['call_for_price' => cms_call_for_price_enabled(), 'items' => []]);
        }
        $where[] = 'p.id <> ?';
        $params[] = $relatedTo;
        $srcCat = (int) $source['category_id'];
        $srcModel = (int) $source['car_model_id'];
        $where[] = '(p.category_id = ? OR p.car_model_id = ?)';
        $params[] = $srcCat;
        $params[] = $srcModel;
        $orderBy = 'CASE WHEN p.category_id = ' . $srcCat . ' THEN 0 ELSE 1 END ASC, p.sort_order ASC, p.name ASC';
        if ($limit <= 0) {
            $limit = 8;
        }
    }

    if ($seriesId > 0) {
        $seriesJoin = 'JOIN product_series_items psi ON psi.product_id = p.id AND psi.series_id = ?';
        $params[] = $seriesId;
    }
    if ($categoryId > 0) {
        $where[] = 'p.category_id = ?';
        $params[] = $categoryId;
    }
    if ($carModelId > 0) {
        $where[] = 'p.car_model_id = ?';
        $params[] = $carModelId;
    }
    if ($factoryId > 0) {
        $where[] = 'm.factory_id = ?';
        $params[] = $factoryId;
    }
    if ($banner !== '') {
        $where[] = 'p.banner = ?';
        $params[] = $banner;
    }
    if ($q !== '') {
        $like = '%' . search_like_escape($q) . '%';
        $where[] = '(' . search_name_sql('p.name') . ' LIKE ? OR '
            . search_name_sql('p.slug') . ' LIKE ? OR '
            . search_name_sql('c.name') . ' LIKE ? OR '
            . search_name_sql('f.name') . ' LIKE ? OR '
            . search_name_sql('m.name') . ' LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }

    // No filter = all published products (shop catalog default).
    // Optional filters: series_id, category_id, car_model_id, factory_id, banner, related_to.

    if ($seriesId > 0 && $relatedTo <= 0) {
        $orderBy = 'psi.sort_order ASC, p.sort_order ASC, p.name ASC';
    }

    $sql = 'SELECT p.id, p.category_id, p.car_model_id, p.name, p.slug, p.description,
                   p.price_text, p.pack_size, p.banner, p.image, p.sort_order,
                   c.name AS category_name, m.name AS model_name, f.name AS factory_name
            FROM products p
            ' . $seriesJoin . '
            JOIN categories c ON c.id = p.category_id
            JOIN car_models m ON m.id = p.car_model_id
            JOIN factories f ON f.id = m.factory_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy;

    if ($limit > 0) {
        $sql .= ' LIMIT ' . min($limit, 500);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $callForPrice = cms_call_for_price_enabled();
    $callLabel = cms_call_for_price_label();
    if ($callForPrice) {
        foreach ($items as &$item) {
            $item['price_text'] = $callLabel;
        }
        unset($item);
    }

    api_json([
        'call_for_price' => $callForPrice,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    api_error('Products unavailable', 503);
}
