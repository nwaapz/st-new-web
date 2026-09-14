<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/uploads.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = min(100, max(10, (int) ($_GET['page_size'] ?? 40)));

        $items = cms_scan_upload_images();
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $items = array_values(array_filter(
                $items,
                static fn(array $item): bool => str_contains(mb_strtolower((string) ($item['name'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($item['path'] ?? '')), $needle)
            ));
        }

        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $pageSize;
        $pageItems = array_slice($items, $offset, $pageSize);

        api_json([
            'ok' => true,
            'items' => array_map(static fn(array $item): array => [
                'path' => (string) ($item['path'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'size' => (int) ($item['size'] ?? 0),
            ], $pageItems),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
        ]);
    }

    if ($method === 'DELETE') {
        $path = trim((string) ($_GET['path'] ?? ''));
        if ($path === '') {
            api_error('مسیر فایل الزامی است', 400);
        }
        cms_delete_upload_file($path);
        api_json(['ok' => true, 'message' => 'تصویر حذف شد']);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        api_error('فایلی ارسال نشده است', 400);
    }

    $path = cms_store_uploaded_image($_FILES['file']);
    api_json([
        'ok' => true,
        'path' => $path,
        'url' => cms_asset_url($path),
        'message' => 'تصویر آپلود شد',
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-media] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
