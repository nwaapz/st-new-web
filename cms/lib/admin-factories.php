<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';

/** @return list<array<string, mixed>> */
function admin_factories_list(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT f.*,
                (SELECT COUNT(DISTINCT cmf.car_model_id) FROM car_model_factories cmf WHERE cmf.factory_id = f.id) AS model_count
         FROM factories f
         ORDER BY f.sort_order ASC, f.name ASC'
    )->fetchAll() ?: [];

    return array_map(static function (array $row): array {
        $item = admin_base_entity_row($row);
        $item['model_count'] = (int) ($row['model_count'] ?? 0);
        return $item;
    }, $rows);
}

function admin_factories_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM factories WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return admin_base_entity_row($row);
}

function admin_factories_delete(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException('شناسه نامعتبر است');
    }
    $stmt = $pdo->prepare('DELETE FROM factories WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * @param array<string, mixed> $data
 */
function admin_factories_save(PDO $pdo, array $data): int
{
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
            'UPDATE factories SET name=?, slug=?, description=?, image=?, sort_order=?, published=? WHERE id=?'
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
        'INSERT INTO factories (name, slug, description, image, sort_order, published) VALUES (?,?,?,?,?,?)'
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
function admin_factories_options(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name FROM factories ORDER BY sort_order ASC, name ASC')->fetchAll() ?: [];
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'label' => (string) $row['name'],
    ], $rows);
}
