<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/car-model-factories.php';

function car_models_api_row(PDO $pdo, array $row): array
{
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

    return [
        'id' => (int) $row['id'],
        'factory_id' => (int) ($row['factory_id'] ?? ($factoryIds[0] ?? 0)),
        'factory_ids' => $factoryIds,
        'factory_names' => (string) ($row['factory_names'] ?? ''),
        'name' => (string) $row['name'],
        'slug' => (string) $row['slug'],
        'description' => $row['description'] ?? null,
        'image' => $row['image'] ?? null,
        'sort_order' => (int) ($row['sort_order'] ?? 0),
    ];
}

try {
    $pdo = cms_pdo();
    cms_ensure_car_model_factories_schema($pdo);

    $factoryNamesSql = cms_car_model_factory_names_sql('m');
    $primaryFactorySql = cms_car_model_primary_factory_id_sql('m');

    $carModelId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($carModelId > 0) {
        $stmt = $pdo->prepare(
            "SELECT m.id, {$primaryFactorySql} AS factory_id, {$factoryNamesSql} AS factory_names,
                    m.name, m.slug, m.description, m.image, m.sort_order
             FROM car_models m
             WHERE m.id = ? AND m.published = 1
             LIMIT 1"
        );
        $stmt->execute([$carModelId]);
        $row = $stmt->fetch();
        if (!$row) {
            api_error('Car model not found', 404);
        }
        api_json(['item' => car_models_api_row($pdo, $row)]);
    }

    $factoryId = isset($_GET['factory_id']) ? (int) $_GET['factory_id'] : 0;
    $factorySlug = trim((string) ($_GET['factory_slug'] ?? ''));

    if ($factoryId <= 0 && $factorySlug !== '') {
        $s = $pdo->prepare('SELECT id FROM factories WHERE slug = ? AND published = 1 LIMIT 1');
        $s->execute([$factorySlug]);
        $factoryId = (int) ($s->fetchColumn() ?: 0);
    }

    if ($factoryId <= 0) {
        api_error('factory_id, factory_slug, or id required', 400);
    }

    $filterSql = cms_car_model_factory_filter_sql('m');
    $stmt = $pdo->prepare(
        "SELECT m.id, {$primaryFactorySql} AS factory_id, {$factoryNamesSql} AS factory_names,
                m.name, m.slug, m.description, m.image, m.sort_order
         FROM car_models m
         WHERE m.published = 1 AND {$filterSql}
         ORDER BY m.sort_order ASC, m.name ASC"
    );
    $stmt->execute([$factoryId]);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = car_models_api_row($pdo, $row);
    }
    api_json(['items' => $items]);
} catch (Throwable $e) {
    api_error('Car models unavailable', 503);
}
