<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/uploads.php';

cms_require_login();

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) {
        $body = [];
    }

    $scope = trim((string) ($body['scope'] ?? 'session'));
    if ($scope !== 'session') {
        throw new RuntimeException('محدوده پردازش پشتیبانی نمی‌شود');
    }

    $paths = cms_upload_session_paths();
    $results = [];

    foreach ($paths as $webPath) {
        if (!cms_upload_path_is_framable($webPath)) {
            continue;
        }

        $result = cms_reframe_uploaded_image($webPath);
        $oldPath = $webPath;
        $newPath = $result['path'];

        if ($result['changed']) {
            cms_upload_session_replace_path($oldPath, $newPath);
        }

        $results[] = [
            'old' => $oldPath,
            'path' => $newPath,
            'changed' => $result['changed'],
            'skipped' => $result['skipped'],
            'message' => $result['message'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'results' => $results,
        'session_paths' => cms_upload_session_paths(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
