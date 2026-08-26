<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/shop-search-intent.php';
require_once dirname(__DIR__) . '/cms/lib/car-model-factories.php';

try {
    $pdo = cms_pdo();
    cms_ensure_car_model_factories_schema($pdo);

    $raw = trim((string) ($_GET['q'] ?? ''));
    $intent = shop_search_parse_intent($pdo, $raw);

    api_json([
        'car_model_id' => $intent['car_model_id'],
        'factory_id' => $intent['factory_id'],
        'category_ids' => $intent['category_ids'],
        'remainder' => $intent['remainder'],
        'matched' => $intent['matched'],
    ]);
} catch (Throwable $e) {
    error_log('[shop-search-intent] ' . $e->getMessage());
    api_error('Shop search intent unavailable', 503);
}
