<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/admin-mobile-app.php';

cms_require_login();

try {
    $abs = admin_mobile_app_absolute_path();
    if ($abs === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'فایل اپ ادمین موجود نیست';
        exit;
    }

    $filename = 'startech-admin.apk';
    $size = filesize($abs);
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    readfile($abs);
} catch (Throwable $e) {
    error_log('[admin-mobile-app-download] ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'دانلود اپ در دسترس نیست';
}
