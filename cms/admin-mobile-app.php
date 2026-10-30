<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/admin-mobile-app.php';
require_once __DIR__ . '/lib/admin-audit.php';

cms_require_login();
$pdo = cms_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $existing = admin_mobile_app_stored_path();
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
                admin_mobile_app_delete_file($existing);
            }
            admin_mobile_app_save('', $version);
            cms_audit_content($pdo, 'اپ ادمین');
            cms_flash('فایل اپ ادمین حذف شد');
            cms_redirect('admin-mobile-app.php');
        }

        if ($hasFile) {
            $next = cms_store_uploaded_apk($file, 'apps/admin');
            if ($existing !== '' && $existing !== $next) {
                admin_mobile_app_delete_file($existing);
            }
            admin_mobile_app_save($next, $version);
            cms_audit_content($pdo, 'اپ ادمین');
            cms_flash('اپ ادمین ذخیره شد');
        } else {
            admin_mobile_app_save($existing, $version);
            cms_audit_content($pdo, 'اپ ادمین');
            cms_flash('نسخه اپ ادمین ذخیره شد');
        }
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('admin-mobile-app.php');
}

$apkPath = admin_mobile_app_stored_path();
$apkReady = $apkPath !== '' && admin_mobile_app_absolute_path($apkPath) !== null;
$version = admin_mobile_app_stored_version();
$uploadMax = (string) ini_get('upload_max_filesize');
$postMax = (string) ini_get('post_max_size');

cms_layout_start('اپ ادمین', cms_current_username(), 'advanced');
?>
<h1 style="margin-top:0">اپ ادمین (Android)</h1>
<p class="cms-muted">
  APK اپ پرتال ادمین را اینجا آپلود کنید. دانلود فقط برای کاربران واردشده به CMS است — در سایت عمومی نمایش داده نمی‌شود.
</p>

<?php if ($apkReady): ?>
  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">دانلود</h2>
    <p class="cms-muted" style="margin:.25rem 0 1rem">
      فایل:
      <code dir="ltr"><?= cms_h(basename($apkPath)) ?></code>
      <?php if ($version !== ''): ?>
        — نسخه <span dir="ltr"><?= cms_h($version) ?></span>
      <?php endif; ?>
    </p>
    <div class="cms-btn-row">
      <a class="cms-btn" href="admin-mobile-app-download.php">دانلود APK</a>
    </div>
  </div>
<?php endif; ?>

<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2 style="margin-top:0">آپلود / ویرایش</h2>
  <?php if (!$apkReady): ?>
    <p class="cms-muted" style="margin:.25rem 0 1rem">هنوز فایلی آپلود نشده است.</p>
  <?php endif; ?>

  <div class="cms-field">
    <label class="cms-label" for="apk_file">آپلود APK</label>
    <input id="apk_file" class="cms-input" type="file" name="apk_file" accept=".apk,application/vnd.android.package-archive">
    <p class="cms-muted" style="margin:.45rem 0 0">
      حداکثر حدود ۱۰۰ مگابایت. حد فعلی PHP:
      upload_max_filesize=<?= cms_h($uploadMax) ?>
      و post_max_size=<?= cms_h($postMax) ?>
    </p>
  </div>

  <div class="cms-field">
    <label class="cms-label" for="admin-mobile-app-version">نسخه (اختیاری)</label>
    <input
      id="admin-mobile-app-version"
      class="cms-input"
      type="text"
      name="version"
      value="<?= cms_h($version) ?>"
      dir="ltr"
      maxlength="32"
      placeholder="1.0.0"
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
