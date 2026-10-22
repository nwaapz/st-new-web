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

function cms_series_no_explicit_car_models_sql(string $seriesAlias): string
{
    return 'NOT EXISTS (
        SELECT 1 FROM product_series_car_models pscm_ne
        WHERE pscm_ne.series_id = ' . $seriesAlias . '.id
    )';
}

function cms_series_no_explicit_categories_sql(string $seriesAlias): string
{
    return 'NOT EXISTS (
        SELECT 1 FROM product_series_categories sc_ne
        WHERE sc_ne.series_id = ' . $seriesAlias . '.id
    )';
}

function cms_series_member_car_model_filter_sql(string $seriesAlias): string
{
    return 'EXISTS (
        SELECT 1 FROM product_series_items psi_mc
        JOIN products p_mc ON p_mc.id = psi_mc.product_id AND p_mc.published = 1
        JOIN product_car_models pcm_mc ON pcm_mc.product_id = p_mc.id
        WHERE psi_mc.series_id = ' . $seriesAlias . '.id
          AND pcm_mc.car_model_id = ?
    )';
}

function cms_series_member_factory_filter_sql(string $seriesAlias): string
{
    return 'EXISTS (
        SELECT 1 FROM product_series_items psi_mf
        JOIN products p_mf ON p_mf.id = psi_mf.product_id AND p_mf.published = 1
        JOIN product_car_models pcm_mf ON pcm_mf.product_id = p_mf.id
        JOIN car_model_factories cmf_mf ON cmf_mf.car_model_id = pcm_mf.car_model_id
        WHERE psi_mf.series_id = ' . $seriesAlias . '.id
          AND cmf_mf.factory_id = ?
    )';
}

function cms_series_car_model_shop_filter_sql(string $seriesAlias = 'ps'): string
{
    return '(' . cms_series_car_model_filter_sql($seriesAlias) . '
        OR (' . cms_series_no_explicit_car_models_sql($seriesAlias) . '
            AND ' . cms_series_member_car_model_filter_sql($seriesAlias) . '))';
}

function cms_series_factory_shop_filter_sql(string $seriesAlias = 'ps'): string
{
    return '(' . cms_series_factory_filter_sql($seriesAlias) . '
        OR (' . cms_series_no_explicit_car_models_sql($seriesAlias) . '
            AND ' . cms_series_member_factory_filter_sql($seriesAlias) . '))';
}

function cms_series_category_shop_filter_sql(string $seriesAlias, int $count): string
{
    if ($count <= 0) {
        return '1=1';
    }

    return '(' . cms_series_category_in_filter_sql($seriesAlias, $count) . '
        OR (' . cms_series_no_explicit_categories_sql($seriesAlias) . '
            AND EXISTS (
                SELECT 1 FROM product_series_items psi_cat
                JOIN products p_cat ON p_cat.id = psi_cat.product_id AND p_cat.published = 1
                WHERE psi_cat.series_id = ' . $seriesAlias . '.id
                  AND ' . cms_product_effective_category_in_filter_sql('p_cat', $count) . '
            )))';
}

function cms_series_member_car_category_pair_exists_sql(string $seriesAlias, int $categoryCount): string
{
    return 'EXISTS (
        SELECT 1 FROM product_series_items psi_pair
        JOIN products p_pair ON p_pair.id = psi_pair.product_id AND p_pair.published = 1
        WHERE psi_pair.series_id = ' . $seriesAlias . '.id
          AND ' . cms_product_car_category_pair_filter_sql('p_pair', $categoryCount) . '
    )';
}

function cms_series_car_and_category_shop_filter_sql(string $seriesAlias, int $categoryCount): string
{
    if ($categoryCount <= 0) {
        return cms_series_car_model_shop_filter_sql($seriesAlias);
    }

    $explicitCars = 'EXISTS (SELECT 1 FROM product_series_car_models pscm_cc WHERE pscm_cc.series_id = ' . $seriesAlias . '.id)';

    return '((' . $explicitCars . ' AND ' . cms_series_car_model_filter_sql($seriesAlias) . ' AND '
        . cms_series_category_shop_filter_sql($seriesAlias, $categoryCount) . ')
        OR (' . cms_series_no_explicit_car_models_sql($seriesAlias) . '
            AND ' . cms_series_member_car_category_pair_exists_sql($seriesAlias, $categoryCount) . '))';
}

/**
 * @param list<int> $categoryIds
 */
function cms_series_push_category_shop_params(array &$listParams, array $categoryIds): void
{
    foreach ($categoryIds as $catId) {
        $listParams[] = $catId;
    }
    foreach ($categoryIds as $catId) {
        $listParams[] = $catId;
    }
    foreach ($categoryIds as $catId) {
        $listParams[] = $catId;
    }
}

/**
 * @param list<int> $categoryIds
 */
function cms_series_push_car_category_pair_params(array &$listParams, int $carModelId, array $categoryIds): void
{
    $listParams[] = $carModelId;
    foreach ($categoryIds as $catId) {
        $listParams[] = $catId;
    }
    foreach ($categoryIds as $catId) {
        $listParams[] = $catId;
    }
}

/**
 * @param list<int> $categoryIds
 */
function cms_series_push_car_category_shop_params(array &$listParams, int $carModelId, array $categoryIds): void
{
    $listParams[] = $carModelId;
    cms_series_push_category_shop_params($listParams, $categoryIds);
    cms_series_push_car_category_pair_params($listParams, $carModelId, $categoryIds);
}

/**
 * Shop text search for kits (aligned with global search.php series matching).
 *
 * @return array{0:string,1:list<mixed>}
 */
function cms_series_search_match_sql(string $seriesAlias, string $rawQ, string $normalizedQ): array
{
    $like = '%' . search_like_escape($normalizedQ) . '%';
    [$visualSql, $visualParams] = search_visual_id_like_clause($seriesAlias . '.visual_id', $rawQ);
    $memberName = search_name_sql('p_srch.name');
    $memberCategory = cms_product_any_category_name_search_sql(
        'p_srch',
        search_name_sql('c_srch.name') . ' LIKE ?'
    );
    $modelNamesSql = cms_product_model_names_sql('p_srch');

    $sql = '(' . search_name_sql($seriesAlias . '.name') . ' LIKE ?
        OR ' . $visualSql . '
        OR ' . $seriesAlias . '.slug LIKE ?
        OR EXISTS (
            SELECT 1 FROM product_series_categories sc_srch
            JOIN categories c_srch ON c_srch.id = sc_srch.category_id
            WHERE sc_srch.series_id = ' . $seriesAlias . '.id
              AND ' . search_name_sql('c_srch.name') . ' LIKE ?
        )
        OR EXISTS (
            SELECT 1 FROM product_series_items psi_srch
            JOIN products p_srch ON p_srch.id = psi_srch.product_id AND p_srch.published = 1
            WHERE psi_srch.series_id = ' . $seriesAlias . '.id
              AND (' . $memberName . ' LIKE ?
                   OR ' . search_name_sql('p_srch.visual_id') . ' LIKE ?
                   OR ' . $memberCategory . '
                   OR ' . $modelNamesSql . ' LIKE ?)
        ))';

    return [
        $sql,
        array_merge(
            [$like],
            $visualParams,
            [$like, $like, $like, $like, $like, $like]
        ),
    ];
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
