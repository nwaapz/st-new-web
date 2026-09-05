<?php
declare(strict_types=1);

/**
 * Kit (product_series) filters derived from member products.
 * A series matches only when every published member part passes the filter.
 */

function cms_series_has_published_members_sql(string $seriesAlias = 'ps'): string
{
    return 'EXISTS (
        SELECT 1 FROM product_series_items psi_m
        JOIN products p_m ON p_m.id = psi_m.product_id AND p_m.published = 1
        WHERE psi_m.series_id = ' . $seriesAlias . '.id
    )';
}

function cms_series_factory_filter_sql(string $seriesAlias = 'ps'): string
{
    return cms_series_has_published_members_sql($seriesAlias) . '
        AND NOT EXISTS (
            SELECT 1 FROM product_series_items psi_f
            JOIN products p_f ON p_f.id = psi_f.product_id AND p_f.published = 1
            WHERE psi_f.series_id = ' . $seriesAlias . '.id
              AND NOT EXISTS (
                  SELECT 1 FROM product_car_models pcm_ff
                  JOIN car_model_factories cmf_ff ON cmf_ff.car_model_id = pcm_ff.car_model_id
                  WHERE pcm_ff.product_id = p_f.id
                    AND cmf_ff.factory_id = ?
              )
        )';
}

function cms_series_car_model_filter_sql(string $seriesAlias = 'ps'): string
{
    return cms_series_has_published_members_sql($seriesAlias) . '
        AND NOT EXISTS (
            SELECT 1 FROM product_series_items psi_c
            JOIN products p_c ON p_c.id = psi_c.product_id AND p_c.published = 1
            WHERE psi_c.series_id = ' . $seriesAlias . '.id
              AND NOT EXISTS (
                  SELECT 1 FROM product_car_models pcm_c
                  WHERE pcm_c.product_id = p_c.id
                    AND pcm_c.car_model_id = ?
              )
        )';
}

function cms_series_factory_names_sql(string $seriesAlias = 'ps'): string
{
    return '(SELECT GROUP_CONCAT(DISTINCT f2.name ORDER BY f2.sort_order ASC, f2.name ASC SEPARATOR \' · \')
             FROM product_series_items psi_fn
             JOIN products p_fn ON p_fn.id = psi_fn.product_id AND p_fn.published = 1
             JOIN product_car_models pcm_fn ON pcm_fn.product_id = p_fn.id
             JOIN car_model_factories cmf_fn ON cmf_fn.car_model_id = pcm_fn.car_model_id
             JOIN factories f2 ON f2.id = cmf_fn.factory_id
             WHERE psi_fn.series_id = ' . $seriesAlias . '.id)';
}

/** @return list<int> */
function cms_series_load_factory_ids(PDO $pdo, int $seriesId): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT cmf.factory_id
         FROM product_series_items psi
         JOIN products p ON p.id = psi.product_id AND p.published = 1
         JOIN product_car_models pcm ON pcm.product_id = p.id
         JOIN car_model_factories cmf ON cmf.car_model_id = pcm.car_model_id
         WHERE psi.series_id = ?
         ORDER BY cmf.factory_id ASC'
    );
    $stmt->execute([$seriesId]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $factoryId) {
        $ids[] = (int) $factoryId;
    }
    return $ids;
}
