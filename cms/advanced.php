<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();

$fontLabUrl = rtrim(cms_site_base(), '/') . '/font-lab/';

cms_layout_start('تنظیمات پیشرفته', cms_current_username(), 'advanced');
?>
<h1 style="margin-top:0">تنظیمات پیشرفته</h1>

<div class="cms-warning">
  <strong>هشدار — فقط برای متخصص طراحی</strong>
  این بخش فونت، فاصله‌ها، هدر، بیلبورد و جزئیات UI را تغییر می‌دهد.
  کارکنان عادی نباید اینجا را لمس کنند. برای تغییر متن/تصویر هیرو از
  <a href="website.php" style="color:#ffd089;text-decoration:underline">نمایش وب</a>
  استفاده کنید.
</div>

<p class="cms-muted">
  ابزار Font Lab همان آزمایشگاه طراحی React است. بعد از تغییرات، خروجی JSON را
  روی سایت اعمال کنید تا ظاهر برای همه کاربران یکسان شود.
</p>

<ol class="cms-steps">
  <li>Font Lab را باز کنید (نیاز به ورود CMS دارد).</li>
  <li>تنظیمات را تغییر دهید و در پنل JSON روی «ذخیره JSON» بزنید.</li>
  <li>فایل <span dir="ltr">font-lab-export.json</span> را در ریشه سایت (کنار پوشه cms) جایگزین کنید.</li>
  <li>برای بیلد کامل: <span dir="ltr">npm run bake:font-lab && npm run build</span> سپس آپلود مجدد.</li>
</ol>

<div class="cms-btn-row" style="margin-top:0">
  <a class="cms-btn" href="<?= cms_h($fontLabUrl) ?>" target="_blank" rel="noopener">
    باز کردن Font Lab
  </a>
  <a class="cms-btn cms-btn--secondary" href="website.php">
    بازگشت به نمایش وب (ساده)
  </a>
</div>

<div class="cms-panel" style="margin-top:1.5rem">
  <h2>Font Lab</h2>
  <p class="cms-muted" style="margin:0">
    طراحی دقیق UI — فونت، هدر، بیلبورد، بدنه صفحه، کارت محصول و …
  </p>
  <div class="cms-btn-row">
    <a class="cms-btn cms-btn--ghost" href="<?= cms_h($fontLabUrl) ?>" target="_blank" rel="noopener">
      ورود به Font Lab ↗
    </a>
  </div>
</div>

<div class="cms-panel" style="margin-top:1.5rem">
  <h2>پیامک (ملی‌پیامک)</h2>
  <p class="cms-muted" style="margin:0 0 .75rem">
    نام کاربری، رمز و شماره خط برای ارسال پیامک (مثل OTP در آینده). فقط از پنل مدیریت قابل ویرایش است.
  </p>
  <div class="cms-btn-row">
    <a class="cms-btn" href="sms-settings.php">تنظیمات پیامک</a>
  </div>
</div>

<div class="cms-panel" style="margin-top:1.5rem">
  <h2>دوره سرویس باشگاه مشتریان</h2>
  <p class="cms-muted" style="margin:0 0 .75rem">
    کیلومتر و ماه پیش‌فرض یادآوری برای هر خدمت (مثل لنت ترمز).
  </p>
  <div class="cms-btn-row">
    <a class="cms-btn" href="mechanic-services.php">ویرایش دوره سرویس‌ها</a>
  </div>
</div>

<div class="cms-panel" style="margin-top:1.5rem">
  <h2>سریال‌های گارانتی</h2>
  <p class="cms-muted" style="margin:0 0 .75rem">
    سریال‌های قابل ثبت در <strong>دیتابیس پیامک</strong> (<span dir="ltr">startech_sms.old_serials</span>)
    از پنل CRM/تولید سریال مدیریت می‌شوند — همان منبعی که SMS و صفحه گارانتی سایت از آن می‌خوانند.
    وارد کردن دستی در CMS دیگر برای سایت استفاده نمی‌شود.
  </p>
</div>
<?php cms_layout_end(); ?>
