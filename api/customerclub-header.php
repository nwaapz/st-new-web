<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/page-intros.php';

try {
    $headerImage = trim(cms_setting_get('customerclub_header_image', ''));
    $headerWidth = (int) round((float) cms_setting_get('customerclub_header_width', '330'));
    $headerWidth = max(120, min(720, $headerWidth));
    $intro = cms_page_intro_public('customerclub');
    $sideImage = trim(cms_setting_get('customerclub_side_image', ''));
    $sideText = trim(cms_setting_get('customerclub_side_text', ''));
    $sideTextSize = (int) round((float) cms_setting_get('customerclub_side_text_size', '15'));
    $sideTextSize = max(12, min(28, $sideTextSize));

    api_json([
        'header_image' => $headerImage !== '' ? $headerImage : null,
        'header_width' => $headerWidth,
        'title' => $intro['title'],
        'explanation' => $intro['explanation'],
        'side_image' => $sideImage !== '' ? $sideImage : null,
        'side_text' => $sideText,
        'side_text_size' => $sideTextSize,
    ]);
} catch (Throwable $e) {
    api_error('Customer club header unavailable', 503);
}
