<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/page-intros.php';

try {
    $headerImage = trim(cms_setting_get('shop_header_image', ''));
    $intro = cms_page_intro_public('shop');

    api_json([
        'call_for_price' => cms_call_for_price_enabled(),
        'call_for_price_label' => cms_call_for_price_label(),
        'header_image' => $headerImage !== '' ? $headerImage : null,
        'title' => $intro['title'],
        'explanation' => $intro['explanation'],
    ]);
} catch (Throwable $e) {
    api_error('Shop settings unavailable', 503);
}
