<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cms_session_start();
if (!empty($_SESSION['cms_user_id'])) {
    cms_redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    try {
        if ($username === '' || $password === '') {
            $error = 'نام کاربری و رمز عبور الزامی است';
        } elseif (cms_attempt_login($username, $password)) {
            cms_redirect('index.php');
        } else {
            $error = 'نام کاربری یا رمز عبور اشتباه است';
        }
    } catch (Throwable $e) {
        $error = 'خطای دیتابیس — config.local.php و schema را بررسی کنید';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title>ورود | StarTech CMS</title>
  <link rel="stylesheet" href="assets/cms.css?v=2">
</head>
<body>
  <div class="cms-shell">
  <div class="cms-login">
    <form class="cms-card" method="post" action="">
      <h1>ورود به CMS</h1>
      <p class="cms-muted">فقط مدیر مجاز است.</p>
      <label class="cms-field">
        <span class="cms-label">نام کاربری</span>
        <input class="cms-input" name="username" required autocomplete="username" dir="ltr">
      </label>
      <label class="cms-field">
        <span class="cms-label">رمز عبور</span>
        <input class="cms-input" type="password" name="password" required autocomplete="current-password" dir="ltr">
      </label>
      <?php if ($error !== ''): ?>
        <p class="cms-error"><?= cms_h($error) ?></p>
      <?php endif; ?>
      <button class="cms-btn" type="submit">ورود</button>
    </form>
  </div>
  </div>
</body>
</html>
