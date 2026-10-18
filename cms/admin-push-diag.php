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
$deliveryMode = admin_push_delivery_mode();

$pdo = cms_pdo();
admin_push_ensure_schema($pdo);
sales_push_ensure_schema($pdo);
$salesTokenCount = (int) $pdo->query('SELECT COUNT(DISTINCT fcm_token) FROM sales_push_tokens')->fetchColumn();
$adminId = (int) cms_current_admin_id();
$clientStatus = admin_push_client_status($pdo, $adminId);

$status = [
    'ok' => true,
    'service_account' => admin_push_service_account_path() !== null,
    'service_account_path' => admin_push_service_account_path(),
    'firebase_project_id' => admin_push_firebase_project_id(),
    'admin_registered_tokens' => count(admin_push_all_tokens($pdo)),
    'admin_tokens' => admin_push_token_summaries($pdo),
    'tray' => $deliveryMode,
    'sales_registered_tokens' => $salesTokenCount,
    'admin_user_id' => $adminId,
    'app_seen_at' => is_array($clientStatus) ? $clientStatus['updated_at'] : null,
    'app_note' => is_array($clientStatus) ? $clientStatus['note'] : null,
    'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
    'canonical_site_base' => $remoteBase,
];
$getBody = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    if ($adminId <= 0) {
        $flash = json_encode([
            'ok' => false,
            'error' => 'شناسه ادمین نامعتبر است',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } else {
        $result = admin_push_send_test($pdo, $adminId, 'تست اعلان', 'اتصال FCM برقرار است');
        $flash = json_encode([
            'ok' => true,
            'queued_phone' => $result['queued'],
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'fcm_tokens' => $result['fcm_tokens'],
            'tray' => $deliveryMode,
            'hint' => 'اپ ادمین را باز نگه دارید؛ تا ۱۰ ثانیه اعلان از سرور می‌آید (بدون گوگل).',
            'last_fcm_error' => admin_push_last_fcm_error(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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
        .warn { background: #ffe8e8; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; }
        a { word-break: break-all; }
    </style>
</head>
<body>
    <h1>تست پوش FCM (ادمین)</h1>
    <p>سرور موبایل (اپ‌ها): <strong><?= htmlspecialchars($remoteBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
    <p>API (اختیاری): <a href="<?= htmlspecialchars($statusUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></p>

    <?php if ($onProduction && $status['tray'] !== 'fcm'): ?>
        <div class="warn">
            <p><strong>فایل‌های PHP روی سرور قدیمی هستند.</strong> در JSON بالا باید <code>tray</code> برابر <code>fcm</code> باشد.</p>
            <p>حداقل این فایل‌ها را آپلود کنید: <code>cms/lib/admin-push.php</code>، <code>cms/admin-push-diag.php</code>، <code>api/admin-push-inbox.php</code>، <code>api/admin-push-register.php</code></p>
        </div>
    <?php endif; ?>

    <?php if ($onProduction): ?>
        <div class="note">
            <p>اپ ادمین <strong>۱.۲-fcm</strong> را نصب کنید، وارد شوید، اعلان را Allow کنید، اپ را به پس‌زمینه ببرید (Home)، بعد تست بزنید.</p>
            <p>بعد از ورود، <code>admin_tokens[].updated_at</code> باید زمان امروز باشد؛ اگر همان ۱۵:۴۰ است، توکن FCM ثبت نشده است.</p>
            <p><code>queued_phone</code> پشتیبان سرور است؛ اعلان اصلی از Firebase با <code>sent</code> می‌آید.</p>
        </div>
    <?php endif; ?>

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
