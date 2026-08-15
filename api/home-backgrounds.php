<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    api_json([
        'sales_bg' => trim(cms_setting_get('home_sales_bg', '')),
        'sales_cta' => trim(cms_setting_get('home_sales_cta', '')),
        'awards_bg' => trim(cms_setting_get('home_awards_bg', '')),
        'series_bg' => trim(cms_setting_get('home_series_bg', '')),
        'series_side_text' => trim(cms_setting_get('home_series_side_text', '')),
        'category_side_text' => trim(cms_setting_get('home_category_side_text', '')),
        'new_products_side_text' => trim(cms_setting_get('home_new_products_side_text', '')),
    ]);
} catch (Throwable $e) {
    api_error('Home background settings unavailable', 503);
}
