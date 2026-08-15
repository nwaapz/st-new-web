<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();
$pdo = cms_pdo();

$danestaniCount = 0;
try {
    $danestaniCount = (int) $pdo->query('SELECT COUNT(*) FROM danestani_media_frames')->fetchColumn();
} catch (Throwable $e) {
    /* table may not exist until migrate */
}

$aboutCount = 0;
try {
    $aboutCount = (int) $pdo->query('SELECT COUNT(*) FROM about_exhibitions')->fetchColumn();
} catch (Throwable $e) {
    /* table may not exist until migrate */
}

$branchesCount = 0;
try {
    require_once __DIR__ . '/lib/messages.php';
    messages_ensure_schema($pdo);
    $branchesCount = (int) $pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn();
} catch (Throwable $e) {
    /* table may not exist until migrate */
}

cms_layout_start('نمایش وب', cms_current_username(), 'website');
?>
<h1 style="margin-top:0">نمایش وب</h1>
<p class="cms-muted">
  محتوای ساده صفحه اصلی برای ادمین عادی — متن و تصویر اسلایدهای هیرو و جوایز.
  طراحی دقیق فونت، فاصله و UI در
  <a href="advanced.php" style="color:#e8d4b0;text-decoration:underline">تنظیمات پیشرفته</a>
  است.
</p>
<div class="cms-grid-2">
  <a class="cms-panel cms-hub-card" href="hero.php">
    <h2>صفحه اصلی</h2>
    <p class="cms-muted">هیرو و جوایز صفحه نخست</p>
  </a>
  <a class="cms-panel cms-hub-card" href="danestani-media.php">
    <h2>تکنولوژی</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $danestaniCount ?></p>
    <p class="cms-muted">افزودن و حذف قاب‌های عنوان + توضیح + اسلایدر تصویر</p>
  </a>
  <a class="cms-panel cms-hub-card" href="about.php">
    <h2>درباره ما / نمایشگاه‌ها</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $aboutCount ?></p>
    <p class="cms-muted">مانیفست برند، آمار، داستان و ویدیو/تصویر نمایشگاه‌ها</p>
  </a>
  <a class="cms-panel cms-hub-card" href="contact.php">
    <h2>تماس با ما</h2>
    <p class="cms-muted">تلفن، واتساپ، بله و تنظیم ربات پاسخگو</p>
  </a>
  <a class="cms-panel cms-hub-card" href="branches.php">
    <h2>نمایندگان / شعب</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $branchesCount ?></p>
    <p class="cms-muted">استان، شهر و اطلاعات شعب روی نقشه پورتال نمایندگان</p>
  </a>
  <a class="cms-panel cms-hub-card" href="mechanics.php">
    <h2>باشگاه مشتریان</h2>
    <p class="cms-muted">مکانیک‌ها، صفحه ورود و پیام گروهی</p>
  </a>
  <a class="cms-panel cms-hub-card" href="warranty.php">
    <h2>گارانتی</h2>
    <p class="cms-muted">عنوان و توضیح هدر صفحه خدمات پس از فروش</p>
  </a>
  <a class="cms-panel cms-hub-card" href="footer.php">
    <h2>پاورقی</h2>
    <p class="cms-muted">متن‌ها، شبکه‌های اجتماعی، پیوندها و لوگوهای کارخانه</p>
  </a>
</div>
<?php cms_layout_end(); ?>
