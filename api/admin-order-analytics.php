<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/analytics-orders.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
    $dashboard = analytics_orders_dashboard($pdo, $forceRefresh);

    api_json([
        'ok' => true,
        'dashboard' => $dashboard,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
