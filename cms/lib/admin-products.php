<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
require_once __DIR__ . '/search-text.php';
require_once __DIR__ . '/shop-search-intent.php';
require_once __DIR__ . '/product-categories.php';
require_once __DIR__ . '/product-car-models.php';
require_once __DIR__ . '/product-series-categories.php';
require_once __DIR__ . '/product-stock.php';
require_once __DIR__ . '/product-price-sync.php';

const ADMIN_PRODUCT_GALLERY_MAX = 12;
const ADMIN_PRODUCTS_PAGE_SIZE = 20;

function admin_products_ensure_schema(PDO $pdo): void
{
    products_ensure_stock_schema($pdo);
    cms_ensure_product_categories_schema($pdo);
    $visualExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'visual_id'")->fetchAll();
    if (count($visualExists) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_images (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          product_id INT UNSIGNED NOT NULL,
          image VARCHAR(512) NOT NULL,
          alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          KEY idx_product_images_product (product_id),
          CONSTRAINT fk_product_image_product
            FOREIGN KEY (product_id) REFERENCES products (id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** @return list<array{image:string,alt_text:string}> */
function admin_products_load_gallery(PDO $pdo, int $productId): array
{
    admin_products_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT image, alt_text, sort_order
         FROM product_images
         WHERE product_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$productId]);
    $slides = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $slides[] = [
            'image' => (string) $row['image'],
            'alt_text' => (string) ($row['alt_text'] ?? ''),
        ];
    }
    return $slides;
}

/** @param list<array{image?:string,alt_text?:string}> $slides */
function admin_products_replace_gallery(PDO $pdo, int $productId, array $slides): void
{
    admin_products_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    $stmt = $pdo->prepare(
        'INSERT INTO product_images (product_id, image, alt_text, sort_order) VALUES (?, ?, ?, ?)'
    );
    $sortOrder = 0;
    foreach ($slides as $slide) {
        $image = admin_normalize_upload_path((string) ($slide['image'] ?? ''));
        if ($image === null) {
            continue;
        }
        $alt = trim((string) ($slide['alt_text'] ?? ''));
        $stmt->execute([$productId, $image, $alt, $sortOrder]);
        $sortOrder++;
        if ($sortOrder >= ADMIN_PRODUCT_GALLERY_MAX) {
            break;
        }
    }
}

/**
 * @return array{
 *   items:list<array<string,mixed>>,
 *   total:int,
 *   page:int,
 *   total_pages:int,
 *   search_intent:?array<string,mixed>
 * }
 */
function admin_products_list(
    PDO $pdo,
    string $q = '',
    int $page = 1,
    int $categoryId = 0,
    int $carModelId = 0
): array {
    admin_products_ensure_schema($pdo);
    cms_ensure_car_model_factories_schema($pdo);
    cms_ensure_product_car_models_schema($pdo);

    $page = max(1, $page);
    $categoryId = max(0, $categoryId);
    $carModelId = max(0, $carModelId);
    $rawQ = trim($q);
    $q = search_normalize($rawQ);
    $categoryIds = [];
    if ($categoryId > 0) {
        $categoryIds[] = $categoryId;
    }

    $searchIntent = null;
    if ($q !== '') {
        $intent = shop_search_parse_intent($pdo, $q, [
            'skip_car' => $carModelId > 0,
            'skip_factory' => true,
            'skip_categories' => $categoryId > 0,
        ]);
        if ($carModelId <= 0 && !empty($intent['car_model_id'])) {
            $carModelId = (int) $intent['car_model_id'];
        }
        if ($categoryId <= 0 && !empty($intent['category_ids'])) {
            $categoryIds = array_values(array_unique(array_map('intval', $intent['category_ids'])));
        }
        $q = search_normalize((string) ($intent['remainder'] ?? ''));
        $searchIntent = shop_search_intent_for_response($intent);
        $searchIntent['matched'] = $intent['matched'] ?? [];
    }

    $where = ['1=1'];
    $params = [];

    if ($carModelId > 0 && $categoryIds !== []) {
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

    if ($q !== '') {
        $like = '%' . search_like_escape($q) . '%';
        [$visualSql, $visualParams] = search_visual_id_like_clause('p.visual_id', $rawQ);
        $modelNamesSqlForQ = cms_product_model_names_sql('p');
        $where[] = '(' . search_name_sql('p.name') . ' LIKE ? OR '
            . $visualSql . ' OR '
            . cms_product_any_category_name_search_sql('p', search_name_sql('c_s.name') . ' LIKE ?') . ' OR '
            . $modelNamesSqlForQ . ' LIKE ?)';
        array_push($params, $like, ...$visualParams);
        array_push($params, $like, $like);
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / ADMIN_PRODUCTS_PAGE_SIZE));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * ADMIN_PRODUCTS_PAGE_SIZE;

    $sql = "SELECT p.id, p.name, p.slug, p.visual_id, p.price_text, p.pack_size, p.stock_qty, p.image, p.published, p.sort_order,
                   " . cms_product_category_names_sql('p') . " AS category_names,
                   " . cms_product_model_names_sql('p') . " AS car_model_names
            FROM products p
            WHERE {$whereSql}
            ORDER BY p.sort_order ASC, p.name ASC
            LIMIT " . ADMIN_PRODUCTS_PAGE_SIZE . " OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'visual_id' => (string) ($row['visual_id'] ?? ''),
            'price_text' => (string) ($row['price_text'] ?? ''),
            'pack_size' => isset($row['pack_size']) && $row['pack_size'] !== null && (int) $row['pack_size'] > 0
                ? (int) $row['pack_size']
                : null,
            'stock_qty' => max(0, (int) ($row['stock_qty'] ?? PRODUCTS_STOCK_DEFAULT)),
            'image' => (string) ($row['image'] ?? ''),
            'category_names' => (string) ($row['category_names'] ?? ''),
            'car_model_names' => (string) ($row['car_model_names'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'published' => (int) ($row['published'] ?? 0) === 1,
        ];
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'search_intent' => $searchIntent,
    ];
}

/**
 * @return list<array{id:int,name:string,visual_id:string,category_names:string,product_count:int,published:bool}>
 */
function admin_series_list_for_category(PDO $pdo, int $categoryId, int $limit = 20): array
{
    admin_products_ensure_schema($pdo);
    cms_series_ensure_categories_schema($pdo);
    $categoryId = max(0, $categoryId);
    if ($categoryId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));

    $sql = 'SELECT s.id, s.name, s.visual_id, s.published,
                   ' . cms_series_category_names_sql('s') . ' AS category_names,
                   (SELECT COUNT(*) FROM product_series_items i WHERE i.series_id = s.id) AS product_count
            FROM product_series s
            WHERE ' . cms_series_category_filter_sql('s') . '
            ORDER BY s.sort_order ASC, s.name ASC
            LIMIT ' . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$categoryId]);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'visual_id' => (string) ($row['visual_id'] ?? ''),
            'category_names' => (string) ($row['category_names'] ?? ''),
            'product_count' => (int) ($row['product_count'] ?? 0),
            'published' => (int) ($row['published'] ?? 0) === 1,
        ];
    }

    return $items;
}

function admin_products_get(PDO $pdo, int $id): ?array
{
    admin_products_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''),
        'visual_id' => (string) ($row['visual_id'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'price_text' => (string) ($row['price_text'] ?? ''),
        'image' => (string) ($row['image'] ?? ''),
        'stock_qty' => max(0, (int) ($row['stock_qty'] ?? PRODUCTS_STOCK_DEFAULT)),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'published' => (int) ($row['published'] ?? 0) === 1,
        'category_ids' => cms_product_load_category_ids($pdo, $id),
        'gallery' => admin_products_load_gallery($pdo, $id),
    ];
}

function admin_products_delete(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException('شناسه نامعتبر است');
    }
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);
}

/** @return array<string, bool> */
function admin_products_table_columns(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    foreach ($pdo->query('SHOW COLUMNS FROM products')->fetchAll() ?: [] as $row) {
        $field = (string) ($row['Field'] ?? '');
        if ($field !== '') {
            $cache[$field] = true;
        }
    }

    return $cache;
}

function admin_products_public_db_error(PDOException $e, string $context): string
{
    $msg = $e->getMessage();
    if (strpos($msg, 'Duplicate entry') !== false) {
        if (strpos($msg, 'uq_prod_slug') !== false || strpos($msg, 'slug') !== false) {
            return 'این اسلاگ قبلاً استفاده شده است';
        }
        if (strpos($msg, 'uq_prod_visual_id') !== false || strpos($msg, 'visual_id') !== false) {
            return 'این شناسه نمایشی قبلاً استفاده شده است';
        }
        return 'اطلاعات تکراری است';
    }
    if (strpos($msg, 'foreign key constraint') !== false || strpos($msg, 'FOREIGN KEY') !== false) {
        return 'یکی از دسته‌های انتخاب‌شده معتبر نیست';
    }
    if (strpos($msg, 'Unknown column') !== false) {
        return 'ستون پایگاه داده وجود ندارد. migrate-run.php را اجرا کنید';
    }
    if (strpos($msg, "doesn't have a default value") !== false) {
        return 'فیلدهای الزامی پایگاه داده مقداردهی نشده‌اند. migrate-run.php را اجرا کنید';
    }

    $short = preg_replace('/\s+\[.*$/', '', $msg) ?? $msg;
    return trim($context . ': ' . $short);
}

/** @param list<int> $categoryIds */
function admin_products_default_car_model_id(PDO $pdo, array $categoryIds): int
{
    $row = $pdo->query('SELECT id FROM car_models ORDER BY sort_order ASC, id ASC LIMIT 1')->fetch();
    $modelId = (int) ($row['id'] ?? 0);
    if ($modelId <= 0) {
        throw new RuntimeException('حداقل یک مدل خودرو در سیستم لازم است');
    }

    return $modelId;
}

/**
 * @param array<string, mixed> $row
 * @param list<int> $categoryIds
 */
function admin_products_apply_row(PDO $pdo, int $id, array $row, array $categoryIds): int
{
    $cols = admin_products_table_columns($pdo);

    if ($id <= 0 && isset($cols['category_id']) && !array_key_exists('category_id', $row)) {
        $firstCategory = (int) ($categoryIds[0] ?? 0);
        if ($firstCategory <= 0) {
            throw new RuntimeException('حداقل یک دسته محصول الزامی است');
        }
        $row['category_id'] = $firstCategory;
    }
    if ($id <= 0 && isset($cols['car_model_id']) && !array_key_exists('car_model_id', $row)) {
        $row['car_model_id'] = admin_products_default_car_model_id($pdo, $categoryIds);
    }

    if ($id > 0) {
        $setParts = [];
        $values = [];
        foreach ($row as $column => $value) {
            if (!isset($cols[$column])) {
                continue;
            }
            $setParts[] = '`' . $column . '`=?';
            $values[] = $value;
        }
        if ($setParts === []) {
            throw new RuntimeException('هیچ فیلد معتبری برای ذخیره محصول یافت نشد');
        }
        $values[] = $id;
        $sql = 'UPDATE products SET ' . implode(', ', $setParts) . ' WHERE id=?';
        try {
            $pdo->prepare($sql)->execute($values);
        } catch (PDOException $e) {
            throw new RuntimeException(admin_products_public_db_error($e, 'ذخیره محصول'));
        }

        return $id;
    }

    $insertCols = [];
    $placeholders = [];
    $values = [];
    foreach ($row as $column => $value) {
        if (!isset($cols[$column])) {
            continue;
        }
        $insertCols[] = '`' . $column . '`';
        $placeholders[] = '?';
        $values[] = $value;
    }
    if ($insertCols === []) {
        throw new RuntimeException('هیچ فیلد معتبری برای ایجاد محصول یافت نشد');
    }
    $sql = 'INSERT INTO products (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
    try {
        $pdo->prepare($sql)->execute($values);
    } catch (PDOException $e) {
        throw new RuntimeException(admin_products_public_db_error($e, 'ایجاد محصول'));
    }

    return (int) $pdo->lastInsertId();
}

/**
 * @param mixed $stockQtyRaw
 * @return array{id:int, stock_qty:int}
 */
function admin_products_update_stock(PDO $pdo, int $id, $stockQtyRaw): array
{
    admin_products_ensure_schema($pdo);

    if ($id <= 0) {
        throw new RuntimeException('شناسه الزامی است');
    }

    $stockQty = products_normalize_stock_qty($stockQtyRaw);

    $exists = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $exists->execute([$id]);
    if (!$exists->fetch()) {
        throw new RuntimeException('محصول یافت نشد');
    }

    $stmt = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
    $stmt->execute([$stockQty, $id]);

    return [
        'id' => $id,
        'stock_qty' => $stockQty,
    ];
}

/**
 * @param array<string, mixed> $data
 */
function admin_products_save(PDO $pdo, array $data): int
{
    admin_products_ensure_schema($pdo);

    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $slug = admin_slug_from_payload($name, (string) ($data['slug'] ?? ''));
    $visualId = trim((string) ($data['visual_id'] ?? ''));
    $visualId = $visualId !== '' ? $visualId : null;
    $description = trim((string) ($data['description'] ?? ''));
    $priceText = trim((string) ($data['price_text'] ?? ''));
    $image = admin_normalize_upload_path(isset($data['image']) ? (string) $data['image'] : null);
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $published = admin_bool_from_payload($data['published'] ?? true) ? 1 : 0;
    $categoryIds = isset($data['category_ids']) && is_array($data['category_ids'])
        ? $data['category_ids']
        : [];
    $gallery = isset($data['gallery']) && is_array($data['gallery'])
        ? $data['gallery']
        : [];
    $stockQty = products_normalize_stock_qty($data['stock_qty'] ?? null);

    if ($name === '') {
        throw new RuntimeException('نام محصول الزامی است');
    }

    $slugCheck = $pdo->prepare('SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1');
    $slugCheck->execute([$slug, $id]);
    if ($slugCheck->fetch()) {
        throw new RuntimeException('این اسلاگ قبلاً استفاده شده است');
    }
    if ($visualId !== null) {
        $visualCheck = $pdo->prepare('SELECT id FROM products WHERE visual_id = ? AND id <> ? LIMIT 1');
        $visualCheck->execute([$visualId, $id]);
        if ($visualCheck->fetch()) {
            throw new RuntimeException('این شناسه نمایشی قبلاً استفاده شده است');
        }
    }

    $row = [
        'name' => $name,
        'slug' => $slug,
        'visual_id' => $visualId,
        'description' => $description !== '' ? $description : null,
        'price_text' => $priceText !== '' ? $priceText : null,
        'image' => $image,
        'stock_qty' => $stockQty,
        'sort_order' => $sortOrder,
        'published' => $published,
    ];

    $productId = admin_products_apply_row($pdo, $id, $row, $categoryIds);

    try {
        cms_product_save_category_ids($pdo, $productId, $categoryIds);
    } catch (PDOException $e) {
        throw new RuntimeException(admin_products_public_db_error($e, 'ذخیره دسته‌های محصول'));
    }
    try {
        admin_products_replace_gallery($pdo, $productId, $gallery);
    } catch (PDOException $e) {
        throw new RuntimeException(admin_products_public_db_error($e, 'ذخیره گالری محصول'));
    }

    if ($priceText !== '') {
        product_price_sync_mark_product($pdo, $productId);
    }

    return $productId;
}

/** @return list<array{id:int,label:string,visual_id:string}> */
function admin_products_options(PDO $pdo, string $q = ''): array
{
    admin_products_ensure_schema($pdo);
    $q = trim($q);
    if ($q === '') {
        $rows = $pdo->query(
            'SELECT id, name, visual_id FROM products ORDER BY sort_order ASC, name ASC LIMIT 200'
        )->fetchAll() ?: [];
    } else {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare(
            'SELECT id, name, visual_id FROM products
             WHERE name LIKE ? OR visual_id LIKE ?
             ORDER BY sort_order ASC, name ASC LIMIT 200'
        );
        $stmt->execute([$like, $like]);
        $rows = $stmt->fetchAll() ?: [];
    }

    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'label' => (string) $row['name'],
        'visual_id' => (string) ($row['visual_id'] ?? ''),
    ], $rows);
}
