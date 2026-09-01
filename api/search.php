<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/iran-provinces.php';
require_once dirname(__DIR__) . '/cms/lib/search-text.php';
require_once dirname(__DIR__) . '/cms/lib/car-model-factories.php';
require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';
require_once dirname(__DIR__) . '/cms/lib/product-categories.php';

function search_add_place(array &$places, array &$seen, string $code, string $label): void
{
    $name = iran_province_name($code);
    if ($name === null) {
        return;
    }
    $key = $code . "\0" . $label;
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $places[] = [
        'province_code' => $code,
        'province_name' => $name,
        'label' => $label,
        'href' => '/branch-portal/?province=' . rawurlencode($code),
    ];
}

try {
    $pdo = cms_pdo();
    cms_ensure_car_model_factories_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);
    cms_ensure_product_categories_schema($pdo);
    $q = search_normalize((string) ($_GET['q'] ?? ''));

    $empty = [
        'products' => [],
        'series' => [],
        'categories' => [],
        'factories' => [],
        'car_models' => [],
        'places' => [],
    ];

    $qLen = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
    if ($q === '' || $qLen < 2) {
        api_json($empty);
    }

    $like = '%' . search_like_escape($q) . '%';
    $nameP = search_name_sql('p.name');
    $slugP = search_name_sql('p.slug');
    $nameM = search_name_sql('m.name');
    $modelNamesSql = cms_product_model_names_sql('p');
    $factoryNamesSql = cms_product_factory_names_sql('p');
    $categoryNamesSql = cms_product_category_names_sql('p');
    $primaryFactorySql = cms_car_model_primary_factory_id_sql('m');

    $products = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, p.slug, p.visual_id, {$categoryNamesSql} AS category_name, {$factoryNamesSql} AS factory_name
             FROM products p
             WHERE p.published = 1
               AND ({$nameP} LIKE ? OR {$slugP} LIKE ? OR "
            . cms_product_any_category_name_search_sql('p', search_name_sql('c_s.name') . ' LIKE ?')
            . " OR {$factoryNamesSql} LIKE ? OR {$modelNamesSql} LIKE ? OR " . search_name_sql('p.visual_id') . " LIKE ?)
             ORDER BY p.sort_order ASC, p.name ASC
             LIMIT 5"
        );
        $stmt->execute([$like, $like, $like, $like, $like, $like]);
        $rows = $stmt->fetchAll() ?: [];
        $productIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $categoryMap = cms_product_load_category_ids_map($pdo, $productIds);
        $carModelCategoryMap = cms_product_load_car_model_categories_map($pdo, $productIds);
        foreach ($rows as $row) {
            $pid = (int) $row['id'];
            $categoryIds = $categoryMap[$pid] ?? [];
            $carModelCategories = $carModelCategoryMap[$pid] ?? [];
            $categoryName = cms_product_resolve_display_category_names(
                $pdo,
                $categoryIds,
                $carModelCategories
            );
            if ($categoryName === '') {
                $categoryName = (string) ($row['category_name'] ?? '');
            }
            $products[] = [
                'id' => $pid,
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'visual_id' => $row['visual_id'] !== null ? (string) $row['visual_id'] : null,
                'category_name' => $categoryName,
                'factory_name' => (string) $row['factory_name'],
            ];
        }
    } catch (Throwable $e) {
        $products = [];
    }

    $series = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, slug, visual_id
             FROM product_series
             WHERE published = 1
               AND (' . search_name_sql('name') . ' LIKE ?
                    OR ' . search_name_sql('slug') . ' LIKE ?
                    OR ' . search_name_sql('visual_id') . ' LIKE ?)
             ORDER BY sort_order ASC, name ASC
             LIMIT 5'
        );
        $stmt->execute([$like, $like, $like]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $series[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'visual_id' => $row['visual_id'] !== null && trim((string) $row['visual_id']) !== ''
                    ? (string) $row['visual_id']
                    : null,
            ];
        }
    } catch (Throwable $e) {
        $series = [];
    }

    $categories = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name FROM categories
             WHERE published = 1 AND ' . search_name_sql('name') . ' LIKE ?
             ORDER BY sort_order ASC, name ASC
             LIMIT 3'
        );
        $stmt->execute([$like]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $categories[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
    } catch (Throwable $e) {
        $categories = [];
    }

    $factories = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name FROM factories
             WHERE published = 1 AND ' . search_name_sql('name') . ' LIKE ?
             ORDER BY sort_order ASC, name ASC
             LIMIT 3'
        );
        $stmt->execute([$like]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $factories[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
    } catch (Throwable $e) {
        $factories = [];
    }

    $carModels = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT m.id, m.name, {$primaryFactorySql} AS factory_id, {$factoryNamesSql} AS factory_name
             FROM car_models m
             WHERE m.published = 1
               AND (
                 {$nameM} LIKE ?
                 OR {$factoryNamesSql} LIKE ?
                 OR EXISTS (
                   SELECT 1 FROM car_model_factories cmf_s
                   JOIN factories f_s ON f_s.id = cmf_s.factory_id
                   WHERE cmf_s.car_model_id = m.id
                     AND f_s.published = 1
                     AND " . search_name_sql('f_s.name') . " LIKE ?
                 )
               )
             ORDER BY m.sort_order ASC, m.name ASC
             LIMIT 3"
        );
        $stmt->execute([$like, $like, $like]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $factoryIdsStmt = $pdo->prepare(
                'SELECT factory_id FROM car_model_factories
                 WHERE car_model_id = ?
                 ORDER BY sort_order ASC, factory_id ASC'
            );
            $factoryIdsStmt->execute([(int) $row['id']]);
            $factoryIds = array_map(
                static fn (array $r): int => (int) $r['factory_id'],
                $factoryIdsStmt->fetchAll() ?: []
            );
            $carModels[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'factory_id' => (int) ($row['factory_id'] ?? 0),
                'factory_ids' => $factoryIds,
                'factory_name' => (string) ($row['factory_name'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $carModels = [];
    }

    $places = [];
    $seenPlaces = [];

    foreach (iran_provinces() as $p) {
        $normName = search_normalize($p['name']);
        if ($normName !== '' && mb_strpos($normName, $q) !== false) {
            search_add_place($places, $seenPlaces, $p['code'], 'نمایندگان — ' . $p['name']);
        }
    }

    foreach (iran_city_aliases() as $city => $code) {
        $normCity = search_normalize($city);
        if ($normCity === '' || mb_strpos($normCity, $q) === false) {
            continue;
        }
        $pname = iran_province_name($code) ?? '';
        $label = search_normalize($city) === search_normalize($pname)
            ? 'نمایندگان — ' . $pname
            : 'نمایندگان — ' . $city . ' (' . $pname . ')';
        search_add_place($places, $seenPlaces, $code, $label);
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT city, name, province_code, province_name
             FROM branches
             WHERE published = 1
               AND (' . search_name_sql('city') . ' LIKE ?
                    OR ' . search_name_sql('name') . ' LIKE ?
                    OR ' . search_name_sql('province_name') . ' LIKE ?)
             ORDER BY province_name ASC, city ASC, name ASC
             LIMIT 20'
        );
        $stmt->execute([$like, $like, $like]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $code = (string) $row['province_code'];
            $city = trim((string) $row['city']);
            $pname = (string) $row['province_name'];
            if ($city === '') {
                continue;
            }
            $label = search_normalize($city) === search_normalize($pname)
                ? 'نمایندگان — ' . $pname
                : 'نمایندگان — ' . $city . ' (' . $pname . ')';
            search_add_place($places, $seenPlaces, $code, $label);
        }
    } catch (Throwable $e) {
        // branches table may be missing on a fresh install
    }

    if (count($places) > 5) {
        $places = array_slice($places, 0, 5);
    }

    api_json([
        'products' => $products,
        'series' => $series,
        'categories' => $categories,
        'factories' => $factories,
        'car_models' => $carModels,
        'places' => $places,
    ]);
} catch (Throwable $e) {
    error_log('[search] ' . $e->getMessage());
    api_error('Search unavailable', 503);
}
