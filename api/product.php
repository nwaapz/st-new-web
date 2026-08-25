<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/car-model-factories.php';
require_once dirname(__DIR__) . '/cms/lib/product-car-models.php';

function product_api_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    foreach (['dim_length', 'dim_width', 'dim_height', 'dim_weight'] as $col) {
        $exists = $pdo->query('SHOW COLUMNS FROM products LIKE ' . $pdo->quote($col))->fetchAll();
        if (count($exists) === 0) {
            $pdo->exec("ALTER TABLE products ADD COLUMN {$col} VARCHAR(64) NULL");
        }
    }
    $packExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'pack_size'")->fetchAll();
    if (count($packExists) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN pack_size INT UNSIGNED NULL AFTER price_text');
    }
    foreach (
        [
            'video_path' => 'VARCHAR(512) NULL AFTER image',
            'video_poster' => 'VARCHAR(512) NULL AFTER video_path',
            'detail_lead_image' => 'VARCHAR(512) NULL AFTER video_poster',
            'shop_display_image' => 'VARCHAR(512) NULL AFTER detail_lead_image',
            'video_path_low' => 'VARCHAR(512) NULL AFTER video_poster',
        ] as $col => $definition
    ) {
        $exists = $pdo->query('SHOW COLUMNS FROM products LIKE ' . $pdo->quote($col))->fetchAll();
        if (count($exists) === 0) {
            $pdo->exec("ALTER TABLE products ADD COLUMN {$col} {$definition}");
        }
    }
    $visualExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'visual_id'")->fetchAll();
    if (count($visualExists) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');
    }
    $visualIdx = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'uq_prod_visual_id'")->fetchAll();
    if (count($visualIdx) === 0) {
        $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_prod_visual_id (visual_id)');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_images (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          product_id INT UNSIGNED NOT NULL,
          image VARCHAR(512) NOT NULL,
          alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          KEY idx_product_images_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_reviews (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          product_id INT UNSIGNED NOT NULL,
          author_name VARCHAR(128) NOT NULL,
          rating TINYINT UNSIGNED NOT NULL,
          body TEXT NOT NULL,
          status ENUM(\'pending\',\'approved\',\'rejected\') NOT NULL DEFAULT \'pending\',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_product_reviews_product_status (product_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

try {
    $pdo = cms_pdo();
    product_api_ensure_schema($pdo);
    cms_ensure_car_model_factories_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);
    $slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($slug === '' && $id <= 0) {
        api_error('slug or id required', 400);
    }

    $where = ['p.published = 1'];
    $params = [];
    if ($slug !== '') {
        $where[] = 'p.slug = ?';
        $params[] = $slug;
    } else {
        $where[] = 'p.id = ?';
        $params[] = $id;
    }

    $modelNamesSql = cms_product_model_names_sql('p');
    $factoryNamesSql = cms_product_factory_names_sql('p');
    $primaryModelSql = cms_product_primary_car_model_id_sql('p');
    $primaryFactorySql = cms_product_primary_factory_id_sql('p');
    $stmt = $pdo->prepare(
        'SELECT p.id, p.category_id, p.name, p.slug, p.visual_id, p.description,
                p.price_text, p.pack_size, p.banner, p.image, p.video_path, p.video_path_low, p.video_poster,
                p.detail_lead_image, p.shop_display_image, p.sort_order,
                p.dim_length, p.dim_width, p.dim_height, p.dim_weight,
                c.name AS category_name, c.slug AS category_slug, c.image AS category_image,
                COALESCE(NULLIF(p.shop_display_image, \'\'), NULLIF(p.image, \'\'), NULLIF(c.image, \'\')) AS display_image,
                ' . $modelNamesSql . ' AS model_name,
                ' . $modelNamesSql . ' AS model_names,
                ' . $factoryNamesSql . ' AS factory_name,
                ' . $primaryModelSql . ' AS car_model_id,
                ' . $primaryFactorySql . ' AS factory_id
         FROM products p
         JOIN categories c ON c.id = p.category_id
         WHERE ' . implode(' AND ', $where) . '
         LIMIT 1'
    );
    $stmt->execute($params);
    $product = $stmt->fetch();

    if (!$product) {
        api_error('Product not found', 404);
    }

    $productId = (int) $product['id'];

    $imgStmt = $pdo->prepare(
        'SELECT id, image, alt_text, sort_order
         FROM product_images
         WHERE product_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $imgStmt->execute([$productId]);
    $images = $imgStmt->fetchAll();

    $ratingStmt = $pdo->prepare(
        'SELECT AVG(rating) AS rating_avg, COUNT(*) AS rating_count
         FROM product_reviews
         WHERE product_id = ? AND status = \'approved\''
    );
    $ratingStmt->execute([$productId]);
    $rating = $ratingStmt->fetch() ?: ['rating_avg' => null, 'rating_count' => 0];

    $callForPrice = cms_call_for_price_enabled();
    if ($callForPrice) {
        $product['price_text'] = cms_call_for_price_label();
    }

    $product['images'] = $images;
    $product['car_model_ids'] = cms_product_load_car_model_ids($pdo, $productId);
    if ($product['car_model_ids'] !== []) {
        $product['car_model_id'] = (int) $product['car_model_ids'][0];
    }
    $product['rating_avg'] = $rating['rating_avg'] !== null
        ? round((float) $rating['rating_avg'], 1)
        : null;
    $product['rating_count'] = (int) ($rating['rating_count'] ?? 0);
    $product['call_for_price'] = $callForPrice;

    api_json(['item' => $product]);
} catch (Throwable $e) {
    api_error('Product unavailable', 503);
}
