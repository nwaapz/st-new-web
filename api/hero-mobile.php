<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/hero-mobile.php';

try {
    $pdo = cms_pdo();
    $slides = hero_mobile_load_saved($pdo);
    api_json(['slides' => $slides]);
} catch (Throwable $e) {
    api_error('Hero unavailable', 503);
}
