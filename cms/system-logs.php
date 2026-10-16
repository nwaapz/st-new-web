<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/application-logs.php';
require_once __DIR__ . '/lib/admin-audit.php';

cms_require_login();
$pdo = cms_pdo();
application_logs_ensure_schema($pdo);

$username = cms_current_username();
if (!application_logs_can_view($username)) {
    cms_layout_start('گزارش رویدادهای سیستم', $username, 'advanced');
    echo '<h1 style="margin-top:0">گزارش رویدادهای سیستم</h1>';
    echo '<p class="cms-error">دسترسی به این بخش برای حساب شما فعال نیست.</p>';
    cms_layout_end();
    exit;
}

$pageSize = 40;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$detailId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$filters = [
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to' => trim((string) ($_GET['to'] ?? '')),
    'severity' => trim((string) ($_GET['severity'] ?? '')),
    'source' => trim((string) ($_GET['source'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'event_code' => trim((string) ($_GET['event_code'] ?? '')),
    'user_id' => trim((string) ($_GET['user_id'] ?? '')),
    'order_id' => trim((string) ($_GET['order_id'] ?? '')),
    'correlation_id' => trim((string) ($_GET['correlation_id'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
];

$listQs = static function (array $extra = []) use ($filters, $page): string {
    $params = array_filter($filters + [
        'page' => $page > 1 ? (string) $page : null,
    ] + $extra, static fn ($v) => $v !== null && $v !== '');
    $qs = http_build_query($params);

    return 'system-logs.php' . ($qs !== '' ? '?' . $qs : '');
};

$error = '';
$list = ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
$summary = [
    'total' => 0,
    'by_severity' => array_fill_keys(application_logs_severities(), 0),
    'recent_critical' => [],
    'recent_sync_failures' => [],
    'recent_notify_failures' => [],
];
$detail = null;
$related = [];

try {
    $list = application_logs_list($pdo, $filters, $page, $pageSize);
    $summary = application_logs_summary($pdo, $filters);
    if ($detailId > 0) {
        $detail = application_logs_get($pdo, $detailId);
        if ($detail !== null) {
            $corr = trim((string) ($detail['correlation_id'] ?? ''));
            if ($corr !== '') {
                $related = application_logs_correlation_siblings($pdo, $corr, $detailId);
            }
            if (!empty($detail['is_sensitive'])) {
                cms_admin_audit($pdo, 'app_log.view', [
                    'entity_type' => 'app_log',
                    'entity_id' => $detailId,
                    'entity_label' => (string) ($detail['event_id'] ?? ''),
                    'summary' => $username . ' جزئیات رویداد سیستم را مشاهده کرد',
                    'detail' => ['event_code' => $detail['event_code'] ?? '', 'severity' => $detail['severity'] ?? ''],
                ]);
            }
        }
    }
} catch (Throwable $e) {
    $error = 'خواندن گزارش رویدادها ناموفق بود.';
    error_log('[system-logs] ' . $e->getMessage());
}

cms_layout_start('گزارش رویدادهای سیستم', $username, 'advanced');

$severityOptions = ['' => 'همه'] + application_logs_severity_labels();
$sourceOptions = ['' => 'همه'] + application_logs_source_labels();
$categoryOptions = ['' => 'همه'] + application_logs_category_labels();
?>
<h1 style="margin-top:0">گزارش رویدادهای سیستم</h1>
<p class="cms-muted">رویدادها، هشدارها و خطاهای عملیاتی اپ‌ها و سرور. زمان‌ها به تقویم جلالی و منطقهٔ زمانی تهران نمایش داده می‌شوند. زمان ذخیره‌شده در پایگاه UTC است.</p>

<?php if ($error !== ''): ?>
  <p class="cms-error"><?= cms_h($error) ?></p>
<?php endif; ?>

<div class="cms-log-summary">
  <div class="cms-log-stat">
    <span class="cms-log-stat__label">تعداد مطابق فیلتر</span>
    <span class="cms-log-stat__value"><?= cms_h(cms_to_persian_digits((string) $summary['total'])) ?></span>
  </div>
  <?php foreach (application_logs_severities() as $sev): ?>
    <div class="cms-log-stat">
      <span class="cms-log-stat__label"><?= cms_h(application_logs_severity_labels()[$sev]) ?></span>
      <span class="cms-log-stat__value"><?= cms_h(cms_to_persian_digits((string) ($summary['by_severity'][$sev] ?? 0))) ?></span>
    </div>
  <?php endforeach; ?>
</div>

<div class="cms-panel" style="margin-bottom:1rem">
  <h2 style="margin-top:0">خطاهای اخیر</h2>
  <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr))">
    <?php
    $miniGroups = [
        'بحرانی' => $summary['recent_critical'],
        'همگام‌سازی' => $summary['recent_sync_failures'],
        'پیامک / پوش' => $summary['recent_notify_failures'],
    ];
    foreach ($miniGroups as $title => $rows):
    ?>
      <div>
        <h3 style="margin:0 0 .4rem;font-size:.95rem"><?= cms_h($title) ?></h3>
        <?php if ($rows === []): ?>
          <p class="cms-empty" style="margin:0">موردی نیست.</p>
        <?php else: ?>
          <ul class="cms-log-mini-list">
            <?php foreach ($rows as $mini): ?>
              <li>
                <span class="cms-severity cms-severity--<?= cms_h((string) $mini['severity']) ?>"><?= cms_h((string) $mini['severity_label']) ?></span>
                <a href="<?= cms_h($listQs(['id' => (string) $mini['id'], 'page' => (string) $list['page']])) ?>">
                  <?= cms_h((string) $mini['summary']) ?>
                </a>
                <div class="cms-muted" dir="ltr"><?= cms_h(cms_to_persian_digits((string) $mini['occurred_at_jalali'])) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<form method="get" class="cms-form cms-form--inline cms-log-filters" style="margin-bottom:1rem">
  <label class="cms-field">
    <span>از تاریخ جلالی</span>
    <input class="cms-input" name="from" dir="ltr" value="<?= cms_h($filters['from']) ?>" placeholder="1405/01/01">
  </label>
  <label class="cms-field">
    <span>تا تاریخ جلالی</span>
    <input class="cms-input" name="to" dir="ltr" value="<?= cms_h($filters['to']) ?>" placeholder="1405/12/29">
  </label>
  <label class="cms-field">
    <span>شدت</span>
    <select class="cms-input" name="severity">
      <?php foreach ($severityOptions as $key => $label): ?>
        <option value="<?= cms_h((string) $key) ?>" <?= $filters['severity'] === (string) $key ? 'selected' : '' ?>><?= cms_h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="cms-field">
    <span>منبع</span>
    <select class="cms-input" name="source">
      <?php foreach ($sourceOptions as $key => $label): ?>
        <option value="<?= cms_h((string) $key) ?>" <?= $filters['source'] === (string) $key ? 'selected' : '' ?>><?= cms_h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="cms-field">
    <span>دسته</span>
    <select class="cms-input" name="category">
      <?php foreach ($categoryOptions as $key => $label): ?>
        <option value="<?= cms_h((string) $key) ?>" <?= $filters['category'] === (string) $key ? 'selected' : '' ?>><?= cms_h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="cms-field">
    <span>کد رویداد</span>
    <input class="cms-input" name="event_code" dir="ltr" value="<?= cms_h($filters['event_code']) ?>">
  </label>
  <label class="cms-field">
    <span>شناسه کاربر</span>
    <input class="cms-input" name="user_id" dir="ltr" value="<?= cms_h($filters['user_id']) ?>">
  </label>
  <label class="cms-field">
    <span>شناسه سفارش</span>
    <input class="cms-input" name="order_id" dir="ltr" value="<?= cms_h($filters['order_id']) ?>">
  </label>
  <label class="cms-field">
    <span>شناسه همبستگی</span>
    <input class="cms-input" name="correlation_id" dir="ltr" value="<?= cms_h($filters['correlation_id']) ?>">
  </label>
  <label class="cms-field">
    <span>جستجو</span>
    <input class="cms-input" name="q" value="<?= cms_h($filters['q']) ?>" placeholder="خلاصه یا کد رویداد">
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">فیلتر</button>
    <a class="cms-btn cms-btn--secondary" href="system-logs.php">پاک کردن</a>
    <a class="cms-btn cms-btn--ghost" href="<?= cms_h($listQs(['id' => $detailId > 0 ? (string) $detailId : null])) ?>">نوسازی</a>
  </div>
</form>

<?php if ($detail !== null): ?>
  <div class="cms-panel cms-log-detail" style="margin-bottom:1rem">
    <h2 style="margin-top:0">جزئیات رویداد</h2>
    <p>
      <span class="cms-severity cms-severity--<?= cms_h((string) $detail['severity']) ?>"><?= cms_h((string) $detail['severity_label']) ?></span>
      <?= cms_h((string) $detail['source_label']) ?>
      · <?= cms_h((string) $detail['category_label']) ?>
      · <span class="cms-log-id"><?= cms_h((string) $detail['event_code']) ?></span>
    </p>
    <p><?= cms_h((string) $detail['summary']) ?></p>
    <p>
      <strong>زمان سرور:</strong>
      <span dir="ltr"><?= cms_h(cms_to_persian_digits((string) $detail['occurred_at_jalali'])) ?></span>
      <span class="cms-muted">(UTC <?= cms_h((string) $detail['occurred_at']) ?>)</span>
    </p>
    <p>
      <strong>زمان ثبت در سرور:</strong>
      <span dir="ltr"><?= cms_h(cms_to_persian_digits((string) $detail['ingested_at_jalali'])) ?></span>
    </p>
    <?php if (!empty($detail['client_occurred_at_jalali'])): ?>
      <p>
        <strong>زمان دستگاه (غیرقابل اعتماد):</strong>
        <span dir="ltr"><?= cms_h(cms_to_persian_digits((string) $detail['client_occurred_at_jalali'])) ?></span>
        <?php if (!empty($detail['client_timezone'])): ?>
          <span class="cms-muted"><?= cms_h((string) $detail['client_timezone']) ?></span>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <p>
      <strong>شناسه رویداد:</strong>
      <code class="cms-log-id" id="cms-log-event-id"><?= cms_h((string) $detail['event_id']) ?></code>
      <button type="button" class="cms-btn cms-btn--ghost" data-copy="cms-log-event-id">کپی</button>
    </p>
    <?php if (!empty($detail['correlation_id'])): ?>
      <p>
        <strong>شناسه همبستگی:</strong>
        <code class="cms-log-id" id="cms-log-corr-id"><?= cms_h((string) $detail['correlation_id']) ?></code>
        <button type="button" class="cms-btn cms-btn--ghost" data-copy="cms-log-corr-id">کپی</button>
      </p>
    <?php endif; ?>
    <?php if (!empty($detail['related_order_id'])): ?>
      <p>سفارش: <a href="orders.php?id=<?= (int) $detail['related_order_id'] ?>">#<?= (int) $detail['related_order_id'] ?></a></p>
    <?php endif; ?>
    <?php if (!empty($detail['user_label']) || !empty($detail['user_id'])): ?>
      <p>کاربر: <?= cms_h((string) ($detail['user_label'] ?: '')) ?>
        <?php if (!empty($detail['user_id'])): ?>
          <span class="cms-log-id">(<?= (int) $detail['user_id'] ?>)</span>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if (!empty($detail['was_offline'])): ?>
      <p class="cms-muted">این رویداد ابتدا آفلاین در دستگاه صف شد و بعداً ارسال گردید.</p>
    <?php endif; ?>
    <?php if (!empty($detail['metadata'])): ?>
      <h3>فراداده</h3>
      <pre><?= cms_h(is_string($detail['metadata']) ? $detail['metadata'] : (string) json_encode($detail['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
    <?php endif; ?>
    <?php if (!empty($detail['exception_detail'])): ?>
      <h3>جزئیات فنی</h3>
      <p class="cms-muted"><?= cms_h((string) ($detail['exception_class'] ?? '')) ?></p>
      <pre><?= cms_h((string) $detail['exception_detail']) ?></pre>
    <?php endif; ?>
    <?php if ($related !== []): ?>
      <h3>رویدادهای مرتبط با همین شناسه همبستگی</h3>
      <ul class="cms-log-mini-list">
        <?php foreach ($related as $sib): ?>
          <li>
            <span dir="ltr"><?= cms_h(cms_to_persian_digits((string) $sib['occurred_at_jalali'])) ?></span>
            · <?= cms_h((string) $sib['event_code']) ?>
            · <a href="<?= cms_h($listQs(['id' => (string) $sib['id']])) ?>"><?= cms_h((string) $sib['summary']) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p><a class="cms-btn cms-btn--secondary" href="<?= cms_h($listQs(['id' => null])) ?>">بستن جزئیات</a></p>
  </div>
<?php elseif ($detailId > 0): ?>
  <p class="cms-error">رویداد مورد نظر یافت نشد.</p>
<?php endif; ?>

<div class="cms-panel">
  <?php if ($list['items'] === []): ?>
    <p class="cms-empty">رویدادی با این فیلترها ثبت نشده است.</p>
  <?php else: ?>
    <p class="cms-muted" style="margin-top:0">
      <?= cms_h(cms_to_persian_digits((string) $list['total'])) ?> مورد —
      صفحه <?= cms_h(cms_to_persian_digits((string) $list['page'])) ?>
      از <?= cms_h(cms_to_persian_digits((string) $list['total_pages'])) ?>
    </p>
    <div class="cms-log-table-wrap">
      <table class="cms-table">
        <thead>
          <tr>
            <th>زمان سرور</th>
            <th>شدت</th>
            <th>منبع</th>
            <th>دسته / کد</th>
            <th>خلاصه</th>
            <th>مرتبط</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($list['items'] as $row): ?>
          <tr>
            <td dir="ltr"><?= cms_h(cms_to_persian_digits((string) $row['occurred_at_jalali'])) ?></td>
            <td><span class="cms-severity cms-severity--<?= cms_h((string) $row['severity']) ?>"><?= cms_h((string) $row['severity_label']) ?></span></td>
            <td><?= cms_h((string) $row['source_label']) ?></td>
            <td>
              <?= cms_h((string) $row['category_label']) ?>
              <div class="cms-muted cms-log-id"><?= cms_h((string) $row['event_code']) ?></div>
            </td>
            <td class="cms-log-summary-cell"><?= cms_h((string) $row['summary']) ?></td>
            <td>
              <?php if (!empty($row['related_order_id'])): ?>
                <a href="orders.php?id=<?= (int) $row['related_order_id'] ?>">سفارش <?= (int) $row['related_order_id'] ?></a>
              <?php elseif (!empty($row['user_label'])): ?>
                <?= cms_h((string) $row['user_label']) ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td><a class="cms-btn cms-btn--ghost" href="<?= cms_h($listQs(['id' => (string) $row['id'], 'page' => (string) $list['page']])) ?>">جزئیات</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($list['total_pages'] > 1): ?>
      <div class="cms-btn-row cms-orders-pager" style="margin-top:1rem">
        <?php if ($list['page'] > 1): ?>
          <a class="cms-btn cms-btn--secondary" href="<?= cms_h($listQs(['page' => (string) ($list['page'] - 1), 'id' => $detailId > 0 ? (string) $detailId : null])) ?>">قبلی</a>
        <?php endif; ?>
        <?php if ($list['page'] < $list['total_pages']): ?>
          <a class="cms-btn cms-btn--secondary" href="<?= cms_h($listQs(['page' => (string) ($list['page'] + 1), 'id' => $detailId > 0 ? (string) $detailId : null])) ?>">بعدی</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<script>
(function () {
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var el = document.getElementById(btn.getAttribute('data-copy'));
      if (!el) return;
      var text = el.textContent || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
      }
    });
  });
})();
</script>
<?php cms_layout_end(); ?>
