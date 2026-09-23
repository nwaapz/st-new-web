<?php
declare(strict_types=1);

/**
 * Kit (product_series) shop filters and display helpers.
 * Car assignments are stored in product_series_car_models (see product-car-models.php).
 */

function cms_series_car_model_filter_sql(string $seriesAlias = 'ps'): string
{
    return 'EXISTS (
        SELECT 1 FROM product_series_car_models pscm
        WHERE pscm.series_id = ' . $seriesAlias . '.id
          AND pscm.car_model_id = ?
    )';
}

function cms_series_factory_filter_sql(string $seriesAlias = 'ps'): string
{
    return 'EXISTS (
        SELECT 1 FROM product_series_car_models pscm_f
        JOIN car_model_factories cmf_f ON cmf_f.car_model_id = pscm_f.car_model_id
        WHERE pscm_f.series_id = ' . $seriesAlias . '.id
          AND cmf_f.factory_id = ?
    )';
}

function cms_series_factory_names_sql(string $seriesAlias = 'ps'): string
{
    return '(SELECT GROUP_CONCAT(DISTINCT f2.name ORDER BY f2.sort_order ASC, f2.name ASC SEPARATOR \' · \')
             FROM product_series_car_models pscm_fn
             JOIN car_model_factories cmf_fn ON cmf_fn.car_model_id = pscm_fn.car_model_id
             JOIN factories f2 ON f2.id = cmf_fn.factory_id
             WHERE pscm_fn.series_id = ' . $seriesAlias . '.id)';
}

/** @return list<int> */
function cms_series_load_factory_ids(PDO $pdo, int $seriesId): array
{
    cms_ensure_product_car_models_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT DISTINCT cmf.factory_id
         FROM product_series_car_models pscm
         JOIN car_model_factories cmf ON cmf.car_model_id = pscm.car_model_id
         WHERE pscm.series_id = ?
         ORDER BY cmf.factory_id ASC'
    );
    $stmt->execute([$seriesId]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $factoryId) {
        $ids[] = (int) $factoryId;
    }

    return $ids;
}
