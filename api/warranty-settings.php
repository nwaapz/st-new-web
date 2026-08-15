<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/page-intros.php';

try {
    $intro = cms_page_intro_public('warranty');
    api_json([
        'title' => $intro['title'],
        'explanation' => $intro['explanation'],
    ]);
} catch (Throwable $e) {
    api_error('Warranty settings unavailable', 503);
}
