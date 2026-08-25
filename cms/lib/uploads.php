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

function cms_unique_upload_name(string $uploadsDir, string $originalName, string $ext): string
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

    $name = $safe . $ext;
    if (!file_exists($uploadsDir . DIRECTORY_SEPARATOR . $name)) {
        return $name;
    }
    return $safe . '-' . bin2hex(random_bytes(3)) . $ext;
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
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('حداکثر حجم تصویر ۵ مگابایت است');
    }

    $uploadsDir = cms_uploads_root();
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
        throw new RuntimeException('ساخت پوشه uploads ممکن نیست — دسترسی نوشتن را بررسی کنید');
    }
    if (!is_writable($uploadsDir)) {
        throw new RuntimeException('پوشه uploads قابل نوشتن نیست');
    }

    $name = cms_unique_upload_name($uploadsDir, (string) ($file['name'] ?? 'image'), $map[$mime]);
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

    return '/uploads/' . str_replace('\\', '/', basename($dest));
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
