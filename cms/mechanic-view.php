<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/mechanics.php';
require_once __DIR__ . '/lib/mechanic-catalog.php';
require_once __DIR__ . '/lib/jalali.php';

cms_require_login();
$pdo = cms_pdo();
mechanics_ensure_schema($pdo);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0 && isset($_POST['id'])) {
    $id = (int) $_POST['id'];
}
$smsPage = isset($_GET['sms_page']) ? (int) $_GET['sms_page'] : 1;
if ($smsPage < 1) {
    $smsPage = 1;
}

function mechanic_view_redirect(int $id, string $hash = ''): void
{
    $url = 'mechanic-view.php?id=' . $id;
    if ($hash !== '') {
        $url .= '#' . $hash;
    }
    cms_redirect($url);
}

function mechanic_view_fa_int(?int $n): string
{
    if ($n === null) {
        return '—';
    }
    return cms_to_persian_digits(number_format($n, 0, '.', '٬'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
    try {
        if (isset($_POST['mechanic_status_action'])) {
            $action = trim((string) ($_POST['action'] ?? ''));
            $note = trim((string) ($_POST['status_note'] ?? ''));
            $msg = mechanics_apply_status_action($pdo, $id, $action, $note);
            cms_flash($msg);
        } elseif (isset($_POST['save_profile'])) {
            $workshop = trim((string) ($_POST['workshop_name'] ?? ''));
            $owner = trim((string) ($_POST['owner_name'] ?? ''));
            $city = trim((string) ($_POST['city'] ?? ''));
            $services = $_POST['services'] ?? [];
            if ($workshop === '' || $owner === '' || $city === '') {
                throw new RuntimeException('نام تعمیرگاه، مکانیک و شهر الزامی است');
            }
            if (!is_array($services)) {
                $services = [];
            }
            $keys = [];
            foreach ($services as $key) {
                $keys[] = (string) $key;
            }
            mechanics_update_profile($pdo, $id, $workshop, $owner, $city);
            mechanics_set_active_services($pdo, $id, $keys);
            cms_flash('نمایه تعمیرگاه ذخیره شد');
        } else {
            throw new RuntimeException('عملیات نامعتبر');
        }
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    mechanic_view_redirect($id, isset($_POST['save_profile']) ? 'profile' : '');
}

$detail = $id > 0 ? mechanics_admin_detail($pdo, $id, $smsPage) : null;
if ($detail === null) {
    cms_flash('تعمیرگاه یافت نشد', 'error');
    cms_redirect('mechanics.php');
}

$mechanic = $detail['mechanic'];
$activity = $detail['activity'];
$credit = $detail['credit'];
$st = (string) $mechanic['status'];
$statusLabels = mechanics_status_labels();
$heatLabels = mechanics_heat_labels();
$actionLabels = mechanics_action_labels();
$actions = mechanics_status_actions($st);
$activeServices = mechanics_active_services($pdo, $id);
$catalog = mechanic_catalog_services();
$catLabels = mechanic_catalog_category_labels();
$servicesByCat = [];
foreach ($catalog as $key => $svc) {
    $cat = (string) ($svc['category'] ?? 'general');
    $servicesByCat[$cat][$key] = $svc;
}

cms_layout_start($mechanic['workshop_name'], cms_current_username(), 'website');
?>
<div class="cms-page-head cms-mech-head">
  <div>
    <p class="cms-muted" style="margin:0 0 .35rem"><a href="mechanics.php" style="text-decoration:underline">مکانیک‌ها</a> / جزئیات</p>
    <h1 style="margin:0"><?= cms_h((string) $mechanic['workshop_name']) ?></h1>
    <p class="cms-muted" style="margin:.35rem 0 0">
      <?= cms_h((string) $mechanic['owner_name']) ?>
      · <?= cms_h((string) $mechanic['city']) ?>
      · <span dir="ltr"><?= cms_h(cms_to_persian_digits((string) $mechanic['phone'])) ?></span>
    </p>
    <div class="cms-mech-card__meta" style="margin-top:.65rem">
      <span class="cms-pill cms-pill--<?= cms_h($st) ?>"><?= cms_h($statusLabels[$st] ?? $st) ?></span>
      <span class="cms-heat cms-heat--<?= cms_h((string) $activity['heat']) ?>"><?= cms_h($heatLabels[$activity['heat']] ?? '') ?></span>
      <span class="cms-muted">فعالیت <?= cms_h(cms_to_persian_digits((string) $activity['score'])) ?></span>
    </div>
    <?php if (!empty($mechanic['status_note'])): ?>
      <p class="cms-muted" style="margin:.5rem 0 0">یادداشت وضعیت: <?= cms_h((string) $mechanic['status_note']) ?></p>
    <?php endif; ?>
  </div>
  <div class="cms-mech-head__actions">
    <?php foreach ($actions as $action): ?>
      <form method="post" class="cms-mech-action">
        <input type="hidden" name="mechanic_status_action" value="1">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="<?= cms_h($action) ?>">
        <?php if ($action === 'reject'): ?>
          <input class="cms-input cms-input--compact" name="status_note" placeholder="دلیل رد" required>
        <?php elseif ($action === 'suspend'): ?>
          <input class="cms-input cms-input--compact" name="status_note" placeholder="یادداشت (اختیاری)">
        <?php endif; ?>
        <button class="<?= cms_h(mechanics_action_btn_class($action)) ?>" type="submit"><?= cms_h($actionLabels[$action] ?? $action) ?></button>
      </form>
    <?php endforeach; ?>
  </div>
</div>

<div class="cms-kpi-grid cms-kpi-grid--compact">
  <div class="cms-kpi">
    <span class="cms-kpi__value"><?= cms_h(mechanic_view_fa_int((int) $activity['customer_count'])) ?></span>
    <span class="cms-kpi__label">مشتری</span>
  </div>
  <div class="cms-kpi">
    <span class="cms-kpi__value"><?= cms_h(mechanic_view_fa_int((int) $activity['vehicle_count'])) ?></span>
    <span class="cms-kpi__label">خودرو</span>
  </div>
  <div class="cms-kpi">
    <span class="cms-kpi__value"><?= cms_h(mechanic_view_fa_int((int) $activity['service_count'])) ?></span>
    <span class="cms-kpi__label">سرویس</span>
  </div>
  <div class="cms-kpi">
    <span class="cms-kpi__value"><?= cms_h(mechanic_view_fa_int((int) ($credit['available'] ?? 0))) ?></span>
    <span class="cms-kpi__label">اعتبار (تومان)</span>
  </div>
  <a class="cms-kpi" href="#sms">
    <span class="cms-kpi__value"><?= cms_h(mechanic_view_fa_int((int) ($detail['sms_sent_count'] ?? 0))) ?></span>
    <span class="cms-kpi__label">پیامک ارسال‌شده</span>
  </a>
</div>

<nav class="cms-anchor-nav" aria-label="بخش‌های پرونده">
  <a class="cms-chip" href="#profile">نمایه</a>
  <a class="cms-chip" href="#customers">مشتریان (<?= cms_h(cms_to_persian_digits((string) count($detail['customers']))) ?>)</a>
  <a class="cms-chip" href="#vehicles">خودروها (<?= cms_h(cms_to_persian_digits((string) count($detail['vehicles']))) ?>)</a>
  <a class="cms-chip" href="#services">سرویس‌ها (<?= cms_h(cms_to_persian_digits((string) count($detail['services']))) ?>)</a>
  <a class="cms-chip" href="#invoices">فاکتورها (<?= cms_h(cms_to_persian_digits((string) count($detail['invoices']))) ?>)</a>
  <a class="cms-chip" href="#sms">پیامک (<?= cms_h(cms_to_persian_digits((string) (int) ($detail['sms_sent_count'] ?? 0))) ?> ارسال)</a>
</nav>

<section id="profile" class="cms-panel cms-anchor-section">
  <h2>نمایه</h2>
  <form method="post">
    <input type="hidden" name="save_profile" value="1">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="cms-grid-2">
      <label class="cms-field"><span class="cms-label">نام تعمیرگاه</span>
        <input class="cms-input" name="workshop_name" required value="<?= cms_h((string) $mechanic['workshop_name']) ?>">
      </label>
      <label class="cms-field"><span class="cms-label">نام مکانیک</span>
        <input class="cms-input" name="owner_name" required value="<?= cms_h((string) $mechanic['owner_name']) ?>">
      </label>
    </div>
    <label class="cms-field"><span class="cms-label">شهر</span>
      <input class="cms-input" name="city" required value="<?= cms_h((string) $mechanic['city']) ?>">
    </label>
    <p class="cms-label" style="margin:1rem 0 .5rem">خدمات ارائه‌شده</p>
    <div class="cms-service-cats">
      <?php foreach ($servicesByCat as $cat => $svcs): ?>
        <fieldset class="cms-service-cat">
          <legend><?= cms_h($catLabels[$cat] ?? $cat) ?></legend>
          <div class="cms-check-list">
            <?php foreach ($svcs as $key => $svc): ?>
              <label class="cms-check cms-check-list__item">
                <input type="checkbox" name="services[]" value="<?= cms_h((string) $key) ?>" <?= in_array($key, $activeServices, true) ? 'checked' : '' ?>>
                <?= cms_h((string) ($svc['label'] ?? $key)) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
      <?php endforeach; ?>
    </div>
    <div class="cms-btn-row">
      <button class="cms-btn" type="submit">ذخیره نمایه</button>
    </div>
  </form>
</section>

<section id="customers" class="cms-panel cms-anchor-section">
  <h2>مشتریان</h2>
  <?php if ($detail['customers'] === []): ?>
    <p class="cms-empty">هنوز مشتری ثبت نشده.</p>
  <?php else: ?>
    <div class="cms-inbox-wrap">
      <table class="cms-table">
        <thead><tr><th>نام</th><th>موبایل</th><th>مراجعه</th><th>آخرین بازدید</th></tr></thead>
        <tbody>
        <?php foreach ($detail['customers'] as $row): ?>
          <tr>
            <td><?= cms_h((string) $row['name']) ?></td>
            <td dir="ltr"><?= cms_h($row['phone'] ? cms_to_persian_digits((string) $row['phone']) : '—') ?></td>
            <td><?= cms_h(mechanic_view_fa_int((int) $row['visit_count'])) ?></td>
            <td dir="ltr"><?= cms_h(cms_to_persian_digits(cms_jalali_format_date($row['last_visit_at'] !== null ? (string) $row['last_visit_at'] : null))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section id="vehicles" class="cms-panel cms-anchor-section">
  <h2>خودروها</h2>
  <?php if ($detail['vehicles'] === []): ?>
    <p class="cms-empty">هنوز خودرویی ثبت نشده.</p>
  <?php else: ?>
    <div class="cms-inbox-wrap">
      <table class="cms-table">
        <thead><tr><th>مالک</th><th>خودرو</th><th>پلاک</th><th>کیلومتر</th><th>آخرین بازدید</th></tr></thead>
        <tbody>
        <?php foreach ($detail['vehicles'] as $row): ?>
          <tr>
            <td><?= cms_h((string) $row['customer_name']) ?></td>
            <td><?= cms_h(trim((string) $row['brand'] . ' ' . (string) $row['model'] . ($row['year'] ? ' ' . $row['year'] : ''))) ?></td>
            <td dir="ltr"><?= cms_h($row['plate'] ? (string) $row['plate'] : '—') ?></td>
            <td><?= cms_h(mechanic_view_fa_int($row['current_km'] !== null ? (int) $row['current_km'] : null)) ?></td>
            <td dir="ltr"><?= cms_h(cms_to_persian_digits(cms_jalali_format_date($row['last_visit_at'] !== null ? (string) $row['last_visit_at'] : null))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section id="services" class="cms-panel cms-anchor-section">
  <h2>سرویس‌ها</h2>
  <?php if ($detail['services'] === []): ?>
    <p class="cms-empty">سرویسی ثبت نشده.</p>
  <?php else: ?>
    <div class="cms-inbox-wrap">
      <table class="cms-table">
        <thead><tr><th>تاریخ</th><th>خدمت</th><th>مشتری / خودرو</th><th>کیلومتر</th><th>هزینه</th><th>سررسید</th></tr></thead>
        <tbody>
        <?php foreach ($detail['services'] as $row):
          $cost = ((int) ($row['labor_cost'] ?? 0)) + ((int) ($row['parts_cost'] ?? 0));
          ?>
          <tr>
            <td dir="ltr"><?= cms_h(cms_to_persian_digits(cms_jalali_format_date((string) $row['performed_at']))) ?></td>
            <td><?= cms_h((string) $row['service_label']) ?></td>
            <td>
              <?= cms_h((string) $row['customer_name']) ?>
              <div class="cms-muted"><?= cms_h(trim((string) $row['vehicle_label'] . ($row['plate'] ? ' · ' . $row['plate'] : ''))) ?></div>
            </td>
            <td><?= cms_h(mechanic_view_fa_int($row['km_at_service'] !== null ? (int) $row['km_at_service'] : null)) ?></td>
            <td><?= $cost > 0 ? cms_h(mechanic_view_fa_int($cost)) : '—' ?></td>
            <td dir="ltr"><?= cms_h(cms_to_persian_digits(cms_jalali_format_date($row['next_due_at'] !== null ? (string) $row['next_due_at'] : null))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section id="invoices" class="cms-panel cms-anchor-section">
  <h2>فاکتورها</h2>
  <?php if ($detail['invoices'] === []): ?>
    <p class="cms-empty">فاکتوری صادر نشده.</p>
  <?php else: ?>
    <div class="cms-inbox-wrap">
      <table class="cms-table">
        <thead><tr><th>تاریخ</th><th>مشتری</th><th>جمع</th><th>پیامک</th></tr></thead>
        <tbody>
        <?php foreach ($detail['invoices'] as $row): ?>
          <tr>
            <td dir="ltr"><?= cms_h(cms_to_persian_digits(cms_jalali_format_date((string) $row['performed_at']))) ?></td>
            <td>
              <?= cms_h((string) $row['customer_name']) ?>
              <div class="cms-muted"><?= cms_h(trim((string) $row['vehicle_label'] . ($row['plate'] ? ' · ' . $row['plate'] : ''))) ?></div>
            </td>
            <td><?= cms_h(mechanic_view_fa_int((int) $row['total'])) ?></td>
            <td><?= $row['sms_sent_at'] ? cms_h(cms_to_persian_digits(cms_jalali_format_from_timestamp((string) $row['sms_sent_at']))) : 'ارسال نشده' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section id="sms" class="cms-panel cms-anchor-section">
  <?php
    $smsSent = (int) ($detail['sms_sent_count'] ?? 0);
    $smsTotal = (int) ($detail['sms_total_count'] ?? 0);
    $smsCur = (int) ($detail['sms_page'] ?? 1);
    $smsPages = (int) ($detail['sms_pages'] ?? 1);
  ?>
  <h2>پیامک</h2>
  <p class="cms-muted" style="margin:-.35rem 0 1rem">
    <?= cms_h(mechanic_view_fa_int($smsSent)) ?> ارسال از <?= cms_h(mechanic_view_fa_int($smsTotal)) ?> پیام
  </p>
  <?php if ($detail['sms'] === []): ?>
    <p class="cms-empty">پیامکی ثبت نشده.</p>
  <?php else: ?>
    <div class="cms-inbox-wrap">
      <table class="cms-table">
        <thead><tr><th>زمان</th><th>شماره</th><th>قالب</th><th>وضعیت</th><th>متن</th></tr></thead>
        <tbody>
        <?php foreach ($detail['sms'] as $row): ?>
          <tr>
            <td dir="ltr"><?= cms_h(cms_to_persian_digits(cms_jalali_format_from_timestamp((string) $row['created_at']))) ?></td>
            <td dir="ltr"><?= cms_h(cms_to_persian_digits((string) $row['phone'])) ?></td>
            <td><?= cms_h(mechanics_sms_template_label((string) $row['template_key'])) ?></td>
            <td><?= cms_h(mechanics_sms_status_label((string) $row['status'])) ?></td>
            <td class="cms-sms-preview"><?= cms_h(function_exists('mb_substr') ? mb_substr((string) $row['body'], 0, 90) : substr((string) $row['body'], 0, 90)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($smsPages > 1): ?>
      <nav class="cms-orders-pager" aria-label="صفحه‌بندی پیامک">
        <?php if ($smsCur > 1): ?>
          <a class="cms-btn cms-btn--secondary" href="mechanic-view.php?id=<?= $id ?>&amp;sms_page=<?= $smsCur - 1 ?>#sms">قبلی</a>
        <?php endif; ?>
        <span class="cms-muted"><?= cms_h(cms_to_persian_digits((string) $smsCur)) ?> / <?= cms_h(cms_to_persian_digits((string) $smsPages)) ?></span>
        <?php if ($smsCur < $smsPages): ?>
          <a class="cms-btn cms-btn--secondary" href="mechanic-view.php?id=<?= $id ?>&amp;sms_page=<?= $smsCur + 1 ?>#sms">بعدی</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php cms_layout_end(); ?>
