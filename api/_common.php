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
    api_json(['ok' => false, 'error' => $message], $status);
}

/**
 * Send JSON immediately, then run $after (push) without holding the client.
 *
 * @param callable(): void $after
 */
function api_json_then($data, callable $after, int $status = 200): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"ok":false,"error":"encode failed"}';
        $status = 500;
    }
    http_response_code($status);
    header('Connection: close');
    header('Content-Length: ' . strlen($json));
    echo $json;
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    ignore_user_abort(true);
    try {
        $after();
    } catch (Throwable $e) {
        error_log('[api_json_then] ' . $e->getMessage());
    }
    exit;
}

function api_request_is_multipart(): bool
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if ($contentType !== '' && strpos($contentType, 'multipart/form-data') !== false) {
        return true;
    }
    if ($_FILES === []) {
        return false;
    }
    foreach ($_FILES as $file) {
        if (!is_array($file)) {
            continue;
        }
        if (is_array($file['error'] ?? null)) {
            foreach ($file['error'] as $err) {
                if ((int) $err !== UPLOAD_ERR_NO_FILE) {
                    return true;
                }
            }
            continue;
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }

    return false;
}

function api_request_order_id(int $fallbackFromJson = 0): int
{
    $fromQuery = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    if ($fromQuery > 0) {
        return $fromQuery;
    }
    $fromPost = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    if ($fromPost > 0) {
        return $fromPost;
    }

    return $fallbackFromJson > 0 ? $fallbackFromJson : 0;
}
