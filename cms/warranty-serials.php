<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/warranty.php';

cms_require_login();
$pdo = cms_pdo();
warranty_ensure_schema($pdo);

$filter = isset($_GET['status']) && in_array($_GET['status'], ['pending', 'registered'], true)
    ? $_GET['status']
    : '';

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM warranty_serials WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('سریال حذف شد');
    cms_redirect('warranty-serials.php' . ($filter !== '' ? '?status=' . $filter : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = (string) ($_POST['serials'] ?? '');
    $kindOverride = (string) ($_POST['kind_override'] ?? 'auto');
    $lines = preg_split('/[\r\n]+/', $raw) ?: [];

    $added = 0;
    $skippedDup = 0;
    $skippedInvalid = 0;

    foreach ($lines as $line) {
        $serial = warranty_normalize_serial((string) $line);
        if ($serial === '') {
            continue;
        }

        $kind = $kindOverride === 'mcode' || $kindOverride === 'old_serial'
            ? $kindOverride
            : warranty_detect_kind($serial);

        if ($kind !== 'mcode' && $kind !== 'old_serial') {
            $skippedInvalid++;
            continue;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO warranty_serials (serial, kind, status) VALUES (?, ?, \'pending\')'
            );
            $stmt->execute([$serial, $kind]);
            $added++;
        } catch (Throwable $e) {
            $skippedDup++;
        }
    }

    cms_flash("افزوده شد: {$added} — تکراری: {$skippedDup} — نامعتبر: {$skippedInvalid}");
    cms_redirect('warranty-serials.php' . ($filter !== '' ? '?status=' . $filter : ''));
}

$sql = 'SELECT * FROM warranty_serials';
$params = [];
if ($filter !== '') {
    $sql .= ' WHERE status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY updated_at DESC, id DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$counts = [
    'all' => (int) $pdo->query('SELECT COUNT(*) FROM warranty_serials')->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM warranty_serials WHERE status = 'pending'")->fetchColumn(),
    'registered' => (int) $pdo->query("SELECT COUNT(*) FROM warranty_serials WHERE status = 'registered'")->fetchColumn(),
];

$viewId = isset($_GET['view']) ? (int) $_GET['view'] : 0;
$viewItem = null;
if ($viewId > 0) {
    $viewStmt = $pdo->prepare('SELECT * FROM warranty_serials WHERE id = ? LIMIT 1');
    $viewStmt->execute([$viewId]);
    $viewItem = $viewStmt->fetch() ?: null;
}

/**
 * @return string
 */
function warranty_serials_list_qs(string $filter, ?int $viewId = null): string
{
    $qs = [];
    if ($filter !== '') {
        $qs['status'] = $filter;
    }
    if ($viewId !== null) {
        $qs['view'] = $viewId;
    }
    return 'warranty-serials.php' . ($qs !== [] ? '?' . http_build_query($qs) : '');
}

$listCloseHref = warranty_serials_list_qs($filter);

cms_layout_start('سریال‌های گارانتی', cms_current_username(), 'advanced');
?>
<div class="cms-warning" style="margin-bottom:1rem">
  <strong>منسوخ — فقط آرشیو</strong>
  صفحه گارانتی سایت و SMS اکنون مستقیماً از
  <span dir="ltr">startech_sms.old_serials</span>
  (پنل CRM) می‌خوانند. افزودن سریال در اینجا دیگر روی سایت اثر ندارد.
</div>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">سریال‌های گارانتی (CMS — منسوخ)</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">جدول قدیمی <span dir="ltr">warranty_serials</span> — فقط برای مراجعه به داده‌های قبلی.</p>
  </div>
</div>

<form class="cms-panel" method="post">
  <h2>افزودن سریال‌های جدید</h2>
  <label class="cms-field">
    <span class="cms-label">لیست سریال‌ها (هر خط یک سریال)</span>
    <textarea class="cms-textarea" name="serials" rows="6" dir="ltr" placeholder="M12345&#10;A98765&#10;B45210" required></textarea>
  </label>
  <label class="cms-field">
    <span class="cms-label">نوع</span>
    <select class="cms-select" name="kind_override">
      <option value="auto">تشخیص خودکار از روی حرف اول (M → فروشگاهی، حرف دیگر → سریال)</option>
      <option value="mcode">فروشگاهی (M) — همه به‌عنوان mcode</option>
      <option value="old_serial">سریال — همه به‌عنوان old_serial</option>
    </select>
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">افزودن</button>
  </div>
</form>

<div class="cms-panel">
  <div class="cms-page-head" style="margin-bottom:1rem">
    <div class="cms-btn-row" style="margin-top:0">
      <a class="cms-btn <?= $filter === '' ? '' : 'cms-btn--secondary' ?>" href="warranty-serials.php">همه (<?= $counts['all'] ?>)</a>
      <a class="cms-btn <?= $filter === 'pending' ? '' : 'cms-btn--secondary' ?>" href="warranty-serials.php?status=pending">در انتظار ثبت (<?= $counts['pending'] ?>)</a>
      <a class="cms-btn <?= $filter === 'registered' ? '' : 'cms-btn--secondary' ?>" href="warranty-serials.php?status=registered">ثبت‌شده (<?= $counts['registered'] ?>)</a>
    </div>
  </div>

  <?php if ($items === []): ?>
    <p class="cms-empty">موردی یافت نشد.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead>
      <tr>
        <th>سریال</th>
        <th>نوع</th>
        <th>وضعیت</th>
        <th>موبایل</th>
        <th>شهر</th>
        <th>کیلومتر</th>
        <th>پلاک</th>
        <th>تاریخ ثبت</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr class="cms-warranty-row<?= $viewId === (int) $item['id'] ? ' is-open' : '' ?>"
          data-href="<?= cms_h(warranty_serials_list_qs($filter, (int) $item['id'])) ?>"
          tabindex="0"
          role="link">
        <td dir="ltr"><?= cms_h($item['serial']) ?></td>
        <td><?= cms_h(warranty_kind_label((string) $item['kind'])) ?></td>
        <td><?= $item['status'] === 'registered' ? 'ثبت‌شده' : 'در انتظار' ?></td>
        <td dir="ltr"><?= cms_h((string) ($item['phone'] ?? '—')) ?></td>
        <td><?= cms_h((string) ($item['city'] ?? '—')) ?></td>
        <td><?= cms_h((string) ($item['km'] ?? '—')) ?></td>
        <td dir="ltr"><?= cms_h((string) ($item['car_plate'] ?? '—')) ?></td>
        <td><?= cms_h((string) ($item['registered_at'] ?? '—')) ?></td>
        <td>
          <a class="cms-btn cms-btn--ghost" href="warranty-serials.php?delete=<?= (int) $item['id'] ?><?= $filter !== '' ? '&status=' . $filter : '' ?>" onclick="event.stopPropagation(); return confirm('حذف این سریال؟')">حذف</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
(function () {
  document.querySelectorAll('.cms-warranty-row[data-href]').forEach(function (row) {
    function go(e) {
      if (e && e.target && e.target.closest('a, button')) return;
      var href = row.getAttribute('data-href');
      if (href) window.location.href = href;
    }
    row.addEventListener('click', go);
    row.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        go();
      }
    });
  });
})();
</script>

<?php if ($viewItem): ?>
  <div class="cms-warranty-modal" role="presentation">
    <div class="cms-warranty-modal__panel" role="dialog" aria-modal="true" aria-labelledby="cms-warranty-modal-title">
      <header class="cms-warranty-modal__head">
        <div>
          <h2 id="cms-warranty-modal-title" style="margin:0">جزئیات گارانتی</h2>
          <p class="cms-muted" dir="ltr" style="margin:.35rem 0 0"><?= cms_h((string) $viewItem['serial']) ?></p>
        </div>
        <a class="cms-btn cms-btn--secondary" id="cms-warranty-modal-close" href="<?= cms_h($listCloseHref) ?>">بستن</a>
      </header>
      <div class="cms-warranty-modal__body">
        <div class="cms-warranty-modal__rows">
          <div class="cms-warranty-modal__row">
            <span>کد سریال</span>
            <strong dir="ltr"><?= cms_h((string) $viewItem['serial']) ?></strong>
          </div>
          <div class="cms-warranty-modal__row">
            <span>نوع</span>
            <strong><?= cms_h(warranty_kind_label((string) $viewItem['kind'])) ?></strong>
          </div>
          <div class="cms-warranty-modal__row">
            <span>وضعیت</span>
            <strong><?= $viewItem['status'] === 'registered' ? 'ثبت‌شده' : 'در انتظار' ?></strong>
          </div>
          <div class="cms-warranty-modal__row">
            <span>موبایل</span>
            <strong dir="ltr"><?= cms_h((string) ($viewItem['phone'] ?? '—')) ?></strong>
          </div>
          <div class="cms-warranty-modal__row">
            <span>شهر</span>
            <strong><?= cms_h((string) ($viewItem['city'] ?? '—')) ?></strong>
          </div>
          <div class="cms-warranty-modal__row">
            <span>کیلومتراژ</span>
            <strong><?= cms_h((string) ($viewItem['km'] ?? '—')) ?></strong>
          </div>
          <div class="cms-warranty-modal__row">
            <span>پلاک خودرو</span>
            <strong dir="ltr"><?= cms_h((string) ($viewItem['car_plate'] ?? '—')) ?></strong>
          </div>
          <div class="cms-warranty-modal__row">
            <span>تاریخ ثبت</span>
            <strong dir="ltr"><?= cms_h((string) ($viewItem['registered_at'] ?? '—')) ?></strong>
          </div>
          <div class="cms-warranty-modal__row">
            <span>تاریخ ایجاد سریال</span>
            <strong dir="ltr"><?= cms_h((string) ($viewItem['created_at'] ?? '—')) ?></strong>
          </div>
        </div>
        <div class="cms-btn-row" style="margin-top:1.25rem">
          <a class="cms-btn cms-btn--ghost" href="warranty-serials.php?delete=<?= (int) $viewItem['id'] ?><?= $filter !== '' ? '&status=' . $filter : '' ?>" onclick="return confirm('حذف این سریال؟')">حذف این سریال</a>
        </div>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var modal = document.querySelector('.cms-warranty-modal');
    var closeHref = <?= json_encode($listCloseHref, JSON_UNESCAPED_UNICODE) ?>;
    if (!modal) return;
    modal.addEventListener('click', function (e) {
      if (e.target === modal) window.location.href = closeHref;
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') window.location.href = closeHref;
    });
  })();
  </script>
<?php endif; ?>

<p class="cms-muted" style="margin-top:1rem">
  <a href="advanced.php">بازگشت به تنظیمات پیشرفته</a>
</p>
<?php cms_layout_end(); ?>
