<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
require_once __DIR__ . '/product-categories.php';
require_once __DIR__ . '/product-car-models.php';

const ADMIN_PRODUCT_GALLERY_MAX = 12;
const ADMIN_PRODUCTS_PAGE_SIZE = 20;

function admin_products_ensure_schema(PDO $pdo): void
{
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
 * @return array{items:list<array<string,mixed>>,total:int,page:int,total_pages:int}
 */
function admin_products_list(PDO $pdo, string $q = '', int $page = 1, int $categoryId = 0): array
{
    admin_products_ensure_schema($pdo);
    $page = max(1, $page);
    $categoryId = max(0, $categoryId);
    $q = trim($q);
    $where = '1=1';
    $params = [];
    if ($q !== '') {
        $where .= ' AND (p.name LIKE ? OR p.visual_id LIKE ? OR p.slug LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like];
    }
    if ($categoryId > 0) {
        $where .= ' AND ' . cms_product_category_filter_sql('p');
        $params[] = $categoryId;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / ADMIN_PRODUCTS_PAGE_SIZE));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * ADMIN_PRODUCTS_PAGE_SIZE;

    $sql = "SELECT p.id, p.name, p.slug, p.visual_id, p.price_text, p.image, p.published, p.sort_order,
                   " . cms_product_category_names_sql('p') . " AS category_names,
                   " . cms_product_model_names_sql('p') . " AS car_model_names
            FROM products p
            WHERE {$where}
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
    ];
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

    if ($name === '') {
        throw new RuntimeException('نام محصول الزامی است');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE products SET name=?, slug=?, visual_id=?, description=?, price_text=?, image=?, sort_order=?, published=? WHERE id=?'
        );
        $stmt->execute([
            $name,
            $slug,
            $visualId,
            $description !== '' ? $description : null,
            $priceText !== '' ? $priceText : null,
            $image,
            $sortOrder,
            $published,
            $id,
        ]);
        $productId = $id;
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO products (name, slug, visual_id, description, price_text, image, sort_order, published)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $name,
            $slug,
            $visualId,
            $description !== '' ? $description : null,
            $priceText !== '' ? $priceText : null,
            $image,
            $sortOrder,
            $published,
        ]);
        $productId = (int) $pdo->lastInsertId();
    }

    cms_product_save_category_ids($pdo, $productId, $categoryIds);
    admin_products_replace_gallery($pdo, $productId, $gallery);

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
