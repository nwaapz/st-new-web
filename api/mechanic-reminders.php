<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_mechanic.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }
    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $mechanic = mechanic_api_require($pdo);
    $items = mechanic_active_reminders($pdo, $mechanic['id']);

    $filter = trim((string) ($_GET['status'] ?? ''));
    if (in_array($filter, ['red', 'yellow', 'green'], true)) {
        $items = array_values(array_filter($items, fn($r) => $r['status'] === $filter));
    }

    api_json(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    error_log('[mechanic-reminders] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
