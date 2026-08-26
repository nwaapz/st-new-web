<?php

declare(strict_types=1);



require_once __DIR__ . '/_common.php';

require_once dirname(__DIR__) . '/cms/lib/search-text.php';

require_once dirname(__DIR__) . '/cms/lib/car-model-factories.php';

require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';

require_once dirname(__DIR__) . '/cms/lib/shop-search-intent.php';



try {

    $pdo = cms_pdo();

    cms_ensure_car_model_factories_schema($pdo);

    cms_ensure_product_car_models_schema($pdo);

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
    $perPage = isset($_GET['per_page']) ? max(1, min(50, (int) $_GET['per_page'])) : 10;
    $usePaginated = isset($_GET['page']) || isset($_GET['per_page']);

    $q = search_normalize((string) ($_GET['q'] ?? ''));

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

        $usePaginated = false;

    }



    if ($seriesId > 0) {

        $seriesJoin = 'JOIN product_series_items psi ON psi.product_id = p.id AND psi.series_id = ?';

        $params[] = $seriesId;

    }

    if ($categoryIds !== []) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $where[] = 'p.category_id IN (' . $placeholders . ')';
        foreach ($categoryIds as $catId) {
            $params[] = $catId;
        }
    } elseif ($categoryId > 0) {

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

        $modelNamesSqlForQ = cms_product_model_names_sql('p');

        $where[] = '(' . search_name_sql('p.name') . ' LIKE ? OR '

            . search_name_sql('p.visual_id') . ' LIKE ? OR '

            . search_name_sql('c.name') . ' LIKE ? OR '

            . $modelNamesSqlForQ . ' LIKE ?)';

        array_push($params, $like, $like, $like, $like);

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
            . ' JOIN categories c ON c.id = p.category_id WHERE ' . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
    }

    $modelNamesSql = cms_product_model_names_sql('p');

    $factoryNamesSql = cms_product_factory_names_sql('p');

    $primaryModelSql = cms_product_primary_car_model_id_sql('p');

    $primaryFactorySql = cms_product_primary_factory_id_sql('p');

    $sql = 'SELECT p.id, p.category_id, p.name, p.slug, p.visual_id, p.description,

                   p.price_text, p.pack_size, p.banner, p.image, p.shop_display_image, p.sort_order,

                   COALESCE(NULLIF(p.shop_display_image, \'\'), NULLIF(p.image, \'\'), NULLIF(c.image, \'\')) AS display_image,

                   c.name AS category_name,

                   c.image AS category_image,

                   ' . $modelNamesSql . ' AS model_name,

                   ' . $modelNamesSql . ' AS model_names,

                   ' . $factoryNamesSql . ' AS factory_name,

                   ' . $primaryModelSql . ' AS car_model_id,

                   ' . $primaryFactorySql . ' AS factory_id

            FROM products p

            ' . $seriesJoin . '

            JOIN categories c ON c.id = p.category_id

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

