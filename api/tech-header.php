<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/page-intros.php';

try {
    $image = trim(cms_setting_get('tech_header_image', ''));
    $zoom = (float) cms_setting_get('tech_header_zoom', '100');
    $posX = (float) cms_setting_get('tech_header_pos_x', '50');
    $posY = (float) cms_setting_get('tech_header_pos_y', '50');
    $intro = cms_page_intro_public('tech_header');

    $zoom = max(100, min(400, $zoom));
    $posX = max(0, min(100, $posX));
    $posY = max(0, min(100, $posY));

    api_json([
        'image' => $image !== '' ? $image : null,
        'zoom' => $zoom,
        'pos_x' => $posX,
        'pos_y' => $posY,
        'title' => $intro['title'],
        'explanation' => $intro['explanation'],
    ]);
} catch (Throwable $e) {
    api_error('Tech header settings unavailable', 503);
}
