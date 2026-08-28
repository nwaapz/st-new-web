<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/branches.php';
require_once dirname(__DIR__) . '/cms/lib/car-model-factories.php';
require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';
require_once dirname(__DIR__) . '/cms/lib/product-categories.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    branches_ensure_schema($pdo);
    cms_ensure_car_model_factories_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);
    cms_ensure_product_categories_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }
    if ($method !== 'GET') {
        api_error('روش نامعتبر است', 405);
    }

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    branches_sync_user_branch($pdo, (int) $user['id'], (string) $user['phone']);
    $branch = branches_for_user($pdo, (int) $user['id']);
    if ($branch === null) {
        api_error('فقط نمایندگان می‌توانند لیست قیمت را ببینند', 403);
    }

    $packExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'pack_size'")->fetchAll();
    if (count($packExists) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN pack_size INT UNSIGNED NULL AFTER price_text');
    }

    $visualExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'visual_id'")->fetchAll();
    if (count($visualExists) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');
    }

    $modelNamesSql = cms_product_model_names_sql('p');
    $factoryNamesSql = cms_product_factory_names_sql('p');
    $primaryCategorySql = cms_product_primary_category_id_sql('p');
    $categoryNamesSql = cms_product_category_names_sql('p');
    $primaryCategoryJoinSql = cms_product_primary_category_join_sql('p');

    $sql = 'SELECT p.id, ' . $primaryCategorySql . ' AS category_id, p.name, p.slug, p.visual_id, p.description,
                   p.price_text, p.pack_size, p.image, p.shop_display_image, p.sort_order,
                   COALESCE(NULLIF(p.shop_display_image, \'\'), NULLIF(p.image, \'\'), NULLIF(c.image, \'\')) AS display_image,
                   ' . $categoryNamesSql . ' AS category_name,
                   c.image AS category_image,
                   ' . $modelNamesSql . ' AS model_name,
                   ' . $factoryNamesSql . ' AS factory_name
            FROM products p
            ' . $primaryCategoryJoinSql . '
            WHERE p.published = 1
            ORDER BY p.sort_order ASC, p.name ASC';

    $stmt = $pdo->query($sql);
    $items = $stmt ? $stmt->fetchAll() : [];

    $productIds = array_map(static fn (array $row): int => (int) $row['id'], $items);
    $categoryMap = cms_product_load_category_ids_map($pdo, $productIds);
    $categoryNamesMap = cms_product_load_category_names_map($pdo, $productIds);

    foreach ($items as &$item) {
        $pid = (int) $item['id'];
        $item['id'] = $pid;
        $item['category_ids'] = $categoryMap[$pid] ?? [];
        if (!empty($item['category_ids'])) {
            $item['category_id'] = (int) $item['category_ids'][0];
        } else {
            $item['category_id'] = isset($item['category_id']) ? (int) $item['category_id'] : null;
        }
        $item['category_names'] = $categoryNamesMap[$pid] ?? [];
        if (isset($item['pack_size']) && $item['pack_size'] !== null && (int) $item['pack_size'] > 0) {
            $item['pack_size'] = (int) $item['pack_size'];
        } else {
            $item['pack_size'] = null;
        }
        $item['sort_order'] = (int) ($item['sort_order'] ?? 0);
    }
    unset($item);

    $catStmt = $pdo->query(
        'SELECT id, name, slug, description, image, sort_order
         FROM categories WHERE published = 1
         ORDER BY sort_order ASC, name ASC'
    );
    $categories = $catStmt ? $catStmt->fetchAll() : [];

    api_json([
        'items' => $items,
        'categories' => $categories,
    ]);
} catch (Throwable $e) {
    api_error('بارگذاری لیست قیمت ناموفق بود', 503);
}
