<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/price-import.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    cms_ensure_product_car_models_schema($pdo);
    cms_ensure_product_categories_schema($pdo);
    price_import_ensure_schema($pdo);
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    if (!isset($_FILES['price_file']) || !is_array($_FILES['price_file'])) {
        api_error('فایل انتخاب نشده است', 400);
    }
    if ((int) ($_FILES['price_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        api_error('خطا در آپلود فایل', 400);
    }

    $tmp = (string) ($_FILES['price_file']['tmp_name'] ?? '');
    $originalName = (string) ($_FILES['price_file']['name'] ?? 'import.xlsx');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) {
        api_error('فقط .xlsx یا .csv پشتیبانی می‌شود', 400);
    }

    $stored = price_import_temp_dir() . DIRECTORY_SEPARATOR . 'import-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $stored)) {
        api_error('ذخیره فایل موقت ناموفق بود', 500);
    }

    try {
        $parsed = price_import_parse_file($stored, $ext);
        if ($parsed === []) {
            api_error('هیچ ردیف محصولی در فایل یافت نشد', 400);
        }

        $result = price_import_apply_prices_only($pdo, $parsed);
        api_json([
            'ok' => true,
            'source_name' => $originalName,
            'total_rows' => $result['total_rows'],
            'updated' => $result['updated'],
            'skipped_count' => count($result['skipped']),
            'skipped' => $result['skipped'],
            'message' => sprintf(
                '%d ردیف خوانده شد — %d قیمت به‌روز شد — %d ردیف به‌روز نشد',
                $result['total_rows'],
                $result['updated'],
                count($result['skipped'])
            ),
        ]);
    } finally {
        if (is_file($stored)) {
            @unlink($stored);
        }
    }
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-price-import] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
