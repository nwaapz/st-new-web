<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/melipayamak.php';
require_once dirname(__DIR__) . '/cms/lib/branches.php';

const SITE_OTP_TTL_SECONDS = 300;
const SITE_OTP_RESEND_SECONDS = 60;
const SITE_OTP_MAX_ATTEMPTS = 5;

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    site_auth_session_start();
    $body = site_auth_request_json();
    $phone = cms_sms_normalize_phone((string) ($body['phone'] ?? ''));
    $mode = trim((string) ($body['mode'] ?? ''));
    $isBranchMode = $mode === 'branch';

    if (!site_auth_is_valid_mobile($phone)) {
        api_error('شماره موبایل معتبر نیست (مثال: ۰۹۱۲۱۲۳۴۵۶۷)', 400);
    }

    if ($isBranchMode) {
        $branch = branches_find_by_phone($pdo, $phone);
        if ($branch === null) {
            api_error('این شماره در فهرست نمایندگان ثبت نشده است', 403);
        }
    } else {
        // Trusted device + matching phone → login without SMS (customers only).
        $deviceUser = site_auth_lookup_device($pdo, $phone);
        if ($deviceUser !== null) {
            branches_sync_user_branch($pdo, $deviceUser['id'], $deviceUser['phone']);
            site_auth_login($deviceUser['id'], $deviceUser['phone']);
            api_json([
                'ok' => true,
                'logged_in' => true,
                'user' => branches_auth_user_payload($pdo, $deviceUser['id'], $deviceUser['phone']),
            ]);
        }
    }

    // Rate limit: one active send window per phone.
    $recent = $pdo->prepare(
        'SELECT id, created_at FROM site_otp_codes
         WHERE phone = ? AND created_at > (NOW() - INTERVAL ? SECOND)
         ORDER BY id DESC LIMIT 1'
    );
    $recent->execute([$phone, SITE_OTP_RESEND_SECONDS]);
    if ($recent->fetch()) {
        api_error('لطفاً کمی صبر کنید و دوباره درخواست کد دهید', 429);
    }

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    if ($hash === false) {
        api_error('خطا در ساخت کد تأیید', 500);
    }

    $expires = (new DateTimeImmutable('now'))
        ->modify('+' . SITE_OTP_TTL_SECONDS . ' seconds')
        ->format('Y-m-d H:i:s');

    $pdo->prepare('DELETE FROM site_otp_codes WHERE phone = ?')->execute([$phone]);

    $ins = $pdo->prepare(
        'INSERT INTO site_otp_codes (phone, code_hash, expires_at, attempts)
         VALUES (?, ?, ?, 0)'
    );
    $ins->execute([$phone, $hash, $expires]);

    $message = $isBranchMode
        ? 'کد ورود نماینده استارتک: ' . $code
        : 'کد ورود استارتک: ' . $code;
    $send = cms_sms_send($phone, $message);
    if (!$send['ok']) {
        $pdo->prepare('DELETE FROM site_otp_codes WHERE phone = ?')->execute([$phone]);
        api_error($send['error'] ?? 'ارسال پیامک ناموفق بود', 502);
    }

    api_json([
        'ok' => true,
        'logged_in' => false,
        'phone' => $phone,
        'expires_in' => SITE_OTP_TTL_SECONDS,
        'resend_in' => SITE_OTP_RESEND_SECONDS,
    ]);
} catch (Throwable $e) {
    error_log('[auth-otp-send] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
