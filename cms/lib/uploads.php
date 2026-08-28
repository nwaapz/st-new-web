<?php
declare(strict_types=1);

/**
 * Shared image/video upload storage for CMS forms and upload.php AJAX.
 */

/** Site-root uploads directory (public/uploads), not cms/uploads. */
function cms_uploads_root(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';
}

/**
 * Move legacy files from cms/uploads/ into site-root uploads/ (preserves subdirs).
 *
 * @return array{moved:int, skipped:int, errors:string[]}
 */
function cms_migrate_legacy_cms_uploads(): array
{
    $legacyRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $targetRoot = cms_uploads_root();
    $result = ['moved' => 0, 'skipped' => 0, 'errors' => []];

    if (!is_dir($legacyRoot)) {
        return $result;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($legacyRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $legacyPath = $item->getPathname();
        $relative = substr($legacyPath, strlen($legacyRoot));
        $relative = str_replace('\\', '/', ltrim($relative, '/\\'));
        $dest = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (is_file($dest)) {
            $result['skipped']++;
            continue;
        }

        $destDir = dirname($dest);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            $result['errors'][] = 'Cannot create directory: ' . $destDir;
            continue;
        }

        if (!rename($legacyPath, $dest)) {
            $result['errors'][] = 'Failed to move: ' . $relative;
            continue;
        }
        $result['moved']++;
    }

    return $result;
}

function cms_unique_upload_name(string $uploadsDir, string $originalName, string $ext, string $prefix = ''): string
{
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $safe = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $base);
    $safe = trim((string) $safe, '-._');
    $safe = preg_replace('/-+/', '-', $safe);
    if ($safe === '' || $safe === null) {
        $safe = 'image';
    }
    if (function_exists('mb_strlen') && mb_strlen($safe) > 80) {
        $safe = mb_substr($safe, 0, 80);
    } elseif (strlen($safe) > 80) {
        $safe = substr($safe, 0, 80);
    }
    $safe = rtrim($safe, '-._');

    $prefix = cms_sanitize_upload_prefix($prefix);
    if ($prefix !== '') {
        $safe = $prefix . '-' . $safe;
    }

    $name = $safe . $ext;
    if (!file_exists($uploadsDir . DIRECTORY_SEPARATOR . $name)) {
        return $name;
    }
    return $safe . '-' . bin2hex(random_bytes(3)) . $ext;
}

function cms_upload_ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

/** Safe ASCII-ish prefix for uploaded filenames (session-scoped). */
function cms_sanitize_upload_prefix(string $prefix): string
{
    $prefix = trim($prefix);
    if ($prefix === '') {
        return '';
    }
    $safe = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $prefix);
    $safe = trim((string) $safe, '-._');
    $safe = preg_replace('/-+/', '-', (string) $safe);
    if ($safe === '' || $safe === null) {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($safe) > 40) {
        return mb_substr($safe, 0, 40);
    }
    if (strlen($safe) > 40) {
        return substr($safe, 0, 40);
    }
    return rtrim($safe, '-._');
}

function cms_upload_session_prefix(): string
{
    cms_upload_ensure_session();
    return cms_sanitize_upload_prefix((string) ($_SESSION['cms_upload_prefix'] ?? ''));
}

function cms_upload_session_set_prefix(string $prefix): string
{
    cms_upload_ensure_session();
    $safe = cms_sanitize_upload_prefix($prefix);
    $_SESSION['cms_upload_prefix'] = $safe;
    return $safe;
}

/** @return list<string> Public paths uploaded in this CMS session. */
function cms_upload_session_paths(): array
{
    cms_upload_ensure_session();
    $paths = $_SESSION['cms_upload_session_paths'] ?? [];
    if (!is_array($paths)) {
        return [];
    }
    $out = [];
    foreach ($paths as $path) {
        if (is_string($path) && str_starts_with($path, '/uploads/')) {
            $out[] = $path;
        }
    }
    return $out;
}

function cms_upload_session_track_path(string $path): void
{
    $path = trim($path);
    if ($path === '' || !str_starts_with($path, '/uploads/')) {
        return;
    }
    cms_upload_ensure_session();
    if (!isset($_SESSION['cms_upload_session_paths']) || !is_array($_SESSION['cms_upload_session_paths'])) {
        $_SESSION['cms_upload_session_paths'] = [];
    }
    if (!in_array($path, $_SESSION['cms_upload_session_paths'], true)) {
        $_SESSION['cms_upload_session_paths'][] = $path;
    }
}

function cms_upload_session_clear_paths(): void
{
    cms_upload_ensure_session();
    $_SESSION['cms_upload_session_paths'] = [];
}

/**
 * Resolve `/uploads/...` to an absolute file under uploads root.
 * Returns null if missing or outside uploads.
 */
function cms_resolve_upload_web_path(string $webPath): ?string
{
    $webPath = trim($webPath);
    if ($webPath === '' || !str_starts_with($webPath, '/uploads/')) {
        return null;
    }

    $relative = substr($webPath, strlen('/uploads/'));
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || str_contains($relative, '..')) {
        return null;
    }

    $root = cms_uploads_root();
    $rootReal = realpath($root);
    if ($rootReal === false || !is_dir($rootReal)) {
        return null;
    }

    $abs = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($abs)) {
        return null;
    }

    $resolved = realpath($abs);
    if ($resolved === false) {
        return null;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
    $resolvedNorm = str_replace('\\', '/', $resolved);
    if (!str_starts_with($resolvedNorm, $rootPrefix)) {
        return null;
    }

    return $resolved;
}

/** @return list<array{path:string,name:string,relative:string,url:string,mtime:int,size:int}> */
function cms_scan_upload_images(): array
{
    $root = cms_uploads_root();
    if (!is_dir($root)) {
        return [];
    }

    $rootReal = realpath($root);
    if ($rootReal === false) {
        return [];
    }

    $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $items = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $ext = strtolower($fileInfo->getExtension());
        if (!in_array($ext, $imageExts, true)) {
            continue;
        }

        $full = $fileInfo->getPathname();
        $relative = substr($full, strlen($rootReal));
        $relative = str_replace('\\', '/', ltrim($relative, '/\\'));
        $path = '/uploads/' . $relative;

        $items[] = [
            'path' => $path,
            'name' => basename($relative),
            'relative' => $relative,
            'url' => cms_asset_url($path),
            'mtime' => (int) filemtime($full),
            'size' => (int) filesize($full),
        ];
    }

    usort($items, static fn(array $a, array $b): int => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));

    return $items;
}

function cms_delete_upload_file(string $webPath): void
{
    $abs = cms_resolve_upload_web_path($webPath);
    if ($abs === null) {
        throw new RuntimeException('فایل یافت نشد');
    }

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        throw new RuntimeException('فقط حذف تصویر مجاز است');
    }

    if (!unlink($abs)) {
        throw new RuntimeException('حذف فایل ناموفق بود');
    }

    cms_upload_ensure_session();
    if (isset($_SESSION['cms_upload_session_paths']) && is_array($_SESSION['cms_upload_session_paths'])) {
        $_SESSION['cms_upload_session_paths'] = array_values(array_filter(
            $_SESSION['cms_upload_session_paths'],
            static fn($path): bool => $path !== $webPath
        ));
    }
}

function cms_format_upload_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

function cms_upload_error_message(int $error, string $kind = 'file'): string
{
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'حجم فایل از حد مجاز سرور بیشتر است',
        UPLOAD_ERR_FORM_SIZE => 'حجم فایل خیلی بزرگ است',
        UPLOAD_ERR_PARTIAL => 'آپلود ناقص بود — دوباره تلاش کنید',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور موجود نیست',
        UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل روی دیسک ممکن نیست',
        UPLOAD_ERR_EXTENSION => 'افزونه PHP آپلود را مسدود کرد',
    ];
    if (isset($uploadErrors[$error])) {
        return $uploadErrors[$error];
    }
    return $kind === 'video' ? ('آپلود ویدیو ناموفق بود (کد ' . $error . ')') : ('آپلود ناموفق بود (کد ' . $error . ')');
}

/** @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
 *  @param array{prefix?:string} $options */
function cms_store_uploaded_image(array $file, array $options = []): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(cms_upload_error_message($error, 'image'));
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('فایل آپلود معتبر نیست');
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $file['tmp_name']) ?: '';
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type((string) $file['tmp_name']);
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $extGuess = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $mime = $extMap[$extGuess] ?? $mime;
    }

    $map = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
        'image/gif' => '.gif',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('فقط JPEG/PNG/WebP/GIF مجاز است (نوع تشخیص‌داده‌شده: ' . ($mime !== '' ? $mime : 'نامشخص') . ')');
    }
    // PHP upload_max_filesize / post_max_size still apply.

    $uploadsDir = cms_uploads_root();
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
        throw new RuntimeException('ساخت پوشه uploads ممکن نیست — دسترسی نوشتن را بررسی کنید');
    }
    if (!is_writable($uploadsDir)) {
        throw new RuntimeException('پوشه uploads قابل نوشتن نیست');
    }

    $prefix = trim((string) ($options['prefix'] ?? ''));
    if ($prefix === '') {
        $prefix = cms_upload_session_prefix();
    }

    $name = cms_unique_upload_name($uploadsDir, (string) ($file['name'] ?? 'image'), $map[$mime], $prefix);
    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره فایل ناموفق بود');
    }

    $publicPath = '/uploads/' . str_replace('\\', '/', basename($dest));
    cms_upload_session_track_path($publicPath);

    return $publicPath;
}

/** @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file */
function cms_store_uploaded_video(array $file, string $subdir = 'about/videos'): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(cms_upload_error_message($error, 'video'));
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('فایل ویدیو معتبر نیست');
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $file['tmp_name']) ?: '';
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type((string) $file['tmp_name']);
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $extGuess = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extMap = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
        $mime = $extMap[$extGuess] ?? $mime;
    }

    $map = [
        'video/mp4' => '.mp4',
        'video/webm' => '.webm',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('فقط MP4 یا WebM مجاز است (نوع تشخیص‌داده‌شده: ' . ($mime !== '' ? $mime : 'نامشخص') . ')');
    }
    if ((int) ($file['size'] ?? 0) > 80 * 1024 * 1024) {
        throw new RuntimeException('حداکثر حجم ویدیو ۸۰ مگابایت است — فیلم را فشرده کنید');
    }

    $uploadsRoot = cms_uploads_root();
    $subdir = trim(str_replace(['\\', '..'], ['/', ''], $subdir), '/');
    if ($subdir === '') {
        $subdir = 'about/videos';
    }
    $uploadsDir = $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
        throw new RuntimeException('ساخت پوشه uploads/' . $subdir . ' ممکن نیست');
    }
    if (!is_writable($uploadsDir)) {
        throw new RuntimeException('پوشه uploads/' . $subdir . ' قابل نوشتن نیست');
    }

    $name = cms_unique_upload_name($uploadsDir, (string) ($file['name'] ?? 'video'), $map[$mime]);
    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره ویدیو ناموفق بود');
    }

    return '/uploads/' . $subdir . '/' . $name;
}
