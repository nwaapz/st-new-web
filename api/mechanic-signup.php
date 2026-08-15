<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/mechanics.php';
require_once dirname(__DIR__) . '/cms/lib/mechanic-catalog.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    mechanics_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }

    $phoneTaken = mechanics_find_by_phone($pdo, (string) $user['phone']) !== null
        || mechanics_find_by_user($pdo, (int) $user['id']) !== null;

    if ($method === 'GET') {
        api_json([
            'ok' => true,
            'phone_taken' => $phoneTaken,
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    if ($phoneTaken) {
        api_error('این شماره موبایل قبلاً برای یک تعمیرگاه ثبت شده است', 409);
    }

    $body = site_auth_request_json();
    $workshopName = trim((string) ($body['workshop_name'] ?? ''));
    $ownerName = trim((string) ($body['owner_name'] ?? ''));
    $city = trim((string) ($body['city'] ?? ''));
    $services = is_array($body['services'] ?? null) ? $body['services'] : [];

    if ($workshopName === '' || mb_strlen($workshopName) > 191) {
        api_error('نام تعمیرگاه را وارد کنید', 400);
    }
    if ($ownerName === '' || mb_strlen($ownerName) > 191) {
        api_error('نام مکانیک را وارد کنید', 400);
    }
    if ($city === '' || mb_strlen($city) > 191) {
        api_error('شهر را وارد کنید', 400);
    }

    $mechanicId = mechanics_create(
        $pdo,
        (int) $user['id'],
        (string) $user['phone'],
        $workshopName,
        $ownerName,
        $city,
        $services
    );

    api_json(['ok' => true, 'mechanic_id' => $mechanicId]);
} catch (Throwable $e) {
    error_log('[mechanic-signup] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (strpos($msg, 'ثبت شده') !== false) {
        api_error($msg, 409);
    }
    if (strpos($msg, 'وارد کنید') !== false || strpos($msg, 'موبایل') !== false) {
        api_error($msg, 400);
    }
    api_error('خطای سرور', 500);
}
