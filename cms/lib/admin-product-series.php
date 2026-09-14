<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
require_once __DIR__ . '/product-series-categories.php';
require_once __DIR__ . '/admin-products.php';

const ADMIN_SERIES_GALLERY_MAX = 12;
const ADMIN_SERIES_PAGE_SIZE = 20;

function admin_product_series_ensure_schema(PDO $pdo): void
{
    cms_series_ensure_categories_schema($pdo);
    $visualCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'visual_id'")->fetchAll();
    if (count($visualCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');
    }
    $priceCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'price_text'")->fetchAll();
    if (count($priceCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN price_text VARCHAR(128) NULL AFTER description');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_series_images (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          series_id INT UNSIGNED NOT NULL,
          image VARCHAR(512) NOT NULL,
          alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          KEY idx_series_images_series (series_id),
          CONSTRAINT fk_series_image_series
            FOREIGN KEY (series_id) REFERENCES product_series (id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** @return list<array{image:string,alt_text:string}> */
function admin_series_load_gallery(PDO $pdo, int $seriesId): array
{
    admin_product_series_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT image, alt_text, sort_order
         FROM product_series_images
         WHERE series_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$seriesId]);
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
function admin_series_replace_gallery(PDO $pdo, int $seriesId, array $slides): void
{
    admin_product_series_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM product_series_images WHERE series_id = ?')->execute([$seriesId]);
    $stmt = $pdo->prepare(
        'INSERT INTO product_series_images (series_id, image, alt_text, sort_order) VALUES (?, ?, ?, ?)'
    );
    $sortOrder = 0;
    foreach ($slides as $slide) {
        $image = admin_normalize_upload_path((string) ($slide['image'] ?? ''));
        if ($image === null) {
            continue;
        }
        $alt = trim((string) ($slide['alt_text'] ?? ''));
        $stmt->execute([$seriesId, $image, $alt, $sortOrder]);
        $sortOrder++;
        if ($sortOrder >= ADMIN_SERIES_GALLERY_MAX) {
            break;
        }
    }
}

/** @return list<int> */
function admin_series_load_product_ids(PDO $pdo, int $seriesId): array
{
    $stmt = $pdo->prepare(
        'SELECT product_id FROM product_series_items WHERE series_id = ? ORDER BY sort_order ASC, product_id ASC'
    );
    $stmt->execute([$seriesId]);
    $ids = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $ids[] = (int) $row['product_id'];
    }
    return $ids;
}

/**
 * @return array{items:list<array<string,mixed>>,total:int,page:int,total_pages:int}
 */
function admin_product_series_list(PDO $pdo, string $q = '', int $page = 1): array
{
    admin_product_series_ensure_schema($pdo);
    $page = max(1, $page);
    $q = trim($q);
    $where = '1=1';
    $params = [];
    if ($q !== '') {
        $where .= ' AND (s.name LIKE ? OR s.visual_id LIKE ? OR s.slug LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like];
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM product_series s WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / ADMIN_SERIES_PAGE_SIZE));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * ADMIN_SERIES_PAGE_SIZE;

    $sql = "SELECT s.*,
                   (SELECT COUNT(*) FROM product_series_items i WHERE i.series_id = s.id) AS product_count,
                   " . cms_series_category_names_sql('s') . " AS category_names
            FROM product_series s
            WHERE {$where}
            ORDER BY s.sort_order ASC, s.name ASC
            LIMIT " . ADMIN_SERIES_PAGE_SIZE . " OFFSET {$offset}";
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
            'product_count' => (int) ($row['product_count'] ?? 0),
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

function admin_product_series_get(PDO $pdo, int $id): ?array
{
    admin_product_series_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM product_series WHERE id = ? LIMIT 1');
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
        'category_ids' => cms_series_load_category_ids($pdo, $id),
        'product_ids' => admin_series_load_product_ids($pdo, $id),
        'gallery' => admin_series_load_gallery($pdo, $id),
    ];
}

function admin_product_series_delete(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException('شناسه نامعتبر است');
    }
    $stmt = $pdo->prepare('DELETE FROM product_series WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * @param array<string, mixed> $data
 */
function admin_product_series_save(PDO $pdo, array $data): int
{
    admin_product_series_ensure_schema($pdo);

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
    $productIds = isset($data['product_ids']) && is_array($data['product_ids'])
        ? $data['product_ids']
        : [];
    $gallery = isset($data['gallery']) && is_array($data['gallery'])
        ? $data['gallery']
        : [];

    if ($name === '') {
        throw new RuntimeException('نام سری الزامی است');
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE product_series SET name=?, slug=?, visual_id=?, description=?, price_text=?, image=?, sort_order=?, published=? WHERE id=?'
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
            $seriesId = $id;
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO product_series (name, slug, visual_id, description, price_text, image, sort_order, published)
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
            $seriesId = (int) $pdo->lastInsertId();
        }

        cms_series_save_category_ids($pdo, $seriesId, $categoryIds);

        $pdo->prepare('DELETE FROM product_series_items WHERE series_id = ?')->execute([$seriesId]);
        $uniqueProductIds = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId > 0 && !in_array($productId, $uniqueProductIds, true)) {
                $uniqueProductIds[] = $productId;
            }
        }
        if ($uniqueProductIds !== []) {
            $insert = $pdo->prepare(
                'INSERT INTO product_series_items (series_id, product_id, sort_order) VALUES (?,?,?)'
            );
            foreach ($uniqueProductIds as $index => $productId) {
                $insert->execute([$seriesId, $productId, $index]);
            }
        }

        admin_series_replace_gallery($pdo, $seriesId, $gallery);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $seriesId;
}
