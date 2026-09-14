<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';
require_once __DIR__ . '/car-model-factories.php';

/** @return list<array<string, mixed>> */
function admin_car_models_list(PDO $pdo): array
{
    cms_ensure_car_model_factories_schema($pdo);
    $rows = $pdo->query(
        'SELECT m.*,
                ' . cms_car_model_factory_names_sql('m') . ' AS factory_names,
                (SELECT COUNT(*) FROM product_car_models pcm WHERE pcm.car_model_id = m.id) AS product_count
         FROM car_models m
         ORDER BY m.sort_order ASC, m.name ASC'
    )->fetchAll() ?: [];

    $items = [];
    foreach ($rows as $row) {
        $item = admin_base_entity_row($row);
        $item['factory_names'] = (string) ($row['factory_names'] ?? '');
        $item['factory_ids'] = cms_car_model_load_factory_ids($pdo, (int) $row['id']);
        $item['product_count'] = (int) ($row['product_count'] ?? 0);
        $items[] = $item;
    }
    return $items;
}

function admin_car_models_get(PDO $pdo, int $id): ?array
{
    cms_ensure_car_model_factories_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM car_models WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $item = admin_base_entity_row($row);
    $item['factory_ids'] = cms_car_model_load_factory_ids($pdo, $id);
    return $item;
}

function admin_car_models_delete(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException('شناسه نامعتبر است');
    }
    $stmt = $pdo->prepare('DELETE FROM car_models WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * @param array<string, mixed> $data
 */
function admin_car_models_save(PDO $pdo, array $data): int
{
    cms_ensure_car_model_factories_schema($pdo);

    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $slug = admin_slug_from_payload($name, (string) ($data['slug'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $image = admin_normalize_upload_path(isset($data['image']) ? (string) $data['image'] : null);
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $published = admin_bool_from_payload($data['published'] ?? true) ? 1 : 0;
    $factoryIds = isset($data['factory_ids']) && is_array($data['factory_ids'])
        ? $data['factory_ids']
        : [];

    if ($name === '') {
        throw new RuntimeException('نام مدل الزامی است');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE car_models SET name=?, slug=?, description=?, image=?, sort_order=?, published=? WHERE id=?'
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
        cms_car_model_save_factory_ids($pdo, $id, $factoryIds);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO car_models (name, slug, description, image, sort_order, published) VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([
        $name,
        $slug,
        $description !== '' ? $description : null,
        $image,
        $sortOrder,
        $published,
    ]);
    $newId = (int) $pdo->lastInsertId();
    cms_car_model_save_factory_ids($pdo, $newId, $factoryIds);
    return $newId;
}
