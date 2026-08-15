<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/contact.php';

try {
    api_json(contact_public_payload());
} catch (Throwable $e) {
    api_error('Contact settings unavailable', 503);
}
