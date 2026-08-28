<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/uploads.php';

cms_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prefix'])) {
    try {
        $prefix = cms_upload_session_set_prefix((string) ($_POST['prefix'] ?? ''));
        cms_flash($prefix !== ''
            ? 'پیشوند نشست تنظیم شد: ' . $prefix
            : 'پیشوند نشست پاک شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('media-upload.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_session'])) {
    cms_upload_session_clear_paths();
    cms_flash('فهرست تصاویر این نشست پاک شد (فایل‌ها روی سرور باقی می‌مانند)');
    cms_redirect('media-upload.php');
}

$sessionPrefix = cms_upload_session_prefix();
$sessionPaths = cms_upload_session_paths();

cms_layout_start('آپلود تصاویر', cms_current_username(), 'shop');
?>
<h1 style="margin-top:0">آپلود گروهی تصاویر</h1>
<p class="cms-muted">
  چند تصویر را یکجا آپلود کنید. با تنظیم <strong>پیشوند نشست</strong>، نام فایل‌ها به شکل
  <code dir="ltr">prefix-name.jpg</code> ذخیره می‌شود و در «انتخاب از سرور» محصولات و دسته‌بندی‌ها در دسترس است.
</p>

<form class="cms-panel" method="post" style="margin-bottom:1.25rem" data-cms-no-upload-progress="1">
  <h2 style="margin-top:0">پیشوند نام فایل (نشست)</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    مثال: <code dir="ltr">st-1404</code> → <code dir="ltr">st-1404-product.jpg</code>.
    تا پایان نشست CMS یا تغییر پیشوند، همه آپلودها (از جمله تک‌تایی در محصولات) از این پیشوند استفاده می‌کنند.
  </p>
  <input type="hidden" name="save_prefix" value="1">
  <label class="cms-field">
    <span class="cms-label">پیشوند</span>
    <input class="cms-input" type="text" name="prefix" value="<?= cms_h($sessionPrefix) ?>" dir="ltr" placeholder="مثال: st-batch-01" maxlength="40" autocomplete="off">
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره پیشوند</button>
  </div>
</form>

<section class="cms-panel cms-bulk-upload" id="cms-bulk-upload">
  <h2 style="margin-top:0">آپلود تصاویر</h2>
  <p class="cms-muted" id="cms-bulk-upload-prefix-note">
    <?php if ($sessionPrefix !== ''): ?>
      پیشوند فعال: <code dir="ltr"><?= cms_h($sessionPrefix) ?></code>
    <?php else: ?>
      بدون پیشوند — نام فایل از نام اصلی گرفته می‌شود.
    <?php endif; ?>
  </p>

  <div class="cms-bulk-upload__drop" id="cms-bulk-drop">
    <input id="cms-bulk-files" class="cms-file-input" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
    <label class="cms-file-pick cms-file-pick--lg" for="cms-bulk-files">انتخاب چند تصویر</label>
    <p class="cms-muted">JPEG، PNG، WebP یا GIF — می‌توانید چند فایل را یکجا انتخاب کنید.</p>
  </div>

  <div class="cms-bulk-upload__progress" id="cms-bulk-progress" hidden>
    <div class="cms-upload-progress__track cms-upload-progress__track--lg">
      <span class="cms-upload-progress__bar" id="cms-bulk-progress-bar"></span>
    </div>
    <p class="cms-upload-overlay__text" id="cms-bulk-progress-text">در حال آپلود…</p>
  </div>

  <ul class="cms-bulk-upload__log" id="cms-bulk-log" aria-live="polite"></ul>

  <div class="cms-bulk-upload__session">
    <div class="cms-bulk-upload__session-head">
      <h3>تصاویر این نشست <span class="cms-badge" id="cms-bulk-session-count"><?= count($sessionPaths) ?></span></h3>
      <?php if ($sessionPaths !== []): ?>
        <form method="post" data-cms-no-upload-progress="1">
          <input type="hidden" name="clear_session" value="1">
          <button type="submit" class="cms-btn cms-btn--ghost">پاک کردن فهرست نشست</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="cms-media-grid" id="cms-bulk-session-grid">
      <?php if ($sessionPaths === []): ?>
        <p class="cms-muted" id="cms-bulk-session-empty">هنوز تصویری در این نشست آپلود نشده.</p>
      <?php else: ?>
        <?php foreach (array_reverse($sessionPaths) as $path): ?>
          <?php
          $url = cms_asset_url($path);
          $name = basename($path);
          ?>
          <div class="cms-media-item cms-media-item--static" title="<?= cms_h($path) ?>" data-session-path="<?= cms_h($path) ?>">
            <img src="<?= cms_h($url) ?>" alt="">
            <span dir="ltr"><?= cms_h($name) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<script src="assets/cms-media-upload.js?v=3"></script>
<?php
cms_layout_end();
