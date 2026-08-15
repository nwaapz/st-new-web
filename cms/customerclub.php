<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/page-intros.php';

cms_require_login();

function customerclub_header_width(float $value): int
{
    return (int) max(120, min(720, round($value)));
}

function customerclub_side_text_size(float $value): int
{
    return (int) max(12, min(28, round($value)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_header_image'])) {
    try {
        $existing = cms_setting_get('customerclub_header_image', '');
        $image = cms_handle_optional_upload('header_image', $existing);
        $width = customerclub_header_width((float) ($_POST['header_width'] ?? 330));
        cms_setting_set('customerclub_header_image', $image);
        cms_setting_set('customerclub_header_width', (string) $width);
        cms_flash($image !== '' ? 'تصویر هدر باشگاه مشتریان ذخیره شد' : 'تصویر هدر باشگاه مشتریان حذف شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('customerclub.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_page_intro'])) {
    try {
        cms_page_intro_save(
            'customerclub',
            (string) ($_POST['intro_title'] ?? ''),
            (string) ($_POST['intro_explanation'] ?? '')
        );
        cms_flash('متن هدر باشگاه مشتریان ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('customerclub.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_side_panel'])) {
    try {
        $existing = cms_setting_get('customerclub_side_image', '');
        $image = cms_handle_optional_upload('side_image', $existing);
        $text = trim((string) ($_POST['side_text'] ?? ''));
        $textSize = customerclub_side_text_size((float) ($_POST['side_text_size'] ?? 15));
        cms_setting_set('customerclub_side_image', $image);
        cms_setting_set('customerclub_side_text', $text);
        cms_setting_set('customerclub_side_text_size', (string) $textSize);
        cms_flash('پنل کناری باشگاه مشتریان ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('customerclub.php');
}

$headerImage = cms_setting_get('customerclub_header_image', '');
$headerWidth = customerclub_header_width((float) cms_setting_get('customerclub_header_width', '330'));
$intro = cms_page_intro_stored('customerclub');
$introDefaults = cms_page_intro_defaults()['customerclub'];
$sideImage = cms_setting_get('customerclub_side_image', '');
$sideText = cms_setting_get('customerclub_side_text', '');
$sideTextSize = customerclub_side_text_size((float) cms_setting_get('customerclub_side_text_size', '15'));

cms_layout_start('باشگاه مشتریان', cms_current_username(), 'website');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">باشگاه مشتریان</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">متن، تصویر هدر و پنل کناری صفحه ورود مکانیک‌ها</p>
  </div>
</div>

<form class="cms-panel" method="post" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">متن هدر صفحه ورود</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    عنوان و توضیح بالای صفحه ورود. خالی بگذارید تا متن پیش‌فرض سایت استفاده شود.
  </p>
  <input type="hidden" name="save_page_intro" value="1">
  <label class="cms-field">
    <span class="cms-label">عنوان هدر</span>
    <input class="cms-input" name="intro_title" value="<?= cms_h($intro['title']) ?>" placeholder="<?= cms_h($introDefaults['title']) ?>">
  </label>
  <label class="cms-field">
    <span class="cms-label">متن توضیحی</span>
    <textarea class="cms-textarea" name="intro_explanation" rows="3" placeholder="<?= cms_h($introDefaults['explanation']) ?>"><?= cms_h($intro['explanation']) ?></textarea>
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره متن</button>
  </div>
</form>

<form class="cms-panel" method="post" enctype="multipart/form-data" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">تصویر هدر صفحه ورود</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    کنار عنوان هدر در صفحه ورود نمایش داده می‌شود. برای حذف، مسیر را خالی کنید و ذخیره کنید.
  </p>
  <input type="hidden" name="save_header_image" value="1">
  <?php cms_image_field('header_image', 'تصویر هدر', $headerImage); ?>
  <div class="cms-field">
    <span class="cms-label">اندازه تصویر</span>
    <div class="cms-crop-zoom-row">
      <span>کوچک</span>
      <input type="range" id="customerclub-header-width" name="header_width" min="120" max="720" step="10" value="<?= $headerWidth ?>">
      <span id="customerclub-header-width-value"><?= $headerWidth ?>px</span>
    </div>
    <p class="cms-muted" style="margin:.45rem 0 0">عرض تصویر کنار عنوان در صفحه ورود. نسبت تصویر حفظ می‌شود.</p>
  </div>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره تصویر</button>
  </div>
</form>

<form class="cms-panel" method="post" enctype="multipart/form-data" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">پنل کناری صفحه ورود</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    تصویر و متن زیر آن در سمت راست صفحه ورود (کنار فرم). برای مخفی کردن پنل، تصویر را حذف و متن را خالی بگذارید.
  </p>
  <input type="hidden" name="save_side_panel" value="1">
  <?php cms_image_field('side_image', 'تصویر پنل', $sideImage); ?>
  <label class="cms-field">
    <span class="cms-label">متن زیر تصویر</span>
    <textarea class="cms-textarea" name="side_text" rows="4" placeholder="متن تبلیغ یا توضیح کوتاه…"><?= cms_h($sideText) ?></textarea>
  </label>
  <div class="cms-field">
    <span class="cms-label">اندازه متن</span>
    <div class="cms-crop-zoom-row">
      <span>کوچک</span>
      <input type="range" id="customerclub-side-text-size" name="side_text_size" min="12" max="28" step="1" value="<?= $sideTextSize ?>">
      <span id="customerclub-side-text-size-value"><?= $sideTextSize ?>px</span>
    </div>
  </div>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره پنل کناری</button>
  </div>
</form>
<script>
(function () {
  var range = document.getElementById('customerclub-header-width');
  var label = document.getElementById('customerclub-header-width-value');
  if (range && label) {
    range.addEventListener('input', function () {
      label.textContent = range.value + 'px';
    });
  }
  var sizeRange = document.getElementById('customerclub-side-text-size');
  var sizeLabel = document.getElementById('customerclub-side-text-size-value');
  if (sizeRange && sizeLabel) {
    sizeRange.addEventListener('input', function () {
      sizeLabel.textContent = sizeRange.value + 'px';
    });
  }
})();
</script>
<?php cms_layout_end(); ?>
