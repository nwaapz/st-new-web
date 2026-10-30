<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/client-app.php';
require_once __DIR__ . '/lib/admin-audit.php';

cms_require_login();
$pdo = cms_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $existing = client_app_stored_path();
        $clear = isset($_POST['clear_apk']) && (string) $_POST['clear_apk'] === '1';
        $version = trim((string) ($_POST['version'] ?? ''));
        if (function_exists('mb_substr')) {
            $version = mb_substr($version, 0, 32);
        } else {
            $version = substr($version, 0, 32);
        }

        $file = $_FILES['apk_file'] ?? null;
        $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($clear && !$hasFile) {
            if ($existing !== '') {
                client_app_delete_file($existing);
            }
            client_app_save('', $version);
            cms_audit_content($pdo, 'اپ فروش');
            cms_flash('فایل اپ فروش حذف شد — دکمه دانلود در سایت نمایش داده نمی‌شود');
            cms_redirect('client-app.php');
        }

        if ($hasFile) {
            $next = cms_store_uploaded_apk($file);
            if ($existing !== '' && $existing !== $next) {
                client_app_delete_file($existing);
            }
            client_app_save($next, $version);
            cms_audit_content($pdo, 'اپ فروش');
            cms_flash('اپ فروش ذخیره شد');
        } else {
            client_app_save($existing, $version);
            cms_audit_content($pdo, 'اپ فروش');
            cms_flash('نسخه اپ فروش ذخیره شد');
        }
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('client-app.php');
}

$apkPath = client_app_stored_path();
$apkReady = $apkPath !== '' && client_app_absolute_path($apkPath) !== null;
$version = client_app_stored_version();
$uploadMax = (string) ini_get('upload_max_filesize');
$postMax = (string) ini_get('post_max_size');

cms_layout_start('اپ فروش', cms_current_username(), 'website');
?>
<h1 style="margin-top:0">اپ فروش</h1>
<p class="cms-muted">
  فایل APK اپ فروش را آپلود کنید تا دکمه دانلود در پاورقی سایت و پرتال نمایندگان نمایش داده شود.
  اگر فایلی ذخیره نشده باشد، دکمه دانلود پنهان می‌ماند.
</p>

<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2 style="margin-top:0">فایل Android</h2>
  <?php if ($apkReady): ?>
    <p class="cms-muted" style="margin:.25rem 0 1rem">
      فایل فعلی: <code dir="ltr"><?= cms_h(basename($apkPath)) ?></code>
    </p>
  <?php else: ?>
    <p class="cms-muted" style="margin:.25rem 0 1rem">هنوز فایلی آپلود نشده است.</p>
  <?php endif; ?>

  <div class="cms-field">
    <label class="cms-label" for="apk_file">آپلود APK</label>
    <input id="apk_file" class="cms-input" type="file" name="apk_file" accept=".apk,application/vnd.android.package-archive">
    <p class="cms-muted" style="margin:.45rem 0 0">
      حداکثر حدود ۱۰۰ مگابایت. حد فعلی PHP:
      upload_max_filesize=<?= cms_h($uploadMax) ?>
      و post_max_size=<?= cms_h($postMax) ?>
      — اگر آپلود رد شد این دو مقدار را در cPanel افزایش دهید.
    </p>
  </div>

  <div class="cms-field">
    <label class="cms-label" for="client-app-version">نسخه (اختیاری)</label>
    <input
      id="client-app-version"
      class="cms-input"
      type="text"
      name="version"
      value="<?= cms_h($version) ?>"
      dir="ltr"
      maxlength="32"
      placeholder="1.4.0"
    >
  </div>

  <?php if ($apkReady): ?>
    <label class="cms-check" style="display:flex;gap:.5rem;align-items:center;margin:1rem 0">
      <input type="checkbox" name="clear_apk" value="1">
      حذف فایل فعلی
    </label>
  <?php endif; ?>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره</button>
  </div>
</form>
<?php cms_layout_end(); ?>
