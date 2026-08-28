<?php
declare(strict_types=1);

/**
 * Shared image/video upload storage for CMS forms and upload.php AJAX.
 */

require_once __DIR__ . '/image-optimize.php';

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

/** Map `/uploads/foo.png` to absolute path under uploads root (flat files only). */
function cms_upload_web_to_absolute(string $webPath): ?string
{
    $webPath = trim($webPath);
    if ($webPath === '' || !str_starts_with($webPath, '/uploads/')) {
        return null;
    }

    $name = basename(str_replace('\\', '/', $webPath));
    if ($name === '' || $name === '.' || $name === '..') {
        return null;
    }

    $abs = cms_uploads_root() . DIRECTORY_SEPARATOR . $name;
    if (!is_file($abs)) {
        return null;
    }

    return $abs;
}

function cms_upload_absolute_to_web(string $absolutePath): string
{
    $name = basename(str_replace('\\', '/', $absolutePath));
    return '/uploads/' . $name;
}

function cms_upload_session_replace_path(string $oldPath, string $newPath): void
{
    cms_upload_ensure_session();
    if (!isset($_SESSION['cms_upload_session_paths']) || !is_array($_SESSION['cms_upload_session_paths'])) {
        return;
    }

    foreach ($_SESSION['cms_upload_session_paths'] as $index => $path) {
        if ($path === $oldPath) {
            $_SESSION['cms_upload_session_paths'][$index] = $newPath;
            return;
        }
    }
}

/** @return array{path:string,changed:bool,skipped:bool,message:string} */
function cms_reframe_uploaded_image(string $webPath): array
{
    $webPath = trim($webPath);
    $abs = cms_upload_web_to_absolute($webPath);
    if ($abs === null) {
        return [
            'path' => $webPath,
            'changed' => false,
            'skipped' => false,
            'message' => 'فایل یافت نشد',
        ];
    }

    $mime = cms_detect_image_mime($abs);
    if ($mime === 'image/jpeg') {
        return [
            'path' => $webPath,
            'changed' => false,
            'skipped' => true,
            'message' => 'JPEG — مرکز‌چینی اعمال نمی‌شود',
        ];
    }
    if ($mime === 'image/gif' && cms_image_is_animated_gif($abs)) {
        return [
            'path' => $webPath,
            'changed' => false,
            'skipped' => true,
            'message' => 'GIF متحرک — رد شد',
        ];
    }
    if (!in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
        return [
            'path' => $webPath,
            'changed' => false,
            'skipped' => true,
            'message' => 'نوع فایل برای مرکز‌چینی مناسب نیست',
        ];
    }

    $oldReal = realpath($abs) ?: $abs;
    $beforeHash = is_file($abs) ? md5_file($abs) : '';

    try {
        $newAbs = cms_auto_frame_product_image($abs, $mime);
        $newMime = cms_detect_image_mime($newAbs);
        if ($newMime === '') {
            $newMime = $mime;
        }
        $newAbs = cms_optimize_stored_image($newAbs, $newMime);
    } catch (Throwable $e) {
        return [
            'path' => $webPath,
            'changed' => false,
            'skipped' => false,
            'message' => $e->getMessage(),
        ];
    }

    $newReal = realpath($newAbs) ?: $newAbs;
    $afterHash = is_file($newAbs) ? md5_file($newAbs) : '';
    $newWeb = cms_upload_absolute_to_web($newAbs);
    $changed = $oldReal !== $newReal || ($beforeHash !== '' && $afterHash !== '' && $beforeHash !== $afterHash);

    if (!$changed) {
        return [
            'path' => $webPath,
            'changed' => false,
            'skipped' => true,
            'message' => 'قبلاً مرکز‌چین است یا نیازی به تغییر نیست',
        ];
    }

    return [
        'path' => $newWeb,
        'changed' => true,
        'skipped' => false,
        'message' => 'مرکز‌چینی شد',
    ];
}

/** Whether a session upload path can be auto-framed (PNG/WebP/GIF). */
function cms_upload_path_is_framable(string $webPath): bool
{
    $ext = strtolower(pathinfo($webPath, PATHINFO_EXTENSION));
    return in_array($ext, ['png', 'webp', 'gif'], true);
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
 *  @param array{auto_frame?:bool} $options */
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
    // No app-level size cap — images are resized/compressed server-side after upload.
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

    if (!empty($options['auto_frame'])) {
        $dest = cms_auto_frame_product_image($dest, $mime);
        $detected = cms_detect_image_mime($dest);
        if ($detected !== '') {
            $mime = $detected;
        }
    }

    $dest = cms_optimize_stored_image($dest, $mime);

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
