<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/warranty.php';
require_once dirname(__DIR__) . '/cms/lib/warranty-sms-store.php';
require_once dirname(__DIR__) . '/cms/lib/jalali.php';
require_once dirname(__DIR__) . '/cms/lib/melipayamak.php';

site_auth_prepare_cors();

/**
 * @return array<string, mixed>
 */
function warranty_api_params(): array
{
    $body = site_auth_request_json();
    return array_merge($_GET, $body);
}

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $params = warranty_api_params();
    $action = trim((string) ($params['action'] ?? ''));

    if ($action === 'check') {
        $serial = strtoupper(warranty_normalize_serial((string) ($params['serial'] ?? '')));
        if ($serial === '') {
            api_error('شماره سریال وارد نشده است', 400);
        }

        $kind = warranty_detect_kind($serial);
        if ($kind === 'invalid') {
            api_json(['ok' => true, 'result' => 'invalid', 'message' => 'فرمت سریال وارد شده معتبر نیست.']);
        }
        if ($kind === 'timing_belt') {
            api_json([
                'ok' => true,
                'result' => 'timing_belt',
                'message' => 'برای فعال‌سازی گارانتی تسمه تایم، کد را به صورت S123456 به پشتیبانی پیامک کنید.',
            ]);
        }

        try {
            $smsPdo = warranty_sms_pdo();
        } catch (RuntimeException $e) {
            api_error($e->getMessage(), 503);
        }

        $user = site_auth_current_user($pdo);
        $userPhone = $user !== null ? (string) $user['phone'] : null;
        $status = warranty_sms_check_status($smsPdo, $serial, $userPhone);

        if ($status['status'] === 'not_found') {
            api_json(['ok' => true, 'result' => 'not_found', 'message' => 'کد سریال در سیستم یافت نشد.']);
        }

        if ($status['status'] === 'registered') {
            api_json([
                'ok' => true,
                'result' => 'registered',
                'message' => 'این سریال قبلاً ثبت شده است.',
                'kind' => (string) ($status['kind'] ?? 'old_serial'),
                'phone_masked' => (string) ($status['phone_masked'] ?? ''),
            ]);
        }

        $isLoggedIn = $user !== null;
        $pipeline = (string) ($status['pipeline'] ?? 'client');
        api_json([
            'ok' => true,
            'result' => 'registrable',
            'kind' => (string) ($status['kind'] ?? 'old_serial'),
            'pipeline' => $pipeline,
            'message' => $isLoggedIn
                ? ($pipeline === 'seller'
                    ? 'کد سریال فروشنده معتبر است. نام شهر خود را وارد کنید.'
                    : 'کد سریال معتبر است. فرم زیر را برای ثبت گارانتی تکمیل کنید.')
                : 'کد سریال معتبر است. برای ثبت گارانتی وارد حساب کاربری شوید.',
        ]);
    }

    if ($action === 'register') {
        if ($method !== 'POST') {
            api_error('Method not allowed', 405);
        }

        $user = site_auth_current_user($pdo);
        if ($user === null) {
            api_error('لطفاً ابتدا وارد حساب کاربری شوید', 401);
        }

        $serial = strtoupper(warranty_normalize_serial((string) ($params['serial'] ?? '')));
        $city = trim((string) ($params['city'] ?? ''));
        $km = warranty_normalize_serial((string) ($params['km'] ?? ''));
        $carPlate = trim((string) ($params['car_plate'] ?? ''));

        if ($serial === '') {
            api_error('شماره سریال وارد نشده است', 400);
        }

        try {
            $smsPdo = warranty_sms_pdo();
        } catch (RuntimeException $e) {
            api_error($e->getMessage(), 503);
        }

        try {
            $row = warranty_sms_register($smsPdo, $serial, (string) $user['phone'], [
                'city' => $city,
                'km' => $km,
                'car_plate' => $carPlate,
            ]);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            $code = str_contains($msg, 'قبلاً') ? 409 : 400;
            api_error($msg, $code);
        }

        $dbSerial = (string) ($row['serial'] ?? $serial);
        $isSeller = warranty_sms_is_seller_serial($dbSerial, $row);
        $smsText = 'گارانتی استارتک: ثبت موفقیت‌آمیز. کد سریال: ' . $dbSerial
            . ($city !== '' ? ' | شهر: ' . $city : '');
        if (!$isSeller && $km !== '') {
            $smsText .= ' | کیلومتراژ: ' . $km;
        }
        cms_sms_send((string) $user['phone'], $smsText);

        api_json(['ok' => true, 'message' => 'ثبت گارانتی با موفقیت انجام شد']);
    }

    if ($action === 'my-list') {
        $user = site_auth_current_user($pdo);
        if ($user === null) {
            api_error('لطفاً ابتدا وارد حساب کاربری شوید', 401);
        }

        try {
            $smsPdo = warranty_sms_pdo();
        } catch (RuntimeException $e) {
            api_error($e->getMessage(), 503);
        }

        $items = warranty_sms_list_by_phone($smsPdo, (string) $user['phone']);
        api_json(['ok' => true, 'items' => $items]);
    }

    api_error('عملیات نامعتبر', 400);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 503);
} catch (Throwable $e) {
    error_log('[warranty] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
