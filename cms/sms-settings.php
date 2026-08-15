<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/melipayamak.php';

cms_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sms_settings'])) {
    $enabled = isset($_POST['sms_enabled']) ? '1' : '0';
    $username = trim((string) ($_POST['sms_username'] ?? ''));
    $from = trim((string) ($_POST['sms_from'] ?? ''));
    $password = (string) ($_POST['sms_password'] ?? '');

    try {
        cms_setting_set('sms_enabled', $enabled);
        cms_setting_set('sms_test_mode', isset($_POST['sms_test_mode']) ? '1' : '0');
        cms_setting_set('sms_username', $username);
        cms_setting_set('sms_from', $from);
        // Blank password keeps the stored value.
        if (trim($password) !== '') {
            cms_setting_set('sms_password', $password);
        }
        cms_flash('تنظیمات پیامک ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('sms-settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_seller_credit'])) {
    $tomanPerScore = max(0, (int) ($_POST['seller_credit_toman_per_score'] ?? 0));
    $smsCost = max(0, (int) ($_POST['seller_credit_sms_cost_toman'] ?? 0));
    try {
        cms_setting_set('seller_credit_toman_per_score', (string) $tomanPerScore);
        cms_setting_set('seller_credit_sms_cost_toman', (string) $smsCost);
        cms_flash('تنظیمات اعتبار فروشنده ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('sms-settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_sms'])) {
    $testPhone = trim((string) ($_POST['test_phone'] ?? ''));
    $testMessage = trim((string) ($_POST['test_message'] ?? ''));
    if ($testMessage === '') {
        $testMessage = 'پیامک آزمایشی استارتک';
    }

    $result = cms_sms_send($testPhone, $testMessage);
    if ($result['ok']) {
        cms_flash('پیامک آزمایشی ارسال شد. نتیجه: ' . ($result['result'] ?? 'ok'));
    } else {
        cms_flash('ارسال ناموفق: ' . ($result['error'] ?? 'خطای ناشناخته'), 'error');
    }
    cms_redirect('sms-settings.php');
}

$cfg = cms_sms_config();
$passwordSet = cms_sms_password_is_set();
$soapOk = extension_loaded('soap') && class_exists('SoapClient');
$smsDbConfigured = cms_sms_pdo() instanceof PDO;

// Show stored username/from (config() already applies defaults when empty).
$storedUsername = trim(cms_setting_get('sms_username', ''));
$storedFrom = trim(cms_setting_get('sms_from', ''));
$formUsername = $storedUsername !== '' ? $storedUsername : CMS_SMS_DEFAULT_USERNAME;
$formFrom = $storedFrom !== '' ? $storedFrom : CMS_SMS_DEFAULT_FROM;

$tomanPerScore = (int) cms_setting_get('seller_credit_toman_per_score', '0');
$smsCostToman = (int) cms_setting_get('seller_credit_sms_cost_toman', '0');

cms_layout_start('پیامک', cms_current_username(), 'advanced');
?>
<h1 style="margin-top:0">پیامک (ملی‌پیامک)</h1>
<p class="cms-muted">
  ارسال پیامک از طریق SOAP ملی‌پیامک برای استفاده‌های بعدی مثل OTP.
  این اطلاعات فقط در پنل مدیریت ذخیره می‌شود و در API عمومی سایت نمایش داده نمی‌شود.
</p>

<?php if (!$soapOk): ?>
<div class="cms-warning" style="margin-bottom:1rem">
  <strong>افزونه SOAP فعال نیست</strong>
  روی این سرور کلاس <span dir="ltr">SoapClient</span> در دسترس نیست. بدون PHP SOAP ارسال پیامک کار نمی‌کند.
</div>
<?php endif; ?>

<form class="cms-panel" method="post" style="margin-bottom:1.25rem" autocomplete="off">
  <h2 style="margin-top:0">تنظیمات اتصال</h2>
  <input type="hidden" name="save_sms_settings" value="1">

  <label class="cms-check" style="margin-bottom:1rem">
    <input type="checkbox" name="sms_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
    فعال بودن ارسال پیامک
  </label>

  <label class="cms-check" style="margin-bottom:1rem">
    <input type="checkbox" name="sms_test_mode" value="1" <?= cms_setting_get('sms_test_mode', '0') === '1' ? 'checked' : '' ?>>
    حالت تست — ارسال باشگاه مشتریان در هر ساعت (معاف از قانون ۹ صبح تا ۹ شب)
  </label>
  <p class="cms-muted" style="margin:-.4rem 0 1rem;font-size:.82rem">
    روی localhost همیشه معاف است. این گزینه برای سرور آزمایشی/استیجینگ است.
  </p>

  <label class="cms-field">
    <span class="cms-label">نام کاربری ملی‌پیامک</span>
    <input class="cms-input" name="sms_username" dir="ltr" value="<?= cms_h($formUsername) ?>" autocomplete="off">
  </label>

  <label class="cms-field">
    <span class="cms-label">رمز عبور ملی‌پیامک</span>
    <input class="cms-input" type="password" name="sms_password" dir="ltr" value="" placeholder="برای حفظ رمز فعلی خالی بگذارید" autocomplete="new-password">
    <span class="cms-muted" style="font-size:.82rem">
      وضعیت: <?= $passwordSet ? 'رمز ذخیره شده است' : 'رمز هنوز تنظیم نشده' ?>
    </span>
  </label>

  <label class="cms-field">
    <span class="cms-label">شماره خط فرستنده (from)</span>
    <input class="cms-input" name="sms_from" dir="ltr" value="<?= cms_h($formFrom) ?>" autocomplete="off">
  </label>

  <div class="cms-btn-row" style="margin-top:0">
    <button class="cms-btn" type="submit">ذخیره تنظیمات</button>
    <span class="cms-muted">وضعیت: <?= $cfg['enabled'] ? 'فعال' : 'غیرفعال' ?></span>
  </div>
</form>

<form class="cms-panel" method="post" style="margin-bottom:1.25rem" autocomplete="off">
  <h2 style="margin-top:0">اعتبار فروشنده</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    امتیاز ثبت سریال از جدول <span dir="ltr">old_serials</span> در دیتابیس پیامک خوانده می‌شود
    و با نرخ زیر به تومان تبدیل می‌شود. هزینه هر پیامک خروجی باشگاه مشتریان از همین اعتبار کسر می‌شود.
    مقدار <strong>۰</strong> یعنی تبدیل/کسر غیرفعال است.
  </p>
  <input type="hidden" name="save_seller_credit" value="1">

  <?php if (!$smsDbConfigured): ?>
  <div class="cms-warning" style="margin-bottom:1rem">
    اتصال <span dir="ltr">sms_db_*</span> در <span dir="ltr">config.local.php</span> تنظیم نشده
    یا برقرار نیست — موجودی امتیاز فعلاً صفر خوانده می‌شود.
  </div>
  <?php else: ?>
  <p class="cms-muted" style="margin:0 0 1rem;font-size:.85rem">
    وضعیت دیتابیس امتیاز: متصل
  </p>
  <?php endif; ?>

  <label class="cms-field">
    <span class="cms-label">تومان به ازای هر امتیاز</span>
    <input
      class="cms-input"
      type="number"
      name="seller_credit_toman_per_score"
      dir="ltr"
      min="0"
      step="1"
      value="<?= cms_h((string) $tomanPerScore) ?>"
      required
    >
  </label>

  <label class="cms-field">
    <span class="cms-label">هزینه هر پیامک خروجی (تومان)</span>
    <input
      class="cms-input"
      type="number"
      name="seller_credit_sms_cost_toman"
      dir="ltr"
      min="0"
      step="1"
      value="<?= cms_h((string) $smsCostToman) ?>"
      required
    >
  </label>

  <div class="cms-btn-row" style="margin-top:0">
    <button class="cms-btn" type="submit">ذخیره اعتبار</button>
  </div>
</form>

<form class="cms-panel" method="post" autocomplete="off">
  <h2 style="margin-top:0">ارسال آزمایشی</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    برای اطمینان از صحت تنظیمات، یک پیامک تست بفرستید. ارسال باید در بالا فعال باشد و رمز ذخیره شده باشد.
  </p>
  <input type="hidden" name="send_test_sms" value="1">

  <label class="cms-field">
    <span class="cms-label">شماره موبایل گیرنده</span>
    <input class="cms-input" name="test_phone" dir="ltr" placeholder="0912…" required autocomplete="off">
  </label>

  <label class="cms-field">
    <span class="cms-label">متن پیام</span>
    <textarea class="cms-textarea" name="test_message" rows="3" placeholder="پیامک آزمایشی استارتک"></textarea>
  </label>

  <div class="cms-btn-row" style="margin-top:0">
    <button class="cms-btn cms-btn--secondary" type="submit" <?= ($cfg['enabled'] && $passwordSet && $soapOk) ? '' : 'disabled' ?>>
      ارسال پیامک تست
    </button>
  </div>
</form>

<p class="cms-muted" style="margin-top:1rem">
  <a href="advanced.php">بازگشت به تنظیمات پیشرفته</a>
</p>
<?php cms_layout_end(); ?>
