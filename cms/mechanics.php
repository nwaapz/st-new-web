<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/mechanics.php';
require_once __DIR__ . '/lib/jalali.php';

cms_require_login();
$pdo = cms_pdo();
mechanics_ensure_schema($pdo);

$filter = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
if (!in_array($filter, ['all', 'pending', 'active', 'suspended', 'rejected', 'dormant'], true)) {
    $filter = 'all';
}
$searchQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mechanic_status_action'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? ''));
    $note = trim((string) ($_POST['status_note'] ?? ''));
    $returnFilter = trim((string) ($_POST['return_status'] ?? $filter));
    $returnQ = trim((string) ($_POST['return_q'] ?? $searchQ));
    try {
        $msg = mechanics_apply_status_action($pdo, $id, $action, $note);
        cms_flash($msg);
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    $qs = ['status' => $returnFilter];
    if ($returnQ !== '') {
        $qs['q'] = $returnQ;
    }
    cms_redirect('mechanics.php?' . http_build_query($qs));
}

$kpis = mechanics_admin_kpis($pdo);
$items = mechanics_admin_list($pdo, $filter, $searchQ);
$statusLabels = mechanics_status_labels();
$heatLabels = mechanics_heat_labels();
$actionLabels = mechanics_action_labels();

$filterTabs = [
    'all' => 'همه',
    'pending' => 'در انتظار',
    'active' => 'فعال',
    'suspended' => 'معلق',
    'rejected' => 'رد شده',
    'dormant' => 'راکد',
];

cms_layout_start('مکانیک‌ها', cms_current_username(), 'website');

function mechanics_cms_list_qs(string $status, string $q = ''): string
{
    $params = ['status' => $status];
    if ($q !== '') {
        $params['q'] = $q;
    }
    return 'mechanics.php?' . http_build_query($params);
}
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">مکانیک‌ها</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">ثبت‌نام‌ها، فعالیت، تعلیق و ازسرگیری — همه تعمیرگاه‌های باشگاه مشتریان</p>
  </div>
</div>

<div class="cms-kpi-grid">
  <div class="cms-kpi">
    <span class="cms-kpi__value"><?= cms_h(cms_to_persian_digits((string) $kpis['total'])) ?></span>
    <span class="cms-kpi__label">کل تعمیرگاه‌ها</span>
  </div>
  <a class="cms-kpi cms-kpi--pending<?= $filter === 'pending' ? ' is-active' : '' ?>" href="<?= cms_h(mechanics_cms_list_qs('pending', $searchQ)) ?>">
    <span class="cms-kpi__value"><?= cms_h(cms_to_persian_digits((string) $kpis['pending'])) ?></span>
    <span class="cms-kpi__label">در انتظار تأیید</span>
  </a>
  <div class="cms-kpi">
    <span class="cms-kpi__value"><?= cms_h(cms_to_persian_digits((string) $kpis['active_week'])) ?></span>
    <span class="cms-kpi__label">فعال این هفته</span>
  </div>
  <a class="cms-kpi<?= $filter === 'dormant' ? ' is-active' : '' ?>" href="<?= cms_h(mechanics_cms_list_qs('dormant', $searchQ)) ?>">
    <span class="cms-kpi__value"><?= cms_h(cms_to_persian_digits((string) $kpis['dormant_30'])) ?></span>
    <span class="cms-kpi__label">راکد ۳۰ روز</span>
  </a>
</div>

<div class="cms-filter-row">
  <?php foreach ($filterTabs as $key => $label): ?>
    <a class="cms-chip<?= $filter === $key ? ' is-active' : '' ?>" href="<?= cms_h(mechanics_cms_list_qs($key, $searchQ)) ?>"><?= cms_h($label) ?></a>
  <?php endforeach; ?>
</div>

<form class="cms-search" method="get" action="mechanics.php">
  <input type="hidden" name="status" value="<?= cms_h($filter) ?>">
  <input class="cms-input" type="search" name="q" value="<?= cms_h($searchQ) ?>" placeholder="نام تعمیرگاه، مکانیک، شهر یا موبایل">
  <button class="cms-btn" type="submit">جستجو</button>
  <?php if ($searchQ !== ''): ?>
    <a class="cms-btn cms-btn--ghost" href="<?= cms_h(mechanics_cms_list_qs($filter)) ?>">پاک کردن</a>
  <?php endif; ?>
</form>

<?php if ($items === []): ?>
  <div class="cms-panel">
    <p class="cms-empty">مکانیکی با این فیلتر پیدا نشد.</p>
  </div>
<?php else: ?>
  <div class="cms-mech-list">
    <?php foreach ($items as $item):
      $st = (string) $item['status'];
      $heat = (string) $item['heat'];
      $score = (int) $item['activity_score'];
      $actions = mechanics_status_actions($st);
      ?>
      <article class="cms-mech-card<?= $st === 'pending' ? ' cms-mech-card--pending' : '' ?>">
        <div class="cms-mech-card__main">
          <div class="cms-mech-card__identity">
            <h2 class="cms-mech-card__title"><?= cms_h((string) $item['workshop_name']) ?></h2>
            <p class="cms-muted" style="margin:.15rem 0 0">
              <?= cms_h((string) $item['owner_name']) ?>
              · <?= cms_h((string) $item['city']) ?>
              · <span dir="ltr"><?= cms_h(cms_to_persian_digits((string) $item['phone'])) ?></span>
            </p>
          </div>
          <div class="cms-mech-card__meta">
            <span class="cms-pill cms-pill--<?= cms_h($st) ?>"><?= cms_h($statusLabels[$st] ?? $st) ?></span>
            <span class="cms-heat cms-heat--<?= cms_h($heat) ?>"><?= cms_h($heatLabels[$heat] ?? $heat) ?></span>
          </div>
        </div>

        <div class="cms-mech-card__stats">
          <div class="cms-activity">
            <div class="cms-activity__bar" aria-hidden="true">
              <span style="width:<?= (int) $score ?>%"></span>
            </div>
            <span class="cms-activity__label">فعالیت <?= cms_h(cms_to_persian_digits((string) $score)) ?></span>
          </div>
          <span class="cms-muted">
            <?= cms_h(cms_to_persian_digits((string) $item['customer_count'])) ?> مشتری
            · <?= cms_h(cms_to_persian_digits((string) $item['service_count'])) ?> سرویس
            · آخرین: <?= cms_h(cms_to_persian_digits(cms_jalali_format_date($item['last_service_at'] !== null ? (string) $item['last_service_at'] : null))) ?>
          </span>
        </div>

        <div class="cms-mech-card__actions">
          <?php foreach ($actions as $action): ?>
            <form method="post" class="cms-mech-action">
              <input type="hidden" name="mechanic_status_action" value="1">
              <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
              <input type="hidden" name="action" value="<?= cms_h($action) ?>">
              <input type="hidden" name="return_status" value="<?= cms_h($filter) ?>">
              <input type="hidden" name="return_q" value="<?= cms_h($searchQ) ?>">
              <?php if ($action === 'reject'): ?>
                <input class="cms-input cms-input--compact" name="status_note" placeholder="دلیل رد" required>
              <?php endif; ?>
              <button class="<?= cms_h(mechanics_action_btn_class($action)) ?>" type="submit"><?= cms_h($actionLabels[$action] ?? $action) ?></button>
            </form>
          <?php endforeach; ?>
          <a class="cms-btn cms-btn--secondary" href="mechanic-view.php?id=<?= (int) $item['id'] ?>">جزئیات</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php cms_layout_end(); ?>
