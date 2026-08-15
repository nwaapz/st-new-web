<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/branches.php';
require_once __DIR__ . '/lib/branch-tickets.php';

cms_require_login();
$pdo = cms_pdo();
branches_ensure_schema($pdo);

$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$statusFilter = trim((string) ($_POST['status'] ?? $_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'open', 'answered', 'closed'], true)) {
    $statusFilter = 'all';
}
$nameQuery = trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? 'reply'));
    try {
        $stmt = $pdo->prepare('SELECT * FROM branch_tickets WHERE id = ? LIMIT 1');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            throw new RuntimeException('تیکت یافت نشد');
        }

        if ($action === 'close') {
            $pdo->prepare(
                "UPDATE branch_tickets SET status = 'closed', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            )->execute([$ticketId]);
            cms_flash('تیکت بسته شد');
        } elseif ($action === 'reopen') {
            $pdo->prepare(
                "UPDATE branch_tickets SET status = 'open', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            )->execute([$ticketId]);
            cms_flash('تیکت دوباره باز شد');
        } else {
            $body = trim((string) ($_POST['body'] ?? ''));
            $image = branch_tickets_handle_image_upload('image');
            if ($body === '' && $image === null) {
                throw new RuntimeException('متن یا تصویر پاسخ الزامی است');
            }
            $pdo->prepare(
                'INSERT INTO branch_ticket_messages
                 (ticket_id, actor, body, image, admin_read_at)
                 VALUES (?, \'admin\', ?, ?, CURRENT_TIMESTAMP)'
            )->execute([
                $ticketId,
                $body !== '' ? $body : null,
                $image,
            ]);
            $pdo->prepare(
                "UPDATE branch_tickets SET status = 'answered', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            )->execute([$ticketId]);
            $pdo->prepare(
                "UPDATE branch_ticket_messages
                 SET admin_read_at = CURRENT_TIMESTAMP
                 WHERE ticket_id = ? AND actor = 'branch' AND admin_read_at IS NULL"
            )->execute([$ticketId]);
            cms_flash('پاسخ ارسال شد');
        }
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    $back = ['id' => $ticketId];
    if ($statusFilter !== 'all') {
        $back['status'] = $statusFilter;
    }
    if ($nameQuery !== '') {
        $back['q'] = $nameQuery;
    }
    cms_redirect('branch-tickets.php?' . http_build_query($back));
}

$where = '1=1';
$params = [];
if ($statusFilter !== 'all') {
    $where .= ' AND t.status = ?';
    $params[] = $statusFilter;
}
if ($nameQuery !== '') {
    $where .= ' AND (b.name LIKE ? OR b.city LIKE ? OR b.province_name LIKE ? OR t.subject LIKE ?)';
    $like = '%' . $nameQuery . '%';
    array_push($params, $like, $like, $like, $like);
}

$sql = "SELECT t.*, b.name AS branch_name, b.city AS branch_city, b.province_name AS branch_province_name,
          u.phone AS user_phone,
          (SELECT COUNT(*) FROM branch_ticket_messages m
           WHERE m.ticket_id = t.id AND m.actor = 'branch' AND m.admin_read_at IS NULL
          ) AS unread
        FROM branch_tickets t
        INNER JOIN branches b ON b.id = t.branch_id
        INNER JOIN site_users u ON u.id = t.user_id
        WHERE {$where}
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 150";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$conversations = $stmt->fetchAll() ?: [];

$viewTicket = null;
$viewMessages = [];
if ($viewId > 0) {
    $stmt = $pdo->prepare(
        "SELECT t.*, b.name AS branch_name, b.city AS branch_city, b.province_name AS branch_province_name,
                b.phone AS branch_phone, u.phone AS user_phone
         FROM branch_tickets t
         INNER JOIN branches b ON b.id = t.branch_id
         INNER JOIN site_users u ON u.id = t.user_id
         WHERE t.id = ?
         LIMIT 1"
    );
    $stmt->execute([$viewId]);
    $viewTicket = $stmt->fetch() ?: null;
    if ($viewTicket) {
        $pdo->prepare(
            "UPDATE branch_ticket_messages
             SET admin_read_at = CURRENT_TIMESTAMP
             WHERE ticket_id = ? AND actor = 'branch' AND admin_read_at IS NULL"
        )->execute([$viewId]);
        $msgs = $pdo->prepare(
            'SELECT * FROM branch_ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC'
        );
        $msgs->execute([$viewId]);
        $viewMessages = $msgs->fetchAll() ?: [];
    }
}

$statusLabels = [
    'open' => 'باز',
    'answered' => 'پاسخ‌داده‌شده',
    'closed' => 'بسته',
];

$listQs = [];
if ($statusFilter !== 'all') {
    $listQs['status'] = $statusFilter;
}
if ($nameQuery !== '') {
    $listQs['q'] = $nameQuery;
}
$listHref = $listQs === [] ? 'branch-tickets.php' : 'branch-tickets.php?' . http_build_query($listQs);

cms_layout_start('تیکت نمایندگان', cms_current_username(), 'communication');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">تیکت‌های نمایندگان</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">گفتگو با شعب — جستجو با نام شعبه</p>
  </div>
</div>

<form class="cms-search" method="get" action="branch-tickets.php">
  <input class="cms-input" type="search" name="q" value="<?= cms_h($nameQuery) ?>" placeholder="جستجو با نام شعبه یا موضوع…">
  <?php if ($statusFilter !== 'all'): ?>
    <input type="hidden" name="status" value="<?= cms_h($statusFilter) ?>">
  <?php endif; ?>
  <?php if ($viewId > 0): ?>
    <input type="hidden" name="id" value="<?= $viewId ?>">
  <?php endif; ?>
  <button class="cms-btn" type="submit">جستجو</button>
  <?php if ($nameQuery !== ''): ?>
    <a class="cms-btn cms-btn--secondary" href="branch-tickets.php<?= $statusFilter !== 'all' ? '?status=' . cms_h($statusFilter) : '' ?>">پاک کردن</a>
  <?php endif; ?>
</form>

<div class="cms-btn-row" style="margin-bottom:1rem">
  <?php foreach (['all' => 'همه', 'open' => 'باز', 'answered' => 'پاسخ‌داده‌شده', 'closed' => 'بسته'] as $k => $label): ?>
    <?php
      $statusQs = [];
      if ($k !== 'all') {
          $statusQs['status'] = $k;
      }
      if ($nameQuery !== '') {
          $statusQs['q'] = $nameQuery;
      }
      $statusHref = $statusQs === [] ? 'branch-tickets.php' : 'branch-tickets.php?' . http_build_query($statusQs);
    ?>
    <a class="cms-btn <?= $statusFilter === $k ? '' : 'cms-btn--secondary' ?>" href="<?= cms_h($statusHref) ?>">
      <?= cms_h($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="<?= $viewTicket ? 'cms-comm-split' : '' ?>">
  <div class="cms-panel">
    <h2 style="margin:0 0 .75rem;font-size:1rem">تیکت‌ها</h2>
    <?php if ($conversations === []): ?>
      <p class="cms-empty"><?= $nameQuery !== '' ? 'تیکتی با این نام پیدا نشد.' : 'تیکتی نیست.' ?></p>
    <?php else: ?>
      <div class="cms-inbox-wrap">
        <table class="cms-table cms-table--inbox">
          <thead>
            <tr>
              <th class="cms-inbox-preview">موضوع</th>
              <th class="cms-inbox-meta">نماینده</th>
              <th class="cms-inbox-unread">وضعیت</th>
              <th class="cms-inbox-unread">خوانده‌نشده</th>
              <th class="cms-inbox-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($conversations as $c): ?>
            <?php
              $tid = (int) $c['id'];
              $openQs = ['id' => $tid];
              if ($statusFilter !== 'all') {
                  $openQs['status'] = $statusFilter;
              }
              if ($nameQuery !== '') {
                  $openQs['q'] = $nameQuery;
              }
              $openHref = 'branch-tickets.php?' . http_build_query($openQs);
            ?>
            <tr class="cms-inbox-row<?= $tid === $viewId ? ' is-active' : '' ?><?= (int) ($c['unread'] ?? 0) > 0 ? ' cms-row--client' : '' ?>">
              <td class="cms-inbox-preview"><a href="<?= cms_h($openHref) ?>"><?= cms_h((string) $c['subject']) ?></a></td>
              <td class="cms-inbox-meta">
                <?= cms_h((string) $c['branch_name']) ?>
                <br><span class="cms-muted" style="font-size:.8rem">
                  <?= cms_h((string) $c['branch_province_name']) ?> / <?= cms_h((string) $c['branch_city']) ?>
                </span>
              </td>
              <td class="cms-inbox-unread"><?= cms_h($statusLabels[(string) $c['status']] ?? (string) $c['status']) ?></td>
              <td class="cms-inbox-unread">
                <?php if ((int) ($c['unread'] ?? 0) > 0): ?>
                  <span class="cms-client-msg__badge"><?= (int) $c['unread'] ?></span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="cms-inbox-actions">
                <a class="cms-inbox-open" href="<?= cms_h($openHref) ?>">باز کردن</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($viewTicket): ?>
  <div class="cms-panel">
    <div class="cms-page-head" style="margin-bottom:.75rem">
      <div>
        <h2 style="margin:0;font-size:1rem"><?= cms_h((string) $viewTicket['subject']) ?></h2>
        <p class="cms-muted" style="margin:.35rem 0 0;font-size:.85rem">
          <?= cms_h((string) $viewTicket['branch_name']) ?> —
          <?= cms_h((string) $viewTicket['branch_province_name']) ?> /
          <?= cms_h((string) $viewTicket['branch_city']) ?>
          — <span dir="ltr"><?= cms_h((string) $viewTicket['branch_phone']) ?></span>
        </p>
      </div>
      <a class="cms-btn cms-btn--secondary" href="<?= cms_h($listHref) ?>">بستن</a>
    </div>

    <div class="cms-msg-thread">
      <?php foreach ($viewMessages as $m): ?>
        <?php $fromBranch = (string) $m['actor'] === 'branch'; ?>
        <div class="<?= $fromBranch ? 'cms-client-msg' : 'cms-payment-answered' ?>" style="margin:0">
          <strong class="<?= $fromBranch ? 'cms-client-msg__badge' : 'cms-payment-answered__badge' ?>">
            <?= $fromBranch ? 'نماینده' : 'ادمین' ?>
          </strong>
          <?php if (!empty($m['body'])): ?>
            <p class="<?= $fromBranch ? 'cms-client-msg__text' : 'cms-payment-answered__text' ?>"><?= cms_h((string) $m['body']) ?></p>
          <?php endif; ?>
          <?php if (!empty($m['image'])): ?>
            <p style="margin:.5rem 0 0">
              <a href="<?= cms_h(cms_asset_url((string) $m['image'])) ?>" target="_blank" rel="noopener">
                <img src="<?= cms_h(cms_asset_url((string) $m['image'])) ?>" alt="" style="max-width:220px;border-radius:6px">
              </a>
            </p>
          <?php endif; ?>
          <p class="cms-muted" style="margin:.35rem 0 0;font-size:.75rem" dir="ltr"><?= cms_h((string) $m['created_at']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ((string) $viewTicket['status'] !== 'closed'): ?>
      <form method="post" enctype="multipart/form-data" class="cms-form">
        <input type="hidden" name="ticket_id" value="<?= (int) $viewTicket['id'] ?>">
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="status" value="<?= cms_h($statusFilter) ?>">
        <input type="hidden" name="q" value="<?= cms_h($nameQuery) ?>">
        <label class="cms-field">
          <span>پاسخ ادمین</span>
          <textarea class="cms-input" name="body" rows="3" placeholder="پاسخ…"></textarea>
        </label>
        <label class="cms-field">
          <span>تصویر (اختیاری)</span>
          <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
        </label>
        <div class="cms-btn-row" style="margin-top:.75rem">
          <button class="cms-btn" type="submit">ارسال پاسخ</button>
          <button class="cms-btn cms-btn--ghost" type="submit" name="action" value="close" onclick="this.form.action.value='close'">بستن تیکت</button>
        </div>
      </form>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="ticket_id" value="<?= (int) $viewTicket['id'] ?>">
        <input type="hidden" name="action" value="reopen">
        <input type="hidden" name="status" value="<?= cms_h($statusFilter) ?>">
        <input type="hidden" name="q" value="<?= cms_h($nameQuery) ?>">
        <button class="cms-btn cms-btn--secondary" type="submit">بازگشایی تیکت</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php
cms_layout_end();
