<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_home_backgrounds'])) {
    try {
        $salesBg = cms_handle_optional_upload(
            'home_sales_bg',
            cms_setting_get('home_sales_bg', '')
        );
        $awardsBg = cms_handle_optional_upload(
            'home_awards_bg',
            cms_setting_get('home_awards_bg', '')
        );
        $seriesBg = cms_handle_optional_upload(
            'home_series_bg',
            cms_setting_get('home_series_bg', '')
        );

        cms_setting_set('home_sales_bg', $salesBg);
        cms_setting_set('home_sales_cta', trim((string) ($_POST['home_sales_cta'] ?? '')));
        cms_setting_set('home_awards_bg', $awardsBg);
        cms_setting_set('home_series_bg', $seriesBg);
        cms_setting_set('home_series_side_text', trim((string) ($_POST['home_series_side_text'] ?? '')));
        cms_setting_set('home_category_side_text', trim((string) ($_POST['home_category_side_text'] ?? '')));
        cms_setting_set('home_new_products_side_text', trim((string) ($_POST['home_new_products_side_text'] ?? '')));
        cms_flash('پس‌زمینه‌ها و متن‌های صفحه اصلی ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('home-backgrounds.php');
}

$salesBg = cms_setting_get('home_sales_bg', '');
$salesCta = cms_setting_get('home_sales_cta', '');
$awardsBg = cms_setting_get('home_awards_bg', '');
$seriesBg = cms_setting_get('home_series_bg', '');
$seriesSideText = cms_setting_get('home_series_side_text', '');
$categorySideText = cms_setting_get('home_category_side_text', '');
$newProductsSideText = cms_setting_get('home_new_products_side_text', '');

cms_layout_start('پس‌زمینه و متن', cms_current_username(), 'website');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">پس‌زمینه و متن</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">
      تصاویر پس‌زمینه و متن‌های عمودی صفحه اصلی. خالی بگذارید تا مقدار پیش‌فرض سایت استفاده شود.
    </p>
  </div>
</div>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="save_home_backgrounds" value="1">

  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">عاملین فروش</h2>
    <p class="cms-muted" style="margin:.25rem 0 1rem">تصویر داخل قاب و متن دکمه. پیش‌فرض تصویر: <span dir="ltr">/images/bg/startechShop.png</span></p>
    <?php cms_image_field('home_sales_bg', 'پس‌زمینه قاب', $salesBg); ?>
    <label class="cms-field">
      <span class="cms-label">متن دکمه</span>
      <input class="cms-input" name="home_sales_cta" value="<?= cms_h($salesCta) ?>" placeholder="عاملین فروش">
    </label>
  </div>

  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">جوایز</h2>
    <p class="cms-muted" style="margin:.25rem 0 1rem">کف بخش جوایز. پیش‌فرض: <span dir="ltr">/images/bg/medal-floor.png</span></p>
    <?php cms_image_field('home_awards_bg', 'پس‌زمینه کف', $awardsBg); ?>
  </div>

  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">سری محصولات</h2>
    <p class="cms-muted" style="margin:.25rem 0 1rem">پس‌زمینه بخش سری و متن عمودی کنار قاب. پیش‌فرض تصویر: <span dir="ltr">/images/bg/floor1.png</span></p>
    <?php cms_image_field('home_series_bg', 'پس‌زمینه', $seriesBg); ?>
    <label class="cms-field">
      <span class="cms-label">متن عمودی</span>
      <input class="cms-input" name="home_series_side_text" value="<?= cms_h($seriesSideText) ?>" placeholder="{  سری محصولات  }">
    </label>
  </div>

  <div class="cms-panel" style="margin-bottom:1.25rem">
    <h2 style="margin-top:0">متن کناری عمودی</h2>
    <p class="cms-muted" style="margin:.25rem 0 1rem">برچسب‌های عمودی ردیف دسته‌ها و محصولات جدید.</p>
    <label class="cms-field">
      <span class="cms-label">دسته‌بندی محصولات</span>
      <input class="cms-input" name="home_category_side_text" value="<?= cms_h($categorySideText) ?>" placeholder="{   دسته بندی محصولات    }">
    </label>
    <label class="cms-field">
      <span class="cms-label">محصولات جدید</span>
      <input class="cms-input" name="home_new_products_side_text" value="<?= cms_h($newProductsSideText) ?>" placeholder="محصولات جدید">
    </label>
  </div>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره</button>
  </div>
</form>
<?php cms_layout_end(); ?>
