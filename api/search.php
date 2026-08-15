<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/iran-provinces.php';
require_once dirname(__DIR__) . '/cms/lib/search-text.php';

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
    $q = search_normalize((string) ($_GET['q'] ?? ''));

    $empty = [
        'products' => [],
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
    $nameC = search_name_sql('c.name');
    $nameF = search_name_sql('f.name');
    $nameM = search_name_sql('m.name');

    $products = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, p.slug, c.name AS category_name, f.name AS factory_name
             FROM products p
             JOIN categories c ON c.id = p.category_id
             JOIN car_models m ON m.id = p.car_model_id
             JOIN factories f ON f.id = m.factory_id
             WHERE p.published = 1
               AND ({$nameP} LIKE ? OR {$slugP} LIKE ? OR {$nameC} LIKE ? OR {$nameF} LIKE ? OR {$nameM} LIKE ?)
             ORDER BY p.sort_order ASC, p.name ASC
             LIMIT 5"
        );
        $stmt->execute([$like, $like, $like, $like, $like]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $products[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'category_name' => (string) $row['category_name'],
                'factory_name' => (string) $row['factory_name'],
            ];
        }
    } catch (Throwable $e) {
        $products = [];
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
            'SELECT m.id, m.name, m.factory_id, f.name AS factory_name
             FROM car_models m
             JOIN factories f ON f.id = m.factory_id
             WHERE m.published = 1 AND f.published = 1
               AND (' . search_name_sql('m.name') . ' LIKE ? OR ' . search_name_sql('f.name') . ' LIKE ?)
             ORDER BY m.sort_order ASC, m.name ASC
             LIMIT 3'
        );
        $stmt->execute([$like, $like]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $carModels[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'factory_id' => (int) $row['factory_id'],
                'factory_name' => (string) $row['factory_name'],
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
        'categories' => $categories,
        'factories' => $factories,
        'car_models' => $carModels,
        'places' => $places,
    ]);
} catch (Throwable $e) {
    error_log('[search] ' . $e->getMessage());
    api_error('Search unavailable', 503);
}
