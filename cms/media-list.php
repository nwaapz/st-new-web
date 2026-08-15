<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cms_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
$items = [];

if (is_dir($uploadsDir)) {
    $files = scandir($uploadsDir) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $full = $uploadsDir . DIRECTORY_SEPARATOR . $file;
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
        ];
    }
}

usort($items, static function (array $a, array $b): int {
    return $b['mtime'] <=> $a['mtime'];
});

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
