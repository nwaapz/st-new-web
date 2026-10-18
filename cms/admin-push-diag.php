<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/site-base.php';
require_once __DIR__ . '/lib/admin-push.php';
require_once __DIR__ . '/lib/sales-push.php';

cms_require_login();

$remoteBase = cms_public_site_base();
$statusUrl = $remoteBase . '/api/admin-push-status.php';
$diagUrl = $remoteBase . '/cms/admin-push-diag.php';
$loginUrl = $remoteBase . '/cms/login.php';
$onProduction = cms_is_production_host();

$pdo = cms_pdo();
admin_push_ensure_schema($pdo);
sales_push_ensure_schema($pdo);
$salesTokenCount = (int) $pdo->query('SELECT COUNT(DISTINCT fcm_token) FROM sales_push_tokens')->fetchColumn();

$status = [
    'ok' => true,
    'service_account' => admin_push_service_account_path() !== null,
    'service_account_path' => admin_push_service_account_path(),
    'firebase_project_id' => admin_push_firebase_project_id(),
    'admin_registered_tokens' => count(admin_push_all_tokens($pdo)),
    'sales_registered_tokens' => $salesTokenCount,
    'admin_user_id' => cms_current_admin_id(),
    'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
    'canonical_site_base' => $remoteBase,
];
$getBody = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    $tokens = admin_push_all_tokens($pdo);
    if ($tokens === []) {
        $flash = json_encode([
            'ok' => false,
            'error' => 'هیچ توکن FCM ثبت نشده — اپ ادمین را باز کنید و وارد شوید',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } elseif (admin_push_service_account_path() === null) {
        $flash = json_encode([
            'ok' => false,
            'error' => 'firebase-service-account.json روی سرور یافت نشد',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } else {
        $data = [
            'order_id' => '0',
            'type' => 'test',
            'public_code' => '',
            'activity' => 'test',
        ];
        $sent = 0;
        $failed = 0;
        foreach ($tokens as $token) {
            if (admin_push_send_to_token($token, 'تست اعلان', 'اتصال FCM برقرار است', $data, $pdo)) {
                $sent++;
            } else {
                $failed++;
            }
        }
        if ($sent === 0) {
            $flash = json_encode([
                'ok' => false,
                'error' => 'ارسال FCM ناموفق بود — ' . (admin_push_last_fcm_error() ?? 'error log سرور'),
                'failed' => $failed,
                'tokens' => count($tokens),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } else {
            $flash = json_encode([
                'ok' => true,
                'sent' => $sent,
                'failed' => $failed,
                'tokens' => count($tokens),
                'last_fcm_error' => admin_push_last_fcm_error(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تست پوش ادمین</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; max-width: 48rem; }
        pre { background: #f4f4f4; padding: 1rem; overflow: auto; direction: ltr; text-align: left; }
        button { padding: 0.5rem 1rem; font-size: 1rem; }
        .note { background: #fff8e6; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; }
        a { word-break: break-all; }
    </style>
</head>
<body>
    <h1>تست پوش FCM (ادمین)</h1>
    <p>سرور موبایل (اپ‌ها): <strong><?= htmlspecialchars($remoteBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
    <p>API (اختیاری): <a href="<?= htmlspecialchars($statusUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></p>

    <?php if (!$onProduction): ?>
        <div class="note">
            <p>این نصب localhost است؛ اعداد زیر از <strong>دیتابیس محلی XAMPP</strong> هستند، نه startechgroup.ir.</p>
            <p>تست واقعی پوش: <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ورود CMS</a> →
                <a href="<?= htmlspecialchars($diagUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">diag روی سرور اصلی</a></p>
        </div>
    <?php endif; ?>

    <p>وضعیت (همین سرور / دیتابیس):</p>
    <pre><?= htmlspecialchars($getBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
    <form method="post">
        <button type="submit" name="send_test" value="1">ارسال اعلان تست</button>
    </form>
    <?php if ($flash !== ''): ?>
        <p>نتیجه تست:</p>
        <pre><?= htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
    <?php endif; ?>

    <p><a href="index.php">بازگشت به CMS</a></p>
</body>
</html>
