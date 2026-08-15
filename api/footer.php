<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/footer.php';

try {
    api_json(footer_public_payload());
} catch (Throwable $e) {
    api_error('Footer settings unavailable', 503);
}
