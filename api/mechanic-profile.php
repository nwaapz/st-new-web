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
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $mechanic = mechanic_api_require($pdo);

    if ($method === 'GET') {
        api_json([
            'ok' => true,
            'mechanic' => [
                'id' => $mechanic['id'],
                'workshop_name' => $mechanic['workshop_name'],
                'owner_name' => $mechanic['owner_name'],
                'city' => $mechanic['city'],
                'phone' => $mechanic['phone'],
            ],
            'active_services' => mechanics_active_services($pdo, $mechanic['id']),
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = mechanic_api_request_json();
    $workshopName = trim((string) ($body['workshop_name'] ?? $mechanic['workshop_name']));
    $ownerName = trim((string) ($body['owner_name'] ?? $mechanic['owner_name']));
    $city = trim((string) ($body['city'] ?? $mechanic['city']));

    if ($workshopName === '' || mb_strlen($workshopName) > 191) {
        api_error('نام تعمیرگاه را وارد کنید', 400);
    }
    if ($ownerName === '' || mb_strlen($ownerName) > 191) {
        api_error('نام مکانیک را وارد کنید', 400);
    }
    if ($city === '' || mb_strlen($city) > 191) {
        api_error('شهر را وارد کنید', 400);
    }

    mechanics_update_profile($pdo, $mechanic['id'], $workshopName, $ownerName, $city);

    if (isset($body['services']) && is_array($body['services'])) {
        mechanics_set_active_services($pdo, $mechanic['id'], $body['services']);
    }

    api_json([
        'ok' => true,
        'active_services' => mechanics_active_services($pdo, $mechanic['id']),
    ]);
} catch (Throwable $e) {
    error_log('[mechanic-profile] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (strpos($msg, 'وارد کنید') !== false) {
        api_error($msg, 400);
    }
    api_error('خطای سرور', 500);
}
