<?php

declare(strict_types=1);



require_once __DIR__ . '/_common.php';

require_once dirname(__DIR__) . '/cms/lib/search-text.php';

require_once dirname(__DIR__) . '/cms/lib/car-model-factories.php';

require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';

require_once dirname(__DIR__) . '/cms/lib/product-categories.php';

require_once dirname(__DIR__) . '/cms/lib/shop-search-intent.php';
require_once dirname(__DIR__) . '/cms/lib/product-stock.php';



try {

    $pdo = cms_pdo();
    products_ensure_stock_schema($pdo);

    cms_ensure_car_model_factories_schema($pdo);

    cms_ensure_product_car_models_schema($pdo);

    cms_ensure_product_categories_schema($pdo);

    $packExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'pack_size'")->fetchAll();

    if (count($packExists) === 0) {

        $pdo->exec('ALTER TABLE products ADD COLUMN pack_size INT UNSIGNED NULL AFTER price_text');

    }

    $visualExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'visual_id'")->fetchAll();

    if (count($visualExists) === 0) {

        $pdo->exec('ALTER TABLE products ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');

    }

    $visualIdx = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'uq_prod_visual_id'")->fetchAll();

    if (count($visualIdx) === 0) {

        $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_prod_visual_id (visual_id)');

    }

    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;

    $categoryIds = [];
    if (isset($_GET['category_ids'])) {
        foreach (explode(',', (string) $_GET['category_ids']) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $categoryIds[] = $id;
            }
        }
        $categoryIds = array_values(array_unique($categoryIds));
    }

    $carModelId = isset($_GET['car_model_id']) ? (int) $_GET['car_model_id'] : 0;

    $factoryId = isset($_GET['factory_id']) ? (int) $_GET['factory_id'] : 0;

    $seriesId = isset($_GET['series_id']) ? (int) $_GET['series_id'] : 0;

    $relatedTo = isset($_GET['related_to']) ? (int) $_GET['related_to'] : 0;

    $banner = isset($_GET['banner']) ? trim((string) $_GET['banner']) : '';

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 0;

    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $catalogDump = isset($_GET['catalog']) && (string) $_GET['catalog'] === '1'
        && $categoryId <= 0
        && $categoryIds === []
        && $carModelId <= 0
        && $factoryId <= 0
        && $seriesId <= 0
        && $relatedTo <= 0
        && $banner === ''
        && trim((string) ($_GET['q'] ?? '')) === '';
    $perPageMax = $catalogDump ? 2000 : 50;
    $perPage = isset($_GET['per_page']) ? max(1, min($perPageMax, (int) $_GET['per_page'])) : 10;
    $usePaginated = isset($_GET['page']) || isset($_GET['per_page']);

    $rawSearchQ = trim((string) ($_GET['q'] ?? ''));
    $q = search_normalize($rawSearchQ);

    $resolvedIntent = null;

    if ($q !== '' && $relatedTo <= 0) {
        $intent = shop_search_parse_intent($pdo, $q, [
            'skip_car' => $carModelId > 0,
            'skip_factory' => $factoryId > 0,
            'skip_categories' => $categoryId > 0 || $categoryIds !== [],
        ]);
        if ($carModelId <= 0 && !empty($intent['car_model_id'])) {
            $carModelId = (int) $intent['car_model_id'];
        }
        if ($factoryId <= 0 && !empty($intent['factory_id'])) {
            $factoryId = (int) $intent['factory_id'];
        }
        if ($categoryId <= 0 && $categoryIds === [] && !empty($intent['category_ids'])) {
            $categoryIds = $intent['category_ids'];
        }
        $q = search_normalize((string) ($intent['remainder'] ?? ''));
        $resolvedIntent = shop_search_intent_for_response($intent);
    }



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

            'SELECT id FROM products WHERE id = ? AND published = 1'

        );

        $src->execute([$relatedTo]);

        $source = $src->fetch();

        if (!$source) {

            api_json(['call_for_price' => cms_call_for_price_enabled(), 'items' => []]);

        }

        $where[] = 'p.id <> ?';

        $params[] = $relatedTo;

        $relatedCatSql = cms_product_related_category_filter_sql('p', $relatedTo);

        $where[] = '(' . $relatedCatSql . ' OR ' . cms_product_related_car_model_filter_sql('p', $relatedTo) . ')';

        $orderBy = 'CASE WHEN ' . $relatedCatSql . ' THEN 0 ELSE 1 END ASC, p.sort_order ASC, p.name ASC';

        if ($limit <= 0) {

            $limit = 8;

        }

        $usePaginated = false;

    }



    if ($seriesId > 0) {

        $seriesJoin = 'JOIN product_series_items psi ON psi.product_id = p.id AND psi.series_id = ?';

        $params[] = $seriesId;

    }

    if ($categoryId > 0) {
        $categoryIds[] = $categoryId;
        $categoryIds = array_values(array_unique($categoryIds));
    }
    if ($carModelId > 0 && $categoryIds !== []) {
        // Exact (car, category) pair: per-car override wins, product categories are the fallback.
        $where[] = cms_product_car_category_pair_filter_sql('p', count($categoryIds));
        $params[] = $carModelId;
        foreach ($categoryIds as $catId) {
            $params[] = $catId;
        }
        foreach ($categoryIds as $catId) {
            $params[] = $catId;
        }
    } else {
        if ($categoryIds !== []) {
            $where[] = cms_product_effective_category_in_filter_sql('p', count($categoryIds));
            foreach ($categoryIds as $catId) {
                $params[] = $catId;
            }
            foreach ($categoryIds as $catId) {
                $params[] = $catId;
            }
        }
        if ($carModelId > 0) {
            $where[] = cms_product_car_model_filter_sql('p');
            $params[] = $carModelId;
        }
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

        [$visualSql, $visualParams] = search_visual_id_like_clause('p.visual_id', $rawSearchQ);

        $modelNamesSqlForQ = cms_product_model_names_sql('p');

        $where[] = '(' . search_name_sql('p.name') . ' LIKE ? OR '

            . $visualSql . ' OR '

            . cms_product_any_category_name_search_sql('p', search_name_sql('c_s.name') . ' LIKE ?') . ' OR '

            . $modelNamesSqlForQ . ' LIKE ?)';

        array_push($params, $like, ...$visualParams, $like, $like);

    }



    // No filter = all published products (shop catalog default).

    // Optional filters: series_id, category_id, car_model_id, factory_id, banner, related_to.



    if ($seriesId > 0 && $relatedTo <= 0) {

        $orderBy = 'psi.sort_order ASC, p.sort_order ASC, p.name ASC';

    }



    $whereSql = implode(' AND ', $where);

    $total = 0;
    $totalPages = 1;
    if ($usePaginated) {
        $countSql = 'SELECT COUNT(*) FROM products p ' . $seriesJoin
            . ' WHERE ' . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
    }

    $sql = 'SELECT p.id, p.name, p.slug, p.visual_id, p.description,
                   p.price_text, p.pack_size, p.banner, p.image, p.shop_display_image, p.sort_order,
                   p.published, p.stock_qty
            FROM products p
            ' . $seriesJoin . '
            WHERE ' . $whereSql . '
            ORDER BY ' . $orderBy;



    if ($usePaginated) {
        $offset = ($page - 1) * $perPage;
        $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . (int) $offset;
    } elseif ($limit > 0) {

        $sql .= ' LIMIT ' . min($limit, 500);

    }



    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);

    $items = $stmt->fetchAll();



    $productIds = array_map(static fn (array $row): int => (int) $row['id'], $items);

    $carModelMap = cms_product_load_car_model_ids_map($pdo, $productIds);

    $categoryMap = cms_product_load_category_ids_map($pdo, $productIds);

    $carModelCategoryMap = cms_product_load_car_model_categories_map($pdo, $productIds);

    $carModelEntriesMap = cms_product_load_car_model_entries_map($pdo, $productIds);

    $categoryNamesMap = cms_product_load_category_names_map($pdo, $productIds);

    $shopLabels = cms_product_load_shop_labels_map($pdo, $productIds);

    $categoryNamesById = [];
    $categoryImagesById = [];
    $neededCategoryIds = [];
    foreach ($categoryMap as $categoryIdsForProduct) {
        foreach ($categoryIdsForProduct as $neededId) {
            if ((int) $neededId > 0) {
                $neededCategoryIds[(int) $neededId] = true;
            }
        }
    }
    foreach ($carModelCategoryMap as $pairs) {
        foreach ($pairs as $pair) {
            $neededId = (int) ($pair['category_id'] ?? 0);
            if ($neededId > 0) {
                $neededCategoryIds[$neededId] = true;
            }
        }
    }
    if ($neededCategoryIds !== []) {
        $catIds = array_keys($neededCategoryIds);
        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $catStmt = $pdo->prepare("SELECT id, name, image FROM categories WHERE id IN ({$placeholders})");
        $catStmt->execute($catIds);
        foreach ($catStmt->fetchAll() ?: [] as $catRow) {
            $catId = (int) $catRow['id'];
            $categoryNamesById[$catId] = (string) $catRow['name'];
            $image = trim((string) ($catRow['image'] ?? ''));
            $categoryImagesById[$catId] = $image !== '' ? $image : null;
        }
    }

    foreach ($items as &$item) {

        $pid = (int) $item['id'];

        $item['car_model_ids'] = $carModelMap[$pid] ?? [];

        if (!empty($item['car_model_ids'])) {

            $item['car_model_id'] = (int) $item['car_model_ids'][0];

        }

        $item['category_ids'] = $categoryMap[$pid] ?? [];

        if (!empty($item['category_ids'])) {

            $item['category_id'] = (int) $item['category_ids'][0];

        }

        $item['category_names'] = $categoryNamesMap[$pid] ?? [];

        $labels = $shopLabels[$pid] ?? null;
        $modelName = is_array($labels) ? trim((string) ($labels['model_name'] ?? '')) : '';
        $factoryName = is_array($labels) ? trim((string) ($labels['factory_name'] ?? '')) : '';
        $item['model_name'] = $modelName !== '' ? $modelName : null;
        $item['model_names'] = $item['model_name'];
        $item['factory_name'] = $factoryName !== '' ? $factoryName : null;
        $factoryIdValue = is_array($labels) ? (int) ($labels['factory_id'] ?? 0) : 0;
        $item['factory_id'] = $factoryIdValue > 0 ? $factoryIdValue : null;
        $labelCarId = is_array($labels) ? (int) ($labels['car_model_id'] ?? 0) : 0;
        if (empty($item['car_model_id']) && $labelCarId > 0) {
            $item['car_model_id'] = $labelCarId;
        }
        $primaryCategoryId = (int) ($item['category_ids'][0] ?? 0);
        $categoryImage = $categoryImagesById[$primaryCategoryId] ?? null;
        $item['category_image'] = $categoryImage;
        $displayImage = trim((string) ($item['shop_display_image'] ?? ''));
        if ($displayImage === '') {
            $displayImage = trim((string) ($item['image'] ?? ''));
        }
        if ($displayImage === '' && $categoryImage !== null) {
            $displayImage = $categoryImage;
        }
        $item['display_image'] = $displayImage !== '' ? $displayImage : null;

        $item['car_model_categories'] = $carModelCategoryMap[$pid] ?? [];

        $item['car_model_entries'] = $carModelEntriesMap[$pid] ?? [];

        $displayCategory = cms_product_display_category_label(
            $categoryNamesById,
            $item['category_ids'],
            $item['car_model_categories']
        );
        if ($displayCategory !== '') {
            $item['category_name'] = $displayCategory;
        }

        $item['orderable'] = (int) ($item['published'] ?? 0) === 1
            && (int) ($item['stock_qty'] ?? 0) > 0;
        unset($item['published'], $item['stock_qty']);

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



    $response = [

        'call_for_price' => $callForPrice,

        'items' => $items,

    ];

    if ($usePaginated) {
        $response['total'] = $total;
        $response['page'] = $page;
        $response['per_page'] = $perPage;
        $response['total_pages'] = $totalPages;
        if ($resolvedIntent !== null) {
            $response['resolved_intent'] = $resolvedIntent;
        }
    }

    api_json($response);

} catch (Throwable $e) {

    api_error('Products unavailable', 503);

}

