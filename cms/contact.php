<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/contact.php';
require_once __DIR__ . '/lib/bale.php';

cms_require_login();
$pdo = cms_pdo();
bale_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_contact_public'])) {
    try {
        cms_setting_set('contact_title', trim((string) ($_POST['contact_title'] ?? '')));
        cms_setting_set('contact_subtitle', trim((string) ($_POST['contact_subtitle'] ?? '')));
        cms_setting_set('contact_explanation', trim((string) ($_POST['contact_explanation'] ?? '')));
        cms_setting_set('contact_landline', trim((string) ($_POST['contact_landline'] ?? '')));
        cms_setting_set('contact_mobile', trim((string) ($_POST['contact_mobile'] ?? '')));
        cms_setting_set('contact_whatsapp', trim((string) ($_POST['contact_whatsapp'] ?? '')));
        cms_setting_set('contact_bale_username', contact_bale_username((string) ($_POST['contact_bale_username'] ?? '')));
        cms_setting_set('contact_hours', trim((string) ($_POST['contact_hours'] ?? '')));
        cms_setting_set('contact_address', trim((string) ($_POST['contact_address'] ?? '')));
        $existingHero = cms_setting_get('contact_hero_image', '');
        $hero = cms_handle_optional_upload('contact_hero_image', $existingHero);
        cms_setting_set('contact_hero_image', $hero);
        cms_flash('اطلاعات تماس ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('contact.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_contact_bot'])) {
    try {
        $token = trim((string) ($_POST['contact_bale_bot_token'] ?? ''));
        if ($token !== '') {
            cms_setting_set('contact_bale_bot_token', $token);
        }
        $llmKey = (string) ($_POST['contact_llm_api_key'] ?? '');
        if (trim($llmKey) !== '') {
            cms_setting_set('contact_llm_api_key', trim($llmKey));
        }
        cms_setting_set('contact_llm_base_url', trim((string) ($_POST['contact_llm_base_url'] ?? '')));
        cms_setting_set('contact_llm_model', trim((string) ($_POST['contact_llm_model'] ?? '')));
        cms_setting_set('contact_llm_prompt', trim((string) ($_POST['contact_llm_prompt'] ?? '')));
        contact_ensure_webhook_secret();
        cms_flash('تنظیمات ربات بله ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('contact.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_bale_webhook'])) {
    $token = bale_bot_token();
    if ($token === '') {
        cms_flash('ابتدا توکن ربات بله را ذخیره کنید', 'error');
        cms_redirect('contact.php');
    }
    $url = contact_bale_webhook_url();
    if (stripos($url, 'https://') !== 0) {
        cms_flash('وب‌هوک بله فقط روی HTTPS کار می‌کند. آدرس فعلی: ' . $url, 'error');
        cms_redirect('contact.php');
    }
    $result = bale_set_webhook($url);
    $apiOk = !empty($result['json']['ok']);
    if ($result['ok'] && $apiOk) {
        cms_flash('وب‌هوک بله تنظیم شد');
    } else {
        $detail = '';
        if (isset($result['json']['description']) && is_string($result['json']['description'])) {
            $detail = $result['json']['description'];
        } elseif ($result['error'] !== '') {
            $detail = $result['error'];
        } else {
            $detail = 'HTTP ' . $result['status'];
        }
        cms_flash('تنظیم وب‌هوک ناموفق: ' . $detail, 'error');
    }
    cms_redirect('contact.php');
}

$title = trim(cms_setting_get('contact_title', ''));
$subtitle = trim(cms_setting_get('contact_subtitle', ''));
$explanation = trim(cms_setting_get('contact_explanation', ''));
$landline = trim(cms_setting_get('contact_landline', ''));
$mobile = trim(cms_setting_get('contact_mobile', ''));
$whatsapp = trim(cms_setting_get('contact_whatsapp', ''));
$baleUsername = trim(cms_setting_get('contact_bale_username', ''));
$hours = trim(cms_setting_get('contact_hours', ''));
$address = trim(cms_setting_get('contact_address', ''));
$heroImage = trim(cms_setting_get('contact_hero_image', ''));
$llmBase = trim(cms_setting_get('contact_llm_base_url', ''));
$llmModel = trim(cms_setting_get('contact_llm_model', ''));
$llmPrompt = trim(cms_setting_get('contact_llm_prompt', ''));
$tokenSet = bale_bot_token() !== '';
$llmKeySet = trim(cms_setting_get('contact_llm_api_key', '')) !== '';
$webhookUrl = contact_bale_webhook_url();

cms_layout_start('تماس با ما', cms_current_username(), 'website');
?>
<h1 style="margin-top:0">تماس با ما</h1>
<p class="cms-muted">
  شماره‌ها و لینک‌های صفحه تماس. خالی بگذارید تا آن کانال در سایت نمایش داده نشود.
  توکن ربات و کلید هوش مصنوعی فقط در همین پنل ذخیره می‌شود و به API عمومی نمی‌رود.
</p>

<form class="cms-panel" method="post" enctype="multipart/form-data" style="margin-bottom:1.25rem" autocomplete="off">
  <h2 style="margin-top:0">متن و راه‌های ارتباطی</h2>
  <input type="hidden" name="save_contact_public" value="1">

  <label class="cms-field">
    <span class="cms-label">عنوان</span>
    <input class="cms-input" name="contact_title" value="<?= cms_h($title) ?>" placeholder="<?= cms_h(contact_default_title()) ?>">
  </label>
  <label class="cms-field">
    <span class="cms-label">زیرعنوان</span>
    <input class="cms-input" name="contact_subtitle" value="<?= cms_h($subtitle) ?>" placeholder="<?= cms_h(contact_default_subtitle()) ?>">
  </label>
  <label class="cms-field">
    <span class="cms-label">توضیح</span>
    <textarea class="cms-textarea" name="contact_explanation" rows="3" placeholder="<?= cms_h(contact_default_explanation()) ?>"><?= cms_h($explanation) ?></textarea>
  </label>
  <label class="cms-field">
    <span class="cms-label">موبایل پشتیبانی</span>
    <input class="cms-input" name="contact_mobile" dir="ltr" value="<?= cms_h($mobile) ?>" placeholder="0912xxxxxxx">
    <span class="cms-muted" style="font-size:.82rem">روی موبایل، زدن برچسب «موبایل پشتیبانی» تماس می‌گیرد.</span>
  </label>
  <label class="cms-field">
    <span class="cms-label">تلفن‌های ثابت</span>
    <textarea class="cms-textarea" name="contact_landline" dir="ltr" rows="3" placeholder="021xxxxxxx"><?= cms_h($landline) ?></textarea>
    <span class="cms-muted" style="font-size:.82rem">هر خط یک شماره. روی موبایل زدن برچسب تماس می‌گیرد.</span>
  </label>
  <label class="cms-field">
    <span class="cms-label">واتساپ</span>
    <input class="cms-input" name="contact_whatsapp" dir="ltr" value="<?= cms_h($whatsapp) ?>" placeholder="0912xxxxxxx یا لینک wa.me">
  </label>
  <label class="cms-field">
    <span class="cms-label">نام کاربری ربات بله</span>
    <input class="cms-input" name="contact_bale_username" dir="ltr" value="<?= cms_h($baleUsername) ?>" placeholder="StarTechBot بدون @">
  </label>
  <label class="cms-field">
    <span class="cms-label">ساعات پاسخگویی</span>
    <input class="cms-input" name="contact_hours" value="<?= cms_h($hours) ?>" placeholder="شنبه تا پنجشنبه، ۹ تا ۱۷">
  </label>
  <label class="cms-field">
    <span class="cms-label">آدرس / محل</span>
    <textarea class="cms-textarea" name="contact_address" rows="2"><?= cms_h($address) ?></textarea>
  </label>
  <?php cms_image_field('contact_hero_image', 'تصویر سمت چپ (هم‌تراز هدر)', $heroImage); ?>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره اطلاعات تماس</button>
  </div>
</form>

<form class="cms-panel" method="post" style="margin-bottom:1.25rem" autocomplete="off">
  <h2 style="margin-top:0">ربات بله و هوش مصنوعی</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    ربات را در بله با BotFather بسازید، توکن را ذخیره کنید، سپس وب‌هوک را روی دامنه HTTPS بزنید.
    برای پاسخ هوشمند، آدرس سازگار با OpenAI (مثل AvalAI یا Liara) و کلید را وارد کنید.
  </p>
  <input type="hidden" name="save_contact_bot" value="1">

  <label class="cms-field">
    <span class="cms-label">توکن ربات بله</span>
    <input class="cms-input" type="password" name="contact_bale_bot_token" dir="ltr" value="" placeholder="برای حفظ توکن فعلی خالی بگذارید" autocomplete="new-password">
    <span class="cms-muted" style="font-size:.82rem">وضعیت: <?= $tokenSet ? 'توکن ذخیره شده است' : 'توکن هنوز تنظیم نشده' ?></span>
  </label>
  <label class="cms-field">
    <span class="cms-label">آدرس API مدل (OpenAI-compatible)</span>
    <input class="cms-input" name="contact_llm_base_url" dir="ltr" value="<?= cms_h($llmBase) ?>" placeholder="https://api.example.com/v1">
  </label>
  <label class="cms-field">
    <span class="cms-label">کلید API مدل</span>
    <input class="cms-input" type="password" name="contact_llm_api_key" dir="ltr" value="" placeholder="برای حفظ کلید فعلی خالی بگذارید" autocomplete="new-password">
    <span class="cms-muted" style="font-size:.82rem">وضعیت: <?= $llmKeySet ? 'کلید ذخیره شده است' : 'کلید هنوز تنظیم نشده' ?></span>
  </label>
  <label class="cms-field">
    <span class="cms-label">نام مدل</span>
    <input class="cms-input" name="contact_llm_model" dir="ltr" value="<?= cms_h($llmModel) ?>" placeholder="gpt-4o-mini">
  </label>
  <label class="cms-field">
    <span class="cms-label">دستور سیستم (system prompt)</span>
    <textarea class="cms-textarea" name="contact_llm_prompt" rows="6" placeholder="<?= cms_h(contact_default_llm_prompt()) ?>"><?= cms_h($llmPrompt) ?></textarea>
  </label>
  <div class="cms-btn-row" style="margin-top:0">
    <button class="cms-btn" type="submit">ذخیره تنظیمات ربات</button>
  </div>
</form>

<form class="cms-panel" method="post" autocomplete="off">
  <h2 style="margin-top:0">وب‌هوک</h2>
  <p class="cms-muted" style="margin:.25rem 0 .75rem">
    بعد از استقرار روی HTTPS این دکمه را بزنید تا بله پیام‌ها را به سایت بفرستد.
  </p>
  <p class="cms-muted" dir="ltr" style="font-size:.82rem;word-break:break-all"><?= cms_h($webhookUrl) ?></p>
  <input type="hidden" name="set_bale_webhook" value="1">
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ثبت وب‌هوک در بله</button>
    <span class="cms-muted">وضعیت مدل: <?= $llmKeySet && $llmBase !== '' ? 'پاسخ هوشمند فعال است' : 'پاسخ آماده / بدون مدل' ?></span>
  </div>
</form>
<?php cms_layout_end(); ?>
