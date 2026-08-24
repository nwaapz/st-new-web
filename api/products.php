<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/search-text.php';
require_once dirname(__DIR__) . '/cms/lib/car-model-factories.php';
require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';

try {
    $pdo = cms_pdo();
    cms_ensure_car_model_factories_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);
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

    // Related products: same category first, then shared car model; exclude self.
    if ($relatedTo > 0) {
        $src = $pdo->prepare(
            'SELECT category_id FROM products WHERE id = ? AND published = 1'
        );
        $src->execute([$relatedTo]);
        $source = $src->fetch();
        if (!$source) {
            api_json(['call_for_price' => cms_call_for_price_enabled(), 'items' => []]);
        }
        $where[] = 'p.id <> ?';
        $params[] = $relatedTo;
        $srcCat = (int) $source['category_id'];
        $where[] = '(p.category_id = ? OR ' . cms_product_related_car_model_filter_sql('p', $relatedTo) . ')';
        $params[] = $srcCat;
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
        $where[] = cms_product_car_model_filter_sql('p');
        $params[] = $carModelId;
    }
    if ($factoryId > 0) {
        $where[] = cms_product_factory_filter_sql('p');
        $params[] = $factoryId;
    }
    if ($banner !== '') {
        $where[] = 'p.banner = ?';
        $params[] = $banner;
    }
    if ($q !== '') {
        $like = '%' . search_like_escape($q) . '%';
        $modelNamesSql = cms_product_model_names_sql('p');
        $factoryNamesSql = cms_product_factory_names_sql('p');
        $where[] = '(' . search_name_sql('p.name') . ' LIKE ? OR '
            . search_name_sql('p.slug') . ' LIKE ? OR '
            . search_name_sql('c.name') . ' LIKE ? OR '
            . $factoryNamesSql . ' LIKE ? OR '
            . $modelNamesSql . ' LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }

    // No filter = all published products (shop catalog default).
    // Optional filters: series_id, category_id, car_model_id, factory_id, banner, related_to.

    if ($seriesId > 0 && $relatedTo <= 0) {
        $orderBy = 'psi.sort_order ASC, p.sort_order ASC, p.name ASC';
    }

    $modelNamesSql = cms_product_model_names_sql('p');
    $factoryNamesSql = cms_product_factory_names_sql('p');
    $primaryModelSql = cms_product_primary_car_model_id_sql('p');
    $primaryFactorySql = cms_product_primary_factory_id_sql('p');
    $sql = 'SELECT p.id, p.category_id, p.name, p.slug, p.description,
                   p.price_text, p.pack_size, p.banner, p.image, p.shop_display_image, p.sort_order,
                   COALESCE(NULLIF(p.shop_display_image, \'\'), p.image) AS display_image,
                   c.name AS category_name,
                   ' . $modelNamesSql . ' AS model_name,
                   ' . $modelNamesSql . ' AS model_names,
                   ' . $factoryNamesSql . ' AS factory_name,
                   ' . $primaryModelSql . ' AS car_model_id,
                   ' . $primaryFactorySql . ' AS factory_id
            FROM products p
            ' . $seriesJoin . '
            JOIN categories c ON c.id = p.category_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy;

    if ($limit > 0) {
        $sql .= ' LIMIT ' . min($limit, 500);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $productIds = array_map(static fn (array $row): int => (int) $row['id'], $items);
    $carModelMap = cms_product_load_car_model_ids_map($pdo, $productIds);
    foreach ($items as &$item) {
        $pid = (int) $item['id'];
        $item['car_model_ids'] = $carModelMap[$pid] ?? [];
        if (!empty($item['car_model_ids'])) {
            $item['car_model_id'] = (int) $item['car_model_ids'][0];
        }
    }
    unset($item);

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
