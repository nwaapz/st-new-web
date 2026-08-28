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
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'set_prefix') {
        $prefix = cms_upload_session_set_prefix((string) ($_POST['prefix'] ?? ''));
        echo json_encode([
            'ok' => true,
            'prefix' => $prefix,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'clear_session') {
        cms_upload_session_clear_paths();
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new RuntimeException('فایلی ارسال نشده است');
    }

    $kind = trim((string) ($_POST['kind'] ?? 'image'));
    $subdir = trim((string) ($_POST['subdir'] ?? ''));
    $prefixOverride = trim((string) ($_POST['prefix'] ?? ''));

    $imageOptions = [];
    if ($prefixOverride !== '') {
        $imageOptions['prefix'] = cms_sanitize_upload_prefix($prefixOverride);
    }

    $path = $kind === 'video'
        ? cms_store_uploaded_video($_FILES['file'], $subdir !== '' ? $subdir : 'about/videos')
        : cms_store_uploaded_image($_FILES['file'], $imageOptions);

    echo json_encode([
        'ok' => true,
        'path' => $path,
        'url' => cms_asset_url($path),
        'prefix' => cms_upload_session_prefix(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
