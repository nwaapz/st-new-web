<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
require_once __DIR__ . '/product-categories.php';

function admin_categories_ensure_schema(PDO $pdo): void
{
    cms_ensure_product_categories_schema($pdo);
    $skipFrameExists = $pdo->query("SHOW COLUMNS FROM categories LIKE 'skip_image_auto_frame'")->fetchAll();
    if (count($skipFrameExists) === 0) {
        $pdo->exec('ALTER TABLE categories ADD COLUMN skip_image_auto_frame TINYINT(1) NOT NULL DEFAULT 0 AFTER image');
    }
}

/** @return list<array<string, mixed>> */
function admin_categories_list(PDO $pdo): array
{
    admin_categories_ensure_schema($pdo);
    $rows = $pdo->query(
        'SELECT c.*,
                (SELECT COUNT(*) FROM product_categories pc WHERE pc.category_id = c.id) AS product_count
         FROM categories c
         ORDER BY c.sort_order ASC, c.name ASC'
    )->fetchAll() ?: [];

    return array_map(static function (array $row): array {
        $item = admin_base_entity_row($row);
        $item['product_count'] = (int) ($row['product_count'] ?? 0);
        return $item;
    }, $rows);
}

function admin_categories_get(PDO $pdo, int $id): ?array
{
    admin_categories_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return admin_base_entity_row($row);
}

function admin_categories_delete(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException('شناسه نامعتبر است');
    }
    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * @param array<string, mixed> $data
 */
function admin_categories_save(PDO $pdo, array $data): int
{
    admin_categories_ensure_schema($pdo);

    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $slug = admin_slug_from_payload($name, (string) ($data['slug'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $image = admin_normalize_upload_path(isset($data['image']) ? (string) $data['image'] : null);
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $published = admin_bool_from_payload($data['published'] ?? true) ? 1 : 0;

    if ($name === '') {
        throw new RuntimeException('نام الزامی است');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE categories SET name=?, slug=?, description=?, image=?, sort_order=?, published=? WHERE id=?'
        );
        $stmt->execute([
            $name,
            $slug,
            $description !== '' ? $description : null,
            $image,
            $sortOrder,
            $published,
            $id,
        ]);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO categories (name, slug, description, image, sort_order, published) VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([
        $name,
        $slug,
        $description !== '' ? $description : null,
        $image,
        $sortOrder,
        $published,
    ]);

    return (int) $pdo->lastInsertId();
}

/** @return list<array{id:int,label:string}> */
function admin_categories_options(PDO $pdo): array
{
    admin_categories_ensure_schema($pdo);
    $rows = $pdo->query('SELECT id, name FROM categories ORDER BY sort_order ASC, name ASC')->fetchAll() ?: [];
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'label' => (string) $row['name'],
    ], $rows);
}
