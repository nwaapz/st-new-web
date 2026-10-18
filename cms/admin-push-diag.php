<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/site-base.php';

cms_require_login();

$remoteBase = cms_public_site_base();
$statusUrl = $remoteBase . '/api/admin-push-status.php';
$diagUrl = $remoteBase . '/cms/admin-push-diag.php';
$loginUrl = $remoteBase . '/cms/login.php';
$onProduction = cms_is_production_host();

$flash = '';
$getBody = '';

if ($onProduction && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    $ch = curl_init($statusUrl);
    if ($ch !== false) {
        $cookie = session_name() . '=' . session_id();
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Cookie: ' . $cookie],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $flash = 'POST HTTP ' . $code . ': ' . (is_string($body) ? $body : '');
    } else {
        $flash = 'curl_init failed';
    }
}

if ($onProduction) {
    $ch = curl_init($statusUrl);
    if ($ch !== false) {
        $cookie = session_name() . '=' . session_id();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Cookie: ' . $cookie],
            CURLOPT_TIMEOUT => 15,
        ]);
        $getBody = (string) curl_exec($ch);
        curl_close($ch);
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
    <p>سرور تست: <strong><?= htmlspecialchars($remoteBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
    <p>API: <a href="<?= htmlspecialchars($statusUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></p>

    <?php if (!$onProduction): ?>
        <div class="note">
            <p>این صفحه روی localhost است؛ پوش و توکن‌های FCM روی <strong>سرور اصلی</strong> ثبت می‌شوند.</p>
            <p>۱. <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ورود به CMS روی startechgroup.ir</a></p>
            <p>۲. <a href="<?= htmlspecialchars($diagUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">باز کردن همین صفحه روی سرور اصلی</a></p>
        </div>
    <?php else: ?>
        <p>وضعیت فعلی (GET):</p>
        <pre><?= htmlspecialchars($getBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
        <form method="post">
            <button type="submit" name="send_test" value="1">ارسال اعلان تست (POST)</button>
        </form>
        <?php if ($flash !== ''): ?>
            <p>نتیجه تست:</p>
            <pre><?= htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
        <?php endif; ?>
    <?php endif; ?>

    <p><a href="index.php">بازگشت به CMS</a></p>
</body>
</html>
