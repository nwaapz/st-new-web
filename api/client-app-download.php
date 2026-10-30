<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cms/bootstrap.php';
require_once dirname(__DIR__) . '/cms/lib/client-app.php';

try {
    $abs = client_app_absolute_path();
    if ($abs === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'فایل اپ موجود نیست';
        exit;
    }

    $filename = 'startech-sales.apk';
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
    error_log('[client-app-download] ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'دانلود اپ در دسترس نیست';
}
