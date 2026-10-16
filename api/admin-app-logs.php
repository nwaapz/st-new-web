<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/application-logs.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $admin = admin_auth_require_user($pdo);

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 102400) {
        api_error('حجم رویدادها بیش از حد مجاز است', 413);
    }

    $body = admin_auth_request_json();
    $events = $body['events'] ?? null;
    if (!is_array($events)) {
        api_error('فهرست رویدادها نامعتبر است', 400);
    }
    if (count($events) > APP_LOG_BATCH_MAX) {
        api_error('حداکثر ' . APP_LOG_BATCH_MAX . ' رویداد در هر درخواست', 400);
    }

    try {
        $result = application_logs_ingest_batch(
            [
                'type' => 'admin',
                'id' => (int) $admin['id'],
                'label' => (string) ($admin['username'] ?? ''),
            ],
            $events,
            'admin_app'
        );
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'rate_limited') {
            api_error('تعداد درخواست‌ها بیش از حد مجاز است', 429);
        }
        throw $e;
    }

    api_json(['ok' => true] + $result);
} catch (Throwable $e) {
    error_log('[admin-app-logs] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
