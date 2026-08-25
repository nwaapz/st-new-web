<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/uploads.php';

cms_require_login();

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new RuntimeException('فایلی ارسال نشده است');
    }

    $kind = trim((string) ($_POST['kind'] ?? 'image'));
    $subdir = trim((string) ($_POST['subdir'] ?? ''));
    $autoFrame = !isset($_POST['auto_frame']) || (string) $_POST['auto_frame'] !== '0';

    $path = $kind === 'video'
        ? cms_store_uploaded_video($_FILES['file'], $subdir !== '' ? $subdir : 'about/videos')
        : cms_store_uploaded_image($_FILES['file'], ['auto_frame' => $autoFrame]);

    echo json_encode([
        'ok' => true,
        'path' => $path,
        'url' => cms_asset_url($path),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
