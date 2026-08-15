<?php
declare(strict_types=1);

/**
 * Publish Font Lab JSON to the live site file (font-lab-export.json).
 * Requires an active CMS session (same cookie as Font Lab gate).
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

cms_session_start();
if (empty($_SESSION['cms_user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty body'], JSON_UNESCAPED_UNICODE);
    exit;
}

$parsed = json_decode($raw, true);
if (!is_array($parsed)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$target = dirname(__DIR__) . '/font-lab-export.json';
$pretty = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($pretty === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Encode failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (file_put_contents($target, $pretty . "\n") === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Write failed — check file permissions'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(
    [
        'ok' => true,
        'path' => '/font-lab-export.json',
        'bytes' => strlen($pretty) + 1,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
