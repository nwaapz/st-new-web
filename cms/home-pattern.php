<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/home-pattern.php';

cms_require_login();

$config = home_pattern_load();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_home_pattern'])) {
    try {
        $config = home_pattern_collect_from_post((string) ($config['image'] ?? ''));
        home_pattern_save($config);
        cms_flash('تنظیمات الگوی تکرارشونده ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('home-pattern.php');
}

$presets = home_pattern_preset_images();
$activeImage = (string) ($config['image'] ?? '');

cms_layout_start('الگوی تکرارشونده', cms_current_username(), 'website');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">الگوی تکرارشونده</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">
      الگوی کاشی پشت ردیف دسته‌بندی و محصولات جدید. تغییرات بلافاصله روی سایت اعمال می‌شود (بدون build).
    </p>
  </div>
</div>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="save_home_pattern" value="1">

  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">فعال‌سازی</h2>
    <label class="cms-field" style="flex-direction:row;align-items:center;gap:.75rem">
      <input type="checkbox" name="enabled" value="1" <?= !empty($config['enabled']) ? 'checked' : '' ?>>
      <span class="cms-label" style="margin:0">نمایش الگوی تکرارشونده</span>
    </label>
  </div>

  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">تصویر الگو</h2>
    <p class="cms-muted" style="margin:.25rem 0 1rem">یک تصویر از پیش‌فرض انتخاب کنید یا فایل جدید آپلود کنید.</p>
    <div class="cms-media-grid" style="margin-bottom:1rem">
      <?php foreach ($presets as $preset): ?>
        <?php $checked = $activeImage === $preset; ?>
        <label class="cms-media-card" style="cursor:pointer;border:2px solid <?= $checked ? '#c0392b' : 'transparent' ?>">
          <input type="radio" name="image_preset" value="<?= cms_h($preset) ?>" <?= $checked ? 'checked' : '' ?> style="position:absolute;opacity:0;pointer-events:none">
          <img src="<?= cms_h(cms_asset_url($preset)) ?>" alt="" loading="lazy" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px">
          <span class="cms-muted" dir="ltr" style="font-size:.7rem;display:block;margin-top:.35rem;word-break:break-all"><?= cms_h($preset) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php cms_image_field('image', 'آپلود سفارشی', $activeImage); ?>
  </div>

  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">چیدمان</h2>
    <div class="cms-grid-2">
      <label class="cms-field">
        <span class="cms-label">اندازه کاشی (px)</span>
        <input class="cms-input" type="number" name="tile_size" min="24" max="400" value="<?= cms_h((string) ($config['tile_size'] ?? 32)) ?>">
      </label>
      <label class="cms-field">
        <span class="cms-label">تعداد ستون (۰ = اندازه کاشی)</span>
        <input class="cms-input" type="number" name="columns" min="0" max="24" value="<?= cms_h((string) ($config['columns'] ?? 0)) ?>">
      </label>
      <label class="cms-field">
        <span class="cms-label">فاصله (px)</span>
        <input class="cms-input" type="number" name="gap" min="0" max="80" value="<?= cms_h((string) ($config['gap'] ?? 0)) ?>">
      </label>
      <label class="cms-field">
        <span class="cms-label">چرخش (درجه)</span>
        <input class="cms-input" type="number" name="rotation" min="0" max="360" value="<?= cms_h((string) ($config['rotation'] ?? 0)) ?>">
      </label>
      <label class="cms-field">
        <span class="cms-label">جابجایی آجری ردیف‌های فرد (%)</span>
        <input class="cms-input" type="number" name="column_offset" min="0" max="50" value="<?= cms_h((string) ($config['column_offset'] ?? 0)) ?>">
      </label>
    </div>
  </div>

  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">روکش رنگی</h2>
    <div class="cms-grid-2">
      <label class="cms-field">
        <span class="cms-label">رنگ روکش</span>
        <input class="cms-input" type="color" name="overlay_color" value="<?= cms_h((string) ($config['overlay_color'] ?? '#d42121')) ?>">
      </label>
      <label class="cms-field">
        <span class="cms-label">شفافیت روکش (۰–۱۰۰)</span>
        <input class="cms-input" type="number" name="overlay_opacity" min="0" max="100" value="<?= cms_h((string) ($config['overlay_opacity'] ?? 0)) ?>">
      </label>
    </div>
  </div>

  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">هایلایت تصادفی</h2>
    <label class="cms-field" style="flex-direction:row;align-items:center;gap:.75rem;margin-bottom:1rem">
      <input type="checkbox" name="highlight_enabled" value="1" <?= !empty($config['highlight_enabled']) ? 'checked' : '' ?>>
      <span class="cms-label" style="margin:0">فعال‌سازی هایلایت تصادفی کاشی‌ها</span>
    </label>
    <div class="cms-grid-2">
      <label class="cms-field">
        <span class="cms-label">رنگ هایلایت</span>
        <input class="cms-input" type="color" name="highlight_color" value="<?= cms_h((string) ($config['highlight_color'] ?? '#fdbe4c')) ?>">
      </label>
      <label class="cms-field">
        <span class="cms-label">شفافیت هایلایت (۰–۱۰۰)</span>
        <input class="cms-input" type="number" name="highlight_opacity" min="0" max="100" value="<?= cms_h((string) ($config['highlight_opacity'] ?? 85)) ?>">
      </label>
      <label class="cms-field">
        <span class="cms-label">مدت نگه‌داری (ثانیه)</span>
        <input class="cms-input" type="number" name="highlight_duration" min="1.5" max="12" step="0.1" value="<?= cms_h((string) ($config['highlight_duration'] ?? 4)) ?>">
      </label>
    </div>
  </div>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره</button>
  </div>
</form>
<?php cms_layout_end(); ?>
