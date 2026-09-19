<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/site-logo.php';

try {
    api_json(site_logo_public_payload());
} catch (Throwable $e) {
    api_error('Site logo unavailable', 503);
}
