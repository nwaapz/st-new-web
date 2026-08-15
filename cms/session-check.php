<?php
declare(strict_types=1);

/**
 * Public JSON endpoint for Font Lab auth gate.
 * Returns whether the current browser has an active CMS admin session.
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

cms_session_start();
$ok = !empty($_SESSION['cms_user_id']);

echo json_encode(
    [
        'ok' => $ok,
        'username' => $ok ? (string) ($_SESSION['cms_username'] ?? '') : null,
    ],
    JSON_UNESCAPED_UNICODE
);
