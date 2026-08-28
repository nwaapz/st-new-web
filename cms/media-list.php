<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/uploads.php';

cms_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$kind = trim((string) ($_GET['kind'] ?? 'image'));
$subdir = trim(str_replace(['\\', '..'], ['/', ''], (string) ($_GET['subdir'] ?? '')), '/');
$prefixFilter = cms_sanitize_upload_prefix((string) ($_GET['prefix'] ?? ''));
$sessionOnly = isset($_GET['session']) && (string) $_GET['session'] === '1';
$uploadsRoot = cms_uploads_root();
$items = [];
$sessionPrefix = cms_upload_session_prefix();
$sessionPaths = cms_upload_session_paths();
$sessionPathSet = array_fill_keys($sessionPaths, true);

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
                'in_session' => isset($sessionPathSet[$path]),
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
            $inSession = isset($sessionPathSet[$path]);
            $matchesPrefix = $prefixFilter === '' || str_starts_with($file, $prefixFilter . '-');
            if ($sessionOnly && !$inSession) {
                continue;
            }
            if ($prefixFilter !== '' && !$matchesPrefix && !$inSession) {
                continue;
            }
            $items[] = [
                'path' => $path,
                'name' => $file,
                'url' => cms_asset_url($path),
                'mtime' => (int) filemtime($full),
                'size' => (int) filesize($full),
                'kind' => 'image',
                'in_session' => $inSession,
            ];
        }
    }
}

usort($items, static function (array $a, array $b): int {
    $aSession = !empty($a['in_session']) ? 1 : 0;
    $bSession = !empty($b['in_session']) ? 1 : 0;
    if ($aSession !== $bSession) {
        return $bSession <=> $aSession;
    }
    return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
});

echo json_encode([
    'items' => $items,
    'session_prefix' => $sessionPrefix,
    'session_count' => count($sessionPaths),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
