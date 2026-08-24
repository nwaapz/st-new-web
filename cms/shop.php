<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/page-intros.php';

cms_require_login();
$pdo = cms_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_price_mode'])) {
    $enabled = isset($_POST['call_for_price']) ? '1' : '0';
    try {
        cms_setting_set('call_for_price', $enabled);
        cms_flash($enabled === '1'
            ? 'قیمت‌ها به «تماس برای قیمت» تغییر کرد'
            : 'نمایش قیمت واقعی فعال شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('shop.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_header_image'])) {
    try {
        $existing = cms_setting_get('shop_header_image', '');
        $image = cms_handle_optional_upload('header_image', $existing);
        cms_setting_set('shop_header_image', $image);
        cms_flash($image !== '' ? 'تصویر هدر فروشگاه ذخیره شد' : 'تصویر هدر فروشگاه حذف شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('shop.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_page_intro'])) {
    try {
        cms_page_intro_save(
            'shop',
            (string) ($_POST['intro_title'] ?? ''),
            (string) ($_POST['intro_explanation'] ?? '')
        );
        cms_flash('متن هدر فروشگاه ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('shop.php');
}

$counts = [
    'factories' => (int) $pdo->query('SELECT COUNT(*) FROM factories')->fetchColumn(),
    'car_models' => (int) $pdo->query('SELECT COUNT(*) FROM car_models')->fetchColumn(),
    'categories' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
    'product_series' => 0,
    'products' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'reviews_pending' => 0,
    'orders_submitted' => 0,
];

try {
    $counts['product_series'] = (int) $pdo->query('SELECT COUNT(*) FROM product_series')->fetchColumn();
} catch (Throwable $e) {
    /* table may not exist until migrate-run */
}

try {
    $counts['reviews_pending'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'"
    )->fetchColumn();
} catch (Throwable $e) {
    /* table may not exist until migrate-run */
}

try {
    $counts['orders_submitted'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'submitted'"
    )->fetchColumn();
} catch (Throwable $e) {
    /* table may not exist until migrate-run */
}

$callForPrice = cms_call_for_price_enabled();
$shopHeaderImage = cms_setting_get('shop_header_image', '');
$intro = cms_page_intro_stored('shop');
$introDefaults = cms_page_intro_defaults()['shop'];

cms_layout_start('فروشگاه', cms_current_username(), 'shop');
?>
<h1 style="margin-top:0">فروشگاه</h1>
<p class="cms-muted">دو ریشه مستقل: <strong>مدل خودرو (۱–۲ کارخانه)</strong> و <strong>دسته محصول</strong>. هر محصول به هر دو وصل می‌شود.</p>

<form class="cms-panel" method="post" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">متن هدر صفحه محصولات</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    عنوان همیشه نمایش داده می‌شود. متن توضیحی وقتی فیلتر فعال نباشد نشان داده می‌شود. خالی = پیش‌فرض سایت.
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
  <h2 style="margin-top:0">تصویر هدر صفحه محصولات</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    کنار عنوان هدر در صفحه فروشگاه نمایش داده می‌شود. برای حذف، مسیر را خالی کنید و ذخیره کنید.
  </p>
  <input type="hidden" name="save_header_image" value="1">
  <?php cms_image_field('header_image', 'تصویر فریم هدر', $shopHeaderImage); ?>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره تصویر</button>
  </div>
</form>

<form class="cms-panel" method="post" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">نمایش قیمت در سایت</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    اگر روشن باشد، همه قیمت‌های عمومی سایت به «<?= cms_h(cms_call_for_price_label()) ?>» تبدیل می‌شوند.
    قیمت واقعی فقط در پنل مدیریت دیده می‌شود.
  </p>
  <input type="hidden" name="save_price_mode" value="1">
  <label class="cms-check" style="margin-bottom:1rem">
    <input type="checkbox" name="call_for_price" value="1" <?= $callForPrice ? 'checked' : '' ?>>
    تماس برای قیمت (به جای قیمت واقعی)
  </label>
  <div class="cms-btn-row" style="margin-top:0">
    <button class="cms-btn" type="submit">ذخیره</button>
    <span class="cms-muted">وضعیت فعلی: <?= $callForPrice ? 'تماس برای قیمت' : 'قیمت واقعی' ?></span>
  </div>
</form>

<div class="cms-grid-2">
  <a class="cms-panel cms-hub-card" href="factories.php">
    <h2>کارخانه‌ها</h2>
    <p class="cms-muted" style="margin:0 0 .35rem">مسیر خودرو</p>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $counts['factories'] ?></p>
  </a>
  <a class="cms-panel cms-hub-card" href="car-models.php">
    <h2>مدل خودرو</h2>
    <p class="cms-muted" style="margin:0 0 .35rem">مسیر خودرو</p>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $counts['car_models'] ?></p>
  </a>
  <a class="cms-panel cms-hub-card" href="categories.php">
    <h2>دسته‌بندی محصول</h2>
    <p class="cms-muted" style="margin:0 0 .35rem">ریشه مستقل</p>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $counts['categories'] ?></p>
  </a>
  <a class="cms-panel cms-hub-card" href="product-series.php">
    <h2>سری محصولات</h2>
    <p class="cms-muted" style="margin:0 0 .35rem">گروه صفحه اصلی</p>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $counts['product_series'] ?></p>
  </a>
  <a class="cms-panel cms-hub-card" href="products.php">
    <h2>محصولات</h2>
    <p class="cms-muted" style="margin:0 0 .35rem">مدل + دسته</p>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $counts['products'] ?></p>
  </a>
  <a class="cms-panel cms-hub-card" href="product-reviews.php">
    <h2>نظرات محصولات</h2>
    <p class="cms-muted" style="margin:0 0 .35rem">در انتظار تأیید</p>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $counts['reviews_pending'] ?></p>
  </a>
  <a class="cms-panel cms-hub-card" href="orders.php">
    <h2>سفارش‌ها</h2>
    <p class="cms-muted" style="margin:0 0 .35rem">ارسال‌شده از مشتری</p>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $counts['orders_submitted'] ?></p>
  </a>
</div>
<p class="cms-muted" style="margin-top:1rem">اگر دیتابیس قدیمی است یک‌بار <a href="migrate-run.php">آپدیت ساختار</a> را اجرا کنید.</p>
<?php cms_layout_end(); ?>
