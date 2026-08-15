<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/mechanics.php';
require_once __DIR__ . '/lib/mechanic-broadcasts.php';
require_once __DIR__ . '/lib/jalali.php';
require_once __DIR__ . '/lib/seller-credit.php';

cms_require_login();
$pdo = cms_pdo();
mechanics_ensure_schema($pdo);
mechanic_broadcasts_ensure_schema($pdo);

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : 'pending';
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}

function mechanic_broadcasts_cms_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $broadcastId = (int) ($_POST['id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? ''));
    $ajax = trim((string) ($_POST['ajax'] ?? '')) === '1';
    try {
        if ($broadcastId <= 0) {
            throw new RuntimeException('پیام نامعتبر');
        }
        $row = mechanic_broadcast_find($pdo, $broadcastId);
        if ($row === null) {
            throw new RuntimeException('پیام گروهی یافت نشد');
        }
        if ($action === 'approve') {
            mechanic_broadcast_approve($pdo, $row);
            if ($ajax) {
                $fresh = mechanic_broadcast_find($pdo, $broadcastId);
                mechanic_broadcasts_cms_json(['ok' => true, 'item' => $fresh]);
            }
            cms_flash('پیام تأیید شد و تعداد پیامک محاسبه شد');
        } elseif ($action === 'reject') {
            mechanic_broadcast_reject($pdo, $row, (string) ($_POST['reject_reason'] ?? ''));
            cms_flash('پیام رد شد');
        } elseif ($action === 'send_batch') {
            $result = mechanic_broadcast_send_batch($pdo, $broadcastId, 20);
            $fresh = mechanic_broadcast_find($pdo, $broadcastId);
            $phoneCustomers = mechanic_broadcast_phone_customers_count($pdo, (int) $fresh['mechanic_id']);
            if ($ajax) {
                mechanic_broadcasts_cms_json([
                    'ok' => true,
                    'batch' => $result,
                    'item' => mechanic_broadcast_serialize($pdo, $fresh, $phoneCustomers),
                ]);
            }
            if ($result['completed']) {
                cms_flash('ارسال گروهی تمام شد');
            } elseif (!empty($result['outside_hours'])) {
                cms_flash($result['error'] ?? 'ارسال پیامک باشگاه مشتریان فقط از ساعت ۹ صبح تا ۹ شب امکان‌پذیر است.', 'error');
            } elseif ($result['paused']) {
                cms_flash($result['error'] ?? 'ارسال به‌خاطر کمبود اعتبار متوقف شد', 'error');
            } else {
                cms_flash('یک دسته ارسال شد');
            }
        } else {
            throw new RuntimeException('عملیات نامعتبر');
        }
    } catch (Throwable $e) {
        if ($ajax) {
            mechanic_broadcasts_cms_json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        cms_flash($e->getMessage(), 'error');
    }
    $redir = 'mechanic-broadcasts.php';
    if ($statusFilter !== 'pending') {
        $redir .= '?status=' . rawurlencode($statusFilter);
    }
    cms_redirect($redir);
}

$counts = [
    'pending' => 0,
    'rejected' => 0,
    'approved' => 0,
];
$cStmt = $pdo->query(
    "SELECT status, COUNT(*) AS n FROM mechanic_broadcasts GROUP BY status"
);
foreach ($cStmt ? ($cStmt->fetchAll() ?: []) : [] as $crow) {
    $st = (string) $crow['status'];
    $n = (int) $crow['n'];
    if ($st === 'pending') {
        $counts['pending'] += $n;
    } elseif ($st === 'rejected') {
        $counts['rejected'] += $n;
    } elseif (in_array($st, ['approved', 'sending', 'paused', 'completed'], true)) {
        $counts['approved'] += $n;
    }
}

$items = mechanic_broadcast_list_all($pdo, $statusFilter);
$smsWindow = mechanic_sms_send_window();

$statusLabels = [
    'draft' => 'پیش‌نویس',
    'pending' => 'در انتظار تأیید',
    'approved' => 'تأیید شده',
    'rejected' => 'رد شده',
    'sending' => 'در حال ارسال',
    'paused' => 'متوقف (اعتبار)',
    'completed' => 'ارسال شده',
];

cms_layout_start('پیام گروهی مکانیک‌ها', cms_current_username(), 'website');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">پیام گروهی باشگاه مشتریان</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">پس از تأیید، متن قفل می‌شود و تعداد پیامک بر اساس فارسی/انگلیسی محاسبه می‌گردد</p>
    <?php if (empty($smsWindow['ok'])): ?>
      <p class="cms-error" style="margin:.6rem 0 0"><?= cms_h((string) ($smsWindow['error'] ?? 'ارسال پیامک باشگاه مشتریان فقط از ساعت ۹ صبح تا ۹ شب امکان‌پذیر است.')) ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="cms-btn-row" style="margin-bottom:1rem">
  <a class="cms-btn <?= $statusFilter === 'pending' ? '' : 'cms-btn--secondary' ?>" href="mechanic-broadcasts.php?status=pending">
    در انتظار (<?= (int) $counts['pending'] ?>)
  </a>
  <a class="cms-btn <?= $statusFilter === 'approved' ? '' : 'cms-btn--secondary' ?>" href="mechanic-broadcasts.php?status=approved">
    تأییدشده (<?= (int) $counts['approved'] ?>)
  </a>
  <a class="cms-btn <?= $statusFilter === 'rejected' ? '' : 'cms-btn--secondary' ?>" href="mechanic-broadcasts.php?status=rejected">
    رد شده (<?= (int) $counts['rejected'] ?>)
  </a>
  <a class="cms-btn <?= $statusFilter === 'all' ? '' : 'cms-btn--secondary' ?>" href="mechanic-broadcasts.php?status=all">همه</a>
</div>

<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">پیامی در این وضعیت نیست.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead>
      <tr>
        <th>تعمیرگاه</th>
        <th>متن</th>
        <th>مخاطب</th>
        <th>پیامک</th>
        <th>وضعیت</th>
        <th>تاریخ</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <?php
        $bid = (int) $item['id'];
        $st = (string) $item['status'];
        $sendable = !empty($item['sendable']);
        $segments = (int) $item['segments'];
        $recipients = (int) $item['recipient_count'];
        $smsTotal = (int) $item['sms_total'];
      ?>
      <tr>
        <td>
          <strong><?= cms_h((string) $item['workshop_name']) ?></strong><br>
          <span class="cms-muted"><?= cms_h((string) $item['owner_name']) ?></span>
        </td>
        <td style="max-width:280px;white-space:pre-wrap"><?= cms_h((string) $item['body']) ?></td>
        <td>
          <?php if (in_array($st, ['approved', 'sending', 'paused', 'completed'], true)): ?>
            <?= (int) $item['sent_count'] ?> / <?= $recipients ?> ارسال
            <?php if ((int) $item['pending_count'] > 0): ?>
              <br><span class="cms-muted"><?= (int) $item['pending_count'] ?> باقی</span>
            <?php endif; ?>
          <?php else: ?>
            <?= (int) $item['audience_count'] ?> نفر
            <?php if (count($item['exempts']) > 0): ?>
              <br><span class="cms-muted"><?= count($item['exempts']) ?> مستثنا</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if (in_array($st, ['approved', 'sending', 'paused', 'completed'], true) && $segments > 0): ?>
            <?= $segments ?> بخش × <?= $recipients ?><br>
            <strong><?= $smsTotal ?></strong> پیامک
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td>
          <?= cms_h($statusLabels[$st] ?? $st) ?>
          <?php if ($st === 'rejected' && trim((string) ($item['reject_reason'] ?? '')) !== ''): ?>
            <br><span class="cms-muted" style="white-space:pre-wrap"><?= cms_h((string) $item['reject_reason']) ?></span>
          <?php endif; ?>
        </td>
        <td dir="ltr"><?= cms_h(cms_jalali_format_from_timestamp((string) ($item['created_at'] ?? ''))) ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0;flex-wrap:wrap">
            <?php if ($st === 'pending'): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?= $bid ?>">
                <input type="hidden" name="action" value="approve">
                <button class="cms-btn cms-btn--secondary" type="submit">تأیید</button>
              </form>
              <form method="post" style="display:grid;gap:.4rem;min-width:12rem">
                <input type="hidden" name="id" value="<?= $bid ?>">
                <input type="hidden" name="action" value="reject">
                <textarea class="cms-textarea" name="reject_reason" rows="2" required placeholder="دلیل رد"></textarea>
                <button class="cms-btn cms-btn--ghost" type="submit">رد</button>
              </form>
            <?php endif; ?>
            <?php if ($sendable): ?>
              <button
                class="cms-btn"
                type="button"
                data-broadcast-auto="<?= $bid ?>"
                <?= empty($smsWindow['ok']) ? 'disabled' : '' ?>
              >ارسال خودکار</button>
              <span class="cms-muted" id="cms-broadcast-status-<?= $bid ?>"></span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<script>
(function () {
  function setStatus(id, text) {
    var el = document.getElementById("cms-broadcast-status-" + id);
    if (el) el.textContent = text || "";
  }
  async function sendLoop(id, btn) {
    btn.disabled = true;
    var keepDisabled = false;
    try {
      while (true) {
        var fd = new FormData();
        fd.append("id", String(id));
        fd.append("action", "send_batch");
        fd.append("ajax", "1");
        var res = await fetch("mechanic-broadcasts.php", {
          method: "POST",
          body: fd,
          credentials: "same-origin",
        });
        var data = await res.json();
        if (!data || !data.ok) {
          setStatus(id, (data && data.error) || "خطا در ارسال");
          break;
        }
        var batch = data.batch || {};
        var item = data.item || {};
        setStatus(
          id,
          "ارسال " + (item.sent_count || 0) + " از " + (item.recipient_count || 0)
        );
        if (batch.completed) {
          setStatus(id, "ارسال تمام شد");
          window.location.reload();
          break;
        }
        if (batch.outside_hours) {
          keepDisabled = true;
          setStatus(id, batch.error || "ارسال پیامک باشگاه مشتریان فقط از ساعت ۹ صبح تا ۹ شب امکان‌پذیر است.");
          break;
        }
        if (batch.paused) {
          setStatus(id, batch.error || "اعتبار کافی نیست — ارسال متوقف شد");
          window.location.reload();
          break;
        }
        if (!batch.remaining) {
          window.location.reload();
          break;
        }
      }
    } catch (err) {
      setStatus(id, "خطای شبکه");
    } finally {
      if (!keepDisabled) btn.disabled = false;
    }
  }
  document.querySelectorAll("[data-broadcast-auto]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var id = btn.getAttribute("data-broadcast-auto");
      if (!id) return;
      sendLoop(id, btn);
    });
  });
})();
</script>
<?php cms_layout_end(); ?>
