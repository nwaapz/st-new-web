<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();

cms_layout_start('داشبورد', cms_current_username(), '');
?>
<h1 style="margin-top:0">داشبورد مدیریت</h1>
<p class="cms-muted">بخش مورد نظر را انتخاب کنید.</p>
<div class="cms-grid-2">
  <a class="cms-panel cms-hub-card" href="website.php">
    <h2>نمایش وب</h2>
    <p class="cms-muted" style="margin:0">متن و تصویر هیرو — برای ادمین عادی</p>
  </a>
  <a class="cms-panel cms-hub-card" href="shop.php">
    <h2>فروشگاه</h2>
    <p class="cms-muted" style="margin:0">خودرو (کارخانه/مدل) + دسته محصول مستقل</p>
  </a>
  <a class="cms-panel cms-hub-card" href="communication.php">
    <h2>ارتباطات</h2>
    <p class="cms-muted" style="margin:0">پیام مشتریان، پیام نمایندگان و تیکت‌ها</p>
  </a>
  <a class="cms-panel cms-hub-card cms-hub-card--advanced" href="advanced.php">
    <h2>تنظیمات پیشرفته</h2>
    <p class="cms-muted" style="margin:0">طراحی دقیق UI (Font Lab) — فقط متخصص</p>
  </a>
</div>
<?php cms_layout_end(); ?>
