<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/melipayamak.php';
require_once dirname(__DIR__) . '/cms/lib/branches.php';
require_once dirname(__DIR__) . '/cms/lib/mechanics.php';

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
    $code = preg_replace('/\D/', '', (string) ($body['code'] ?? '')) ?? '';
    $mode = trim((string) ($body['mode'] ?? ''));
    $isBranchMode = $mode === 'branch';

    if (!site_auth_is_valid_mobile($phone)) {
        api_error('شماره موبایل معتبر نیست', 400);
    }
    if (strlen($code) < 4 || strlen($code) > 8) {
        api_error('کد تأیید نامعتبر است', 400);
    }

    if ($isBranchMode) {
        $branch = branches_find_by_phone($pdo, $phone);
        if ($branch === null) {
            api_error('این شماره در فهرست نمایندگان ثبت نشده است', 403);
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id, code_hash, expires_at, attempts
         FROM site_otp_codes
         WHERE phone = ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    if (!$row) {
        api_error('کد تأیید یافت نشد. دوباره درخواست دهید', 400);
    }

    if ((int) $row['attempts'] >= SITE_OTP_MAX_ATTEMPTS) {
        $pdo->prepare('DELETE FROM site_otp_codes WHERE id = ?')->execute([(int) $row['id']]);
        api_error('تعداد تلاش بیش از حد مجاز است. دوباره کد بگیرید', 429);
    }

    $expiresAt = strtotime((string) $row['expires_at']);
    if ($expiresAt === false || $expiresAt < time()) {
        $pdo->prepare('DELETE FROM site_otp_codes WHERE phone = ?')->execute([$phone]);
        api_error('کد منقضی شده است. دوباره درخواست دهید', 400);
    }

    if (!password_verify($code, (string) $row['code_hash'])) {
        $pdo->prepare('UPDATE site_otp_codes SET attempts = attempts + 1 WHERE id = ?')
            ->execute([(int) $row['id']]);
        api_error('کد تأیید نادرست است', 400);
    }

    $pdo->prepare('DELETE FROM site_otp_codes WHERE phone = ?')->execute([$phone]);

    $find = $pdo->prepare('SELECT id, phone FROM site_users WHERE phone = ? LIMIT 1');
    $find->execute([$phone]);
    $user = $find->fetch();
    if (!$user) {
        $pdo->prepare('INSERT INTO site_users (phone) VALUES (?)')->execute([$phone]);
        $userId = (int) $pdo->lastInsertId();
    } else {
        $userId = (int) $user['id'];
        $pdo->prepare('UPDATE site_users SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$userId]);
    }

    branches_sync_user_branch($pdo, $userId, $phone);

    if ($isBranchMode && branches_for_user($pdo, $userId) === null) {
        api_error('این شماره در فهرست نمایندگان ثبت نشده است', 403);
    }

    site_auth_login($userId, $phone);
    if (!$isBranchMode) {
        site_auth_issue_device_token($pdo, $userId, $phone);
    }

    $payload = branches_auth_user_payload($pdo, $userId, $phone);
    $payload = array_merge($payload, mechanics_auth_user_payload($pdo, $userId));

    api_json([
        'ok' => true,
        'user' => $payload,
    ]);
} catch (Throwable $e) {
    error_log('[auth-otp-verify] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (strpos($msg, 'نمایندگان') !== false) {
        api_error($msg, 403);
    }
    api_error('خطای سرور', 500);
}
