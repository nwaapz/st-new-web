<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/uploads.php';

cms_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$kind = trim((string) ($_GET['kind'] ?? 'image'));
$subdir = trim(str_replace(['\\', '..'], ['/', ''], (string) ($_GET['subdir'] ?? '')), '/');
$uploadsRoot = cms_uploads_root();
$items = [];

if ($kind === 'video') {
    if ($subdir === '') {
        $subdir = 'about/videos';
    }
    $scanDir = $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (is_dir($scanDir)) {
        $files = scandir($scanDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $full = $scanDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($full)) {
                continue;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp4', 'webm'], true)) {
                continue;
            }
            $path = '/uploads/' . $subdir . '/' . $file;
            $items[] = [
                'path' => $path,
                'name' => $file,
                'url' => cms_asset_url($path),
                'mtime' => (int) filemtime($full),
                'size' => (int) filesize($full),
                'kind' => 'video',
            ];
        }
    }
} else {
    if (is_dir($uploadsRoot)) {
        $files = scandir($uploadsRoot) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $full = $uploadsRoot . DIRECTORY_SEPARATOR . $file;
            if (!is_file($full)) {
                continue;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                continue;
            }
            $path = '/uploads/' . $file;
            $items[] = [
                'path' => $path,
                'name' => $file,
                'url' => cms_asset_url($path),
                'mtime' => (int) filemtime($full),
                'size' => (int) filesize($full),
                'kind' => 'image',
            ];
        }
    }
}

usort($items, static function (array $a, array $b): int {
    return $b['mtime'] <=> $a['mtime'];
});

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
