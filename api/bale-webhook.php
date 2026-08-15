<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cms/bootstrap.php';
require_once dirname(__DIR__) . '/cms/lib/bale.php';

header('Content-Type: application/json; charset=utf-8');

function bale_webhook_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bale_webhook_json(['ok' => false, 'error' => 'method'], 405);
}

$expected = trim(cms_setting_get('contact_bale_webhook_secret', ''));
$got = trim((string) ($_GET['secret'] ?? ''));
if ($expected === '' || !hash_equals($expected, $got)) {
    bale_webhook_json(['ok' => false, 'error' => 'forbidden'], 403);
}

$raw = file_get_contents('php://input');
$update = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($update)) {
    bale_webhook_json(['ok' => true, 'ignored' => true]);
}

try {
    bale_handle_update(cms_pdo(), $update);
    bale_webhook_json(['ok' => true]);
} catch (Throwable $e) {
    bale_webhook_json(['ok' => false, 'error' => 'handler'], 500);
}
