<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/site-logo.php';
require_once __DIR__ . '/lib/admin-audit.php';

cms_require_login();
$pdo = cms_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $existing = site_logo_stored_path();
        $image = cms_handle_optional_upload('logo_image', $existing);
        site_logo_save($image);
        cms_audit_content($pdo, 'لوگوی سایت');
        cms_flash($image !== '' ? 'لوگوی سایت ذخیره شد' : 'لوگوی سایت حذف شد — پیش‌فرض استفاده می‌شود');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('site-logo.php');
}

$logoImage = site_logo_stored_path();
$effectiveLogo = site_logo_load();

cms_layout_start('لوگوی سایت', cms_current_username(), 'website');
?>
<h1 style="margin-top:0">لوگوی سایت</h1>
<p class="cms-muted">
  تصویر لوگو در هدر و پاورقی سایت نمایش داده می‌شود.
  موقعیت، اندازه و روکش لوگو در
  <a href="<?= cms_h(rtrim(cms_site_base(), '/') . '/font-lab/') ?>" style="color:#e8d4b0;text-decoration:underline">Font Lab</a>
  تنظیم می‌شود.
</p>

<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2 style="margin-top:0">تصویر لوگو</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    برای حذف لوگوی سفارشی، مسیر را خالی کنید و ذخیره کنید —
    <code><?= cms_h(site_logo_default_path()) ?></code>
    استفاده می‌شود.
  </p>
  <?php cms_image_field('logo_image', 'لوگو', $logoImage); ?>
  <?php if ($logoImage === ''): ?>
    <p class="cms-muted" style="margin:.5rem 0 0">
      پیش‌نمایش پیش‌فرض:
      <img
        src="<?= cms_h(cms_asset_url(site_logo_default_path())) ?>"
        alt=""
        style="display:inline-block;max-height:48px;vertical-align:middle;margin-inline-start:.5rem"
      >
    </p>
  <?php else: ?>
    <p class="cms-muted" style="margin:.5rem 0 0">مسیر فعال: <code dir="ltr"><?= cms_h($effectiveLogo) ?></code></p>
  <?php endif; ?>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره لوگو</button>
  </div>
</form>
<?php cms_layout_end(); ?>
