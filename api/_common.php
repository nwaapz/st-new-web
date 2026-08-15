<?php
declare(strict_types=1);

/**
 * Shared PDO for public JSON APIs (no session required).
 */
require_once dirname(__DIR__) . '/cms/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function api_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status = 500): void
{
    api_json(['error' => $message], $status);
}
