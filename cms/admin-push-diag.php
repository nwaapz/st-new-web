<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cms_require_login();

$apiBase = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$apiBase = preg_replace('#/cms$#', '', $apiBase) ?: '';
$statusUrl = $apiBase . '/api/admin-push-status.php';

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
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

$getBody = '';
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

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تست پوش ادمین</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; max-width: 48rem; }
        pre { background: #f4f4f4; padding: 1rem; overflow: auto; }
        button { padding: 0.5rem 1rem; font-size: 1rem; }
    </style>
</head>
<body>
    <h1>تست پوش FCM (ادمین)</h1>
    <p>وضعیت فعلی (GET):</p>
    <pre><?= htmlspecialchars($getBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
    <form method="post">
        <button type="submit" name="send_test" value="1">ارسال اعلان تست (POST)</button>
    </form>
    <?php if ($flash !== ''): ?>
        <p>نتیجه تست:</p>
        <pre><?= htmlspecialchars($flash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
    <?php endif; ?>
    <p><a href="index.php">بازگشت به CMS</a></p>
</body>
</html>
