<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/page-intros.php';

cms_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_page_intro'])) {
    try {
        cms_page_intro_save(
            'warranty',
            (string) ($_POST['intro_title'] ?? ''),
            (string) ($_POST['intro_explanation'] ?? '')
        );
        cms_flash('متن هدر صفحه گارانتی ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('warranty.php');
}

$intro = cms_page_intro_stored('warranty');
$introDefaults = cms_page_intro_defaults()['warranty'];

cms_layout_start('گارانتی', cms_current_username(), 'website');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">گارانتی</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">متن هدر صفحه عمومی خدمات پس از فروش</p>
  </div>
</div>

<form class="cms-panel" method="post">
  <h2 style="margin-top:0">متن صفحه گارانتی</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    عنوان و توضیح هدر صفحه عمومی گارانتی. خالی بگذارید تا متن پیش‌فرض سایت استفاده شود.
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
<?php cms_layout_end(); ?>
