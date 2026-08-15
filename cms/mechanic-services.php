<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/mechanic-catalog.php';

cms_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_mechanic_services'])) {
    try {
        cms_setting_set('mechanic_catalog_intervals', '{}');
        cms_flash('دوره‌ها به مقادیر پیش‌فرض برگشت');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('mechanic-services.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mechanic_services'])) {
    $posted = $_POST['svc'] ?? [];
    $overrides = [];
    try {
        if (!is_array($posted)) {
            throw new RuntimeException('داده نامعتبر است');
        }
        $builtin = mechanic_catalog_builtin_services();
        foreach ($builtin as $key => $row) {
            if (!isset($posted[$key]) || !is_array($posted[$key])) {
                continue;
            }
            $item = $posted[$key];
            $kmRaw = trim((string) ($item['km'] ?? ''));
            $moRaw = trim((string) ($item['months'] ?? ''));
            $basis = trim((string) ($item['basis'] ?? $row['reminder_basis']));
            if (!in_array($basis, ['km', 'time', 'both'], true)) {
                $basis = (string) $row['reminder_basis'];
            }
            $km = $kmRaw === '' ? null : max(0, (int) $kmRaw);
            $months = $moRaw === '' ? null : max(0, (int) $moRaw);
            if ($km === 0) {
                $km = null;
            }
            if ($months === 0) {
                $months = null;
            }
            $sameKm = $km === $row['default_km'];
            $sameMo = $months === $row['default_months'];
            $sameBasis = $basis === $row['reminder_basis'];
            if ($sameKm && $sameMo && $sameBasis) {
                continue;
            }
            $overrides[$key] = [
                'default_km' => $km,
                'default_months' => $months,
                'reminder_basis' => $basis,
            ];
        }
        cms_setting_set(
            'mechanic_catalog_intervals',
            json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        cms_flash('دوره سرویس‌ها ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('mechanic-services.php');
}

$services = mechanic_catalog_services();
$labels = mechanic_catalog_category_labels();
$grouped = [];
foreach ($services as $key => $service) {
    $cat = (string) $service['category'];
    if (!isset($grouped[$cat])) {
        $grouped[$cat] = [];
    }
    $grouped[$cat][$key] = $service;
}

cms_layout_start('دوره سرویس‌ها', cms_current_username(), 'advanced');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">دوره سرویس‌ها</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">
      کیلومتر و ماه پیش‌فرض برای یادآوری هر خدمت. خالی یعنی آن معیار استفاده نشود.
      ثبت‌های قبلی عوض نمی‌شوند — فقط سرویس‌های جدید این مقادیر را می‌گیرند.
    </p>
  </div>
</div>

<form method="post">
  <input type="hidden" name="save_mechanic_services" value="1">
<?php foreach ($grouped as $cat => $items): ?>
  <div class="cms-panel" style="margin-bottom:1.25rem;overflow-x:auto">
    <h2 style="margin-top:0"><?= cms_h($labels[$cat] ?? $cat) ?></h2>
    <table class="cms-table">
      <thead>
        <tr>
          <th>خدمت</th>
          <th style="width:8rem">کیلومتر</th>
          <th style="width:7rem">ماه</th>
          <th style="width:9rem">یادآوری بر اساس</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $key => $service): ?>
        <tr>
          <td>
            <strong><?= cms_h((string) $service['label']) ?></strong>
            <div class="cms-muted" dir="ltr" style="font-size:.75rem"><?= cms_h($key) ?></div>
          </td>
          <td>
            <input
              class="cms-input"
              type="number"
              name="svc[<?= cms_h($key) ?>][km]"
              dir="ltr"
              min="0"
              step="100"
              placeholder="—"
              value="<?= $service['default_km'] !== null ? (int) $service['default_km'] : '' ?>"
            >
          </td>
          <td>
            <input
              class="cms-input"
              type="number"
              name="svc[<?= cms_h($key) ?>][months]"
              dir="ltr"
              min="0"
              step="1"
              placeholder="—"
              value="<?= $service['default_months'] !== null ? (int) $service['default_months'] : '' ?>"
            >
          </td>
          <td>
            <select class="cms-select" name="svc[<?= cms_h($key) ?>][basis]">
              <option value="km"<?= $service['reminder_basis'] === 'km' ? ' selected' : '' ?>>کیلومتر</option>
              <option value="time"<?= $service['reminder_basis'] === 'time' ? ' selected' : '' ?>>زمان</option>
              <option value="both"<?= $service['reminder_basis'] === 'both' ? ' selected' : '' ?>>هر دو</option>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره دوره‌ها</button>
  </div>
</form>

<form method="post" style="margin-top:1rem" onsubmit="return confirm('همه دوره‌ها به پیش‌فرض کارخانه برگردند؟');">
  <input type="hidden" name="reset_mechanic_services" value="1">
  <button class="cms-btn cms-btn--ghost" type="submit">بازگشت به پیش‌فرض</button>
</form>

<p class="cms-muted" style="margin-top:1rem">
  <a href="advanced.php">بازگشت به تنظیمات پیشرفته</a>
</p>
<?php cms_layout_end(); ?>
