<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/home-pattern.php';

try {
    api_json(home_pattern_public_payload());
} catch (Throwable $e) {
    api_error('Home pattern settings unavailable', 503);
}
