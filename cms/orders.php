<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/orders.php';
require_once __DIR__ . '/lib/invoices.php';
require_once __DIR__ . '/lib/jalali.php';

cms_require_login();
$pdo = cms_pdo();
orders_ensure_schema($pdo);

$statusLabels = orders_status_labels();
$allowed = orders_allowed_transitions();
$allStatuses = orders_all_statuses();

$pageSize = 20;

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
if ($statusFilter !== 'all' && !in_array($statusFilter, $allStatuses, true)) {
    $statusFilter = 'all';
}

$scope = isset($_GET['scope']) ? trim((string) $_GET['scope']) : 'customers';
if ($scope !== 'branches' && $scope !== 'customers') {
    $scope = 'customers';
}
$isBranchScope = $scope === 'branches';
$scopeSql = $isBranchScope ? 'branch_id IS NOT NULL' : 'branch_id IS NULL';

$searchQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$ordersListQs = static function (
    string $scope,
    string $status = 'all',
    string $q = '',
    int $page = 1,
    ?int $id = null
): string {
    $params = [
        'scope' => $scope,
        'status' => $status,
    ];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($page > 1) {
        $params['page'] = (string) $page;
    }
    if ($id !== null && $id > 0) {
        $params['id'] = (string) $id;
    }
    return 'orders.php?' . http_build_query($params);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int) ($_POST['id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $returnStatus = trim((string) ($_POST['return_status'] ?? $statusFilter));
    if ($returnStatus !== 'all' && !in_array($returnStatus, $allStatuses, true)) {
        $returnStatus = 'all';
    }
    $returnQ = trim((string) ($_POST['return_q'] ?? $searchQ));
    $returnPage = max(1, (int) ($_POST['return_page'] ?? $page));

    try {
        if ($orderId <= 0) {
            throw new RuntimeException('سفارش نامعتبر');
        }
        $order = orders_get_by_id($pdo, $orderId);
        if ($order === null) {
            throw new RuntimeException('سفارش یافت نشد');
        }

        $current = (string) $order['status'];

        // Warning about incomplete payment docs — stay on payment_proof_sent.
        if ($action === 'warn_payment') {
            if ($current !== 'payment_proof_sent') {
                throw new RuntimeException('هشدار فقط برای سفارش‌های دارای مدارک پرداخت مجاز است');
            }
            if ($message === '') {
                throw new RuntimeException('متن هشدار درباره نقص مدارک الزامی است');
            }
            $pdo->beginTransaction();
            $upd = $pdo->prepare(
                "UPDATE orders SET payment_warning = ?, payment_warning_state = 'open' WHERE id = ?"
            );
            $upd->execute([$message, $orderId]);
            orders_add_event(
                $pdo,
                $orderId,
                'payment_proof_sent',
                'payment_proof_sent',
                'admin',
                'هشدار نقص مدارک: ' . $message
            );
            $pdo->commit();
            cms_flash('هشدار نقص مدارک برای مشتری ارسال شد');
        } elseif ($action === 'save_prices') {
            if (!in_array($current, ['submitted', 'accepted', 'payment_proof_sent'], true)) {
                throw new RuntimeException('در این وضعیت امکان ویرایش قیمت نیست');
            }
            $prices = $_POST['price'] ?? [];
            if (!is_array($prices)) {
                throw new RuntimeException('قیمت‌ها نامعتبر است');
            }
            $upd = $pdo->prepare('UPDATE order_items SET price_text = ? WHERE id = ? AND order_id = ?');
            $changed = 0;
            foreach ($prices as $itemId => $priceRaw) {
                $itemId = (int) $itemId;
                if ($itemId <= 0) {
                    continue;
                }
                try {
                    $normalized = invoices_normalize_price_text((string) $priceRaw);
                } catch (InvalidArgumentException $e) {
                    throw new RuntimeException('قلم #' . $itemId . ': ' . $e->getMessage());
                }
                $upd->execute([$normalized, $itemId, $orderId]);
                $changed += $upd->rowCount() > 0 ? 1 : 0;
            }
            cms_flash($changed > 0 ? 'قیمت‌های تومان ذخیره شد' : 'تغییری در قیمت‌ها ثبت نشد');
        } elseif ($action === 'accept') {
            // Warehouse accept always requires prices + auto-issues / sends pre-invoice.
            if ($current !== 'submitted') {
                throw new RuntimeException('تأیید انبار فقط برای سفارش تازه ثبت‌شده مجاز است');
            }
            $prices = $_POST['price'] ?? [];
            if (!is_array($prices) || $prices === []) {
                throw new RuntimeException('قبل از تأیید انبار، قیمت تومان همه اقلام را وارد کنید');
            }
            $pdo->beginTransaction();
            $updPrice = $pdo->prepare('UPDATE order_items SET price_text = ? WHERE id = ? AND order_id = ?');
            foreach ($prices as $itemId => $priceRaw) {
                $itemId = (int) $itemId;
                if ($itemId <= 0) {
                    continue;
                }
                try {
                    $normalized = invoices_normalize_price_text((string) $priceRaw);
                } catch (InvalidArgumentException $e) {
                    throw new RuntimeException('قلم #' . $itemId . ': ' . $e->getMessage());
                }
                if ($normalized === null || $normalized === '') {
                    throw new RuntimeException('قیمت تومان همه اقلام برای تأیید انبار الزامی است');
                }
                $updPrice->execute([$normalized, $itemId, $orderId]);
            }
            $pricedItems = orders_fetch_items($pdo, $orderId);
            if ($pricedItems === []) {
                throw new RuntimeException('سفارش بدون قلم است');
            }
            foreach ($pricedItems as $item) {
                $parsed = invoices_parse_toman_amount(
                    isset($item['price_text']) ? (string) $item['price_text'] : null
                );
                if ($parsed === null) {
                    throw new RuntimeException('قبل از تأیید انبار، قیمت تومان همه اقلام را وارد کنید');
                }
            }
            $dueAt = trim((string) ($_POST['pre_invoice_due_at'] ?? ''));
            if ($dueAt === '') {
                $dueAt = date('Y-m-d', strtotime('+7 days'));
            }
            invoices_issue_pre($pdo, $orderId, $dueAt);
            $upd = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
            $upd->execute(['accepted', $orderId]);
            $acceptNote = trim((string) ($_POST['message'] ?? ''));
            orders_add_event(
                $pdo,
                $orderId,
                $current,
                'accepted',
                'admin',
                $acceptNote !== '' ? $acceptNote : 'تأیید انبار و ارسال خودکار پیش‌فاکتور'
            );
            $pdo->commit();
            cms_flash('انبار تأیید شد و پیش‌فاکتور برای مشتری ارسال شد');
        } elseif ($action === 'issue_pre_invoice') {
            if (!in_array($current, ['accepted', 'payment_proof_sent'], true)) {
                throw new RuntimeException('ابتدا انبار را با قیمت‌گذاری تأیید کنید؛ پیش‌فاکتور هنگام تأیید انبار ارسال می‌شود');
            }
            // Save any posted prices first so PDF matches the form.
            $prices = $_POST['price'] ?? [];
            if (is_array($prices)) {
                $upd = $pdo->prepare('UPDATE order_items SET price_text = ? WHERE id = ? AND order_id = ?');
                foreach ($prices as $itemId => $priceRaw) {
                    $itemId = (int) $itemId;
                    if ($itemId <= 0) {
                        continue;
                    }
                    try {
                        $normalized = invoices_normalize_price_text((string) $priceRaw);
                    } catch (InvalidArgumentException $e) {
                        throw new RuntimeException('قلم #' . $itemId . ': ' . $e->getMessage());
                    }
                    $upd->execute([$normalized, $itemId, $orderId]);
                }
            }
            $dueAt = trim((string) ($_POST['pre_invoice_due_at'] ?? ''));
            invoices_issue_pre($pdo, $orderId, $dueAt);
            cms_flash('پیش‌فاکتور صادر شد و در پیگیری سفارش مشتری قابل دریافت است');
        } else {
            $nextMap = [
                'reject' => 'rejected',
                'mark_paid' => 'paid',
                'mark_shipped' => 'shipped',
                'mark_not_received' => 'not_received',
                'mark_returned' => 'returned_to_origin',
                'mark_lost' => 'lost',
                'mark_received' => 'received',
            ];
            if (!isset($nextMap[$action])) {
                throw new RuntimeException('عملیات نامعتبر');
            }
            $next = $nextMap[$action];
            $allowedNext = $allowed[$current] ?? [];
            if (!in_array($next, $allowedNext, true)) {
                throw new RuntimeException('این تغییر وضعیت مجاز نیست');
            }
            if ($action === 'reject' && $message === '') {
                throw new RuntimeException('علت رد انبار (مثلاً موجود نبودن کالا) الزامی است');
            }

            $pdo->beginTransaction();
            if ($next === 'paid') {
                $upd = $pdo->prepare(
                    'UPDATE orders SET status = ?, payment_warning = NULL, payment_warning_state = NULL WHERE id = ?'
                );
            } else {
                $upd = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
            }
            $upd->execute([$next, $orderId]);
            orders_add_event($pdo, $orderId, $current, $next, 'admin', $message !== '' ? $message : null);
            $pdo->commit();

            if ($next === 'paid') {
                try {
                    invoices_issue_final($pdo, $orderId);
                    cms_flash('پرداخت تأیید و فاکتور نهایی صادر شد');
                } catch (Throwable $invErr) {
                    cms_flash('پرداخت تأیید شد اما صدور فاکتور نهایی ناموفق بود: ' . $invErr->getMessage(), 'error');
                }
            } elseif ($next === 'rejected') {
                cms_flash('سفارش رد و بایگانی شد — برای مشتری بسته شده است');
            } elseif ($next === 'received') {
                cms_flash('تحویل تأیید شد — سفارش تمام شد');
            } else {
                cms_flash('وضعیت سفارش به‌روز شد');
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cms_flash($e->getMessage(), 'error');
    }

    $returnScope = trim((string) ($_POST['return_scope'] ?? $scope));
    if ($returnScope !== 'branches' && $returnScope !== 'customers') {
        $returnScope = 'customers';
    }

    cms_redirect($ordersListQs($returnScope, $returnStatus, $returnQ, $returnPage, $orderId));
}

$viewOrder = null;
$viewItems = [];
$viewEvents = [];
if ($viewId > 0) {
    $viewOrder = orders_get_by_id($pdo, $viewId);
    if ($viewOrder) {
        $orderIsBranch = !empty($viewOrder['branch_id']);
        $scope = $orderIsBranch ? 'branches' : 'customers';
        $isBranchScope = $orderIsBranch;
        $scopeSql = $isBranchScope ? 'branch_id IS NOT NULL' : 'branch_id IS NULL';
        $viewItems = orders_fetch_items($pdo, $viewId);
        $viewEvents = orders_fetch_events($pdo, $viewId);
    }
}

$countCustomers = (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE branch_id IS NULL')->fetchColumn();
$countBranches = (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE branch_id IS NOT NULL')->fetchColumn();

$where = [$scopeSql];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'o.status = ?';
    $params[] = $statusFilter;
}
if ($searchQ !== '') {
    $like = '%' . $searchQ . '%';
    $searchParts = [
        'o.phone LIKE ?',
        'o.public_code LIKE ?',
        'COALESCE(o.branch_phone, \'\') LIKE ?',
    ];
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    if (ctype_digit($searchQ)) {
        $searchParts[] = 'o.id = ?';
        $params[] = (int) $searchQ;
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $pageSize;

$sql = "SELECT o.*,
        (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
        FROM orders o
        WHERE {$whereSql}
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT " . (int) $pageSize . ' OFFSET ' . (int) $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll() ?: [];

$listCloseHref = $ordersListQs($scope, $statusFilter, $searchQ, $page, null);

cms_layout_start('سفارش‌ها', cms_current_username(), 'shop');

$defaultDetailTab = 'actions';
if ($viewOrder) {
    $st0 = (string) $viewOrder['status'];
    if (in_array($st0, ['submitted'], true)) {
        $defaultDetailTab = 'items';
    } elseif (in_array($st0, ['accepted', 'payment_proof_sent'], true)) {
        $defaultDetailTab = 'payment';
    } elseif ($st0 === 'paid') {
        $defaultDetailTab = 'invoices';
    }
}
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">سفارش‌ها</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">انبار + قیمت/پیش‌فاکتور → پرداخت → ارسال → تأیید دریافت</p>
  </div>
</div>

<div class="cms-orders-tabs" role="tablist" aria-label="نوع سفارش">
  <a class="cms-orders-tab<?= !$isBranchScope ? ' is-active' : '' ?>"
     href="<?= cms_h($ordersListQs('customers', $statusFilter, $searchQ, 1, null)) ?>"
     role="tab"
     aria-selected="<?= !$isBranchScope ? 'true' : 'false' ?>">
    مشتری آزاد
    <span class="cms-orders-tab__count"><?= (int) $countCustomers ?></span>
  </a>
  <a class="cms-orders-tab<?= $isBranchScope ? ' is-active' : '' ?>"
     href="<?= cms_h($ordersListQs('branches', $statusFilter, $searchQ, 1, null)) ?>"
     role="tab"
     aria-selected="<?= $isBranchScope ? 'true' : 'false' ?>">
    نمایندگان
    <span class="cms-orders-tab__count"><?= (int) $countBranches ?></span>
  </a>
</div>

<div class="cms-panel cms-orders-frame">
  <form class="cms-orders-toolbar" method="get" action="orders.php">
    <input type="hidden" name="scope" value="<?= cms_h($scope) ?>">
    <label class="cms-orders-toolbar__search">
      <span class="cms-label">جستجو</span>
      <input class="cms-input" type="search" name="q" value="<?= cms_h($searchQ) ?>"
        placeholder="موبایل یا کد سفارش" autocomplete="off">
    </label>
    <label class="cms-orders-toolbar__status">
      <span class="cms-label">وضعیت</span>
      <select class="cms-select" name="status">
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>همه</option>
        <?php foreach ($allStatuses as $st): ?>
          <option value="<?= cms_h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>>
            <?= cms_h($statusLabels[$st] ?? $st) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="cms-orders-toolbar__actions">
      <button class="cms-btn" type="submit">اعمال</button>
      <?php if ($searchQ !== '' || $statusFilter !== 'all'): ?>
        <a class="cms-btn cms-btn--secondary" href="<?= cms_h($ordersListQs($scope, 'all', '', 1, null)) ?>">پاک کردن</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="cms-orders-list-head">
    <h2 style="margin:0;font-size:1.05rem">
      <?= $isBranchScope ? 'سفارش نمایندگان' : 'سفارش مشتری آزاد' ?>
    </h2>
    <span class="cms-muted"><?= (int) $totalRows ?> مورد · صفحه <?= (int) $page ?> از <?= (int) $totalPages ?></span>
  </div>

  <?php if ($items === []): ?>
    <p class="cms-empty">سفارشی یافت نشد.</p>
  <?php else: ?>
    <div class="cms-orders-table-wrap">
      <table class="cms-table cms-orders-table">
        <thead>
          <tr>
            <th>کد سفارش</th>
            <?php if ($isBranchScope): ?>
              <th>استان</th>
              <th>شهر</th>
              <th>نماینده</th>
              <th>موبایل</th>
            <?php else: ?>
              <th>موبایل</th>
            <?php endif; ?>
            <th>وضعیت</th>
            <th>اقلام</th>
            <th>تاریخ</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
          <?php
            $rowArchived = orders_is_archived((string) $item['status']);
            $rowHref = $ordersListQs($scope, $statusFilter, $searchQ, $page, (int) $item['id']);
          ?>
          <tr class="cms-orders-row<?= $rowArchived ? ' cms-row--archived' : '' ?><?= $viewId === (int) $item['id'] ? ' is-open' : '' ?>"
              data-href="<?= cms_h($rowHref) ?>"
              tabindex="0"
              role="link">
            <td dir="ltr"><?= cms_h((string) $item['public_code']) ?></td>
            <?php if ($isBranchScope): ?>
              <td><?= cms_h((string) ($item['branch_province_name'] ?? '')) ?></td>
              <td><?= cms_h((string) ($item['branch_city'] ?? '')) ?></td>
              <td><?= cms_h((string) ($item['branch_name'] ?? '')) ?></td>
              <td dir="ltr"><?= cms_h((string) ($item['branch_phone'] ?: $item['phone'])) ?></td>
            <?php else: ?>
              <td dir="ltr"><?= cms_h((string) $item['phone']) ?></td>
            <?php endif; ?>
            <td>
              <?= cms_h($statusLabels[(string) $item['status']] ?? (string) $item['status']) ?>
              <?php if ($rowArchived): ?>
                <span class="cms-client-msg__badge" style="margin-right:6px;background:rgba(154,144,132,.85)">بسته</span>
              <?php endif; ?>
            </td>
            <td><?= (int) $item['item_count'] ?></td>
            <td dir="ltr"><?= cms_h((string) $item['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="cms-orders-pager" aria-label="صفحه‌بندی">
        <?php if ($page > 1): ?>
          <a class="cms-btn cms-btn--secondary" href="<?= cms_h($ordersListQs($scope, $statusFilter, $searchQ, $page - 1, null)) ?>">قبلی</a>
        <?php endif; ?>
        <span class="cms-muted"><?= (int) $page ?> / <?= (int) $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="cms-btn cms-btn--secondary" href="<?= cms_h($ordersListQs($scope, $statusFilter, $searchQ, $page + 1, null)) ?>">بعدی</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function () {
  document.querySelectorAll('.cms-orders-row[data-href]').forEach(function (row) {
    function go() {
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

<?php if ($viewOrder): ?>
  <?php
    $cur = (string) $viewOrder['status'];
    $nextActions = [];
    foreach ($allowed[$cur] ?? [] as $nextStatus) {
        if ($nextStatus === 'accepted') {
            // Accept is forced through the prices tab (prices + auto pre-invoice).
            continue;
        } elseif ($nextStatus === 'rejected') {
            $nextActions[] = ['action' => 'reject', 'label' => 'رد انبار و بایگانی', 'class' => 'cms-btn--ghost'];
        } elseif ($nextStatus === 'paid') {
            $nextActions[] = ['action' => 'mark_paid', 'label' => 'تأیید پرداخت و مرحله بعد', 'class' => ''];
        } elseif ($nextStatus === 'shipped') {
            $label = orders_is_parcel_open($cur) && $cur !== 'shipped'
                ? 'ارسال مجدد مرسوله'
                : 'ارسال مرسوله';
            $nextActions[] = ['action' => 'mark_shipped', 'label' => $label, 'class' => ''];
        } elseif ($nextStatus === 'not_received') {
            $nextActions[] = ['action' => 'mark_not_received', 'label' => 'هنوز دریافت نشده', 'class' => 'cms-btn--ghost'];
        } elseif ($nextStatus === 'returned_to_origin') {
            $nextActions[] = ['action' => 'mark_returned', 'label' => 'برگشت به مبدأ', 'class' => 'cms-btn--ghost'];
        } elseif ($nextStatus === 'lost') {
            $nextActions[] = ['action' => 'mark_lost', 'label' => 'مفقود', 'class' => 'cms-btn--ghost'];
        } elseif ($nextStatus === 'received') {
            $nextActions[] = ['action' => 'mark_received', 'label' => 'تأیید دریافت — تمام', 'class' => ''];
        }
    }
    if ($cur === 'payment_proof_sent') {
        $nextActions[] = [
            'action' => 'warn_payment',
            'label' => 'هشدار نقص مدارک',
            'class' => 'cms-btn--ghost',
        ];
    }

    $canEditPrices = in_array($cur, ['submitted', 'accepted', 'payment_proof_sent'], true);
    $preFile = isset($viewOrder['pre_invoice_file']) ? trim((string) $viewOrder['pre_invoice_file']) : '';
    $preDue = isset($viewOrder['pre_invoice_due_at']) ? (string) $viewOrder['pre_invoice_due_at'] : '';
    $preCreated = isset($viewOrder['pre_invoice_created_at']) ? (string) $viewOrder['pre_invoice_created_at'] : '';
    $finalFile = isset($viewOrder['final_invoice_file']) ? trim((string) $viewOrder['final_invoice_file']) : '';
    $finalCreated = isset($viewOrder['final_invoice_created_at']) ? (string) $viewOrder['final_invoice_created_at'] : '';
    $dueDefault = $preDue !== '' ? substr($preDue, 0, 10) : date('Y-m-d', strtotime('+7 days'));
    $payNote = isset($viewOrder['payment_note']) ? trim((string) $viewOrder['payment_note']) : '';
    $payFiles = orders_payment_files_list($viewOrder);
    $payAt = isset($viewOrder['payment_submitted_at']) ? (string) $viewOrder['payment_submitted_at'] : '';
    $payWarn = isset($viewOrder['payment_warning']) ? trim((string) $viewOrder['payment_warning']) : '';
    $payWarnState = isset($viewOrder['payment_warning_state'])
        ? trim((string) $viewOrder['payment_warning_state'])
        : '';
    if ($payWarnState === '' && $payWarn !== '') {
        $payWarnState = 'open';
    }
    $clientMessage = '';
    if ($payNote !== '') {
        $clientMessage = $payNote;
    } else {
        for ($ei = count($viewEvents) - 1; $ei >= 0; $ei--) {
            $ev = $viewEvents[$ei];
            if ((string) ($ev['actor'] ?? '') !== 'client') {
                continue;
            }
            $evMsg = isset($ev['message']) ? trim((string) $ev['message']) : '';
            if ($evMsg !== '') {
                $clientMessage = $evMsg;
                break;
            }
        }
    }
    $orderCode = (string) $viewOrder['public_code'];
    $draftMessage = $cur === 'payment_proof_sent' && $payWarn !== '' ? $payWarn : '';
    $statusTone = orders_is_archived($cur) ? 'danger' : (orders_is_finished($cur) ? 'ok' : (orders_is_parcel_open($cur) ? 'warn' : 'info'));
    $returnHiddens = static function () use ($statusFilter, $scope, $searchQ, $page): void {
        echo '<input type="hidden" name="return_status" value="' . cms_h($statusFilter) . '">';
        echo '<input type="hidden" name="return_scope" value="' . cms_h($scope) . '">';
        echo '<input type="hidden" name="return_q" value="' . cms_h($searchQ) . '">';
        echo '<input type="hidden" name="return_page" value="' . (int) $page . '">';
    };
  ?>

  <div id="cms-order-modal" class="cms-order-modal" role="dialog" aria-modal="true" aria-labelledby="cms-order-modal-title">
    <div class="cms-order-modal__panel">
      <header class="cms-order-modal__head">
        <div>
          <div class="cms-order-detail__code-row">
            <h2 id="cms-order-modal-title" class="cms-order-detail__code"><?= cms_h($orderCode) ?></h2>
            <span class="cms-order-badge cms-order-badge--<?= cms_h($statusTone) ?>">
              <?= cms_h($statusLabels[$cur] ?? $cur) ?>
            </span>
          </div>
          <dl class="cms-order-meta">
            <div><dt>تاریخ</dt><dd dir="ltr"><?= cms_h((string) $viewOrder['created_at']) ?></dd></div>
            <div><dt>موبایل</dt><dd dir="ltr"><?= cms_h((string) ($viewOrder['branch_phone'] ?: $viewOrder['phone'])) ?></dd></div>
            <?php if (!empty($viewOrder['branch_id'])): ?>
              <div><dt>نماینده</dt><dd><?= cms_h((string) ($viewOrder['branch_name'] ?? '')) ?></dd></div>
              <div><dt>استان / شهر</dt><dd><?= cms_h(trim((string) ($viewOrder['branch_province_name'] ?? '') . ' / ' . (string) ($viewOrder['branch_city'] ?? ''), ' /')) ?></dd></div>
            <?php endif; ?>
            <div><dt>اقلام</dt><dd><?= count($viewItems) ?> خط</dd></div>
          </dl>
        </div>
        <a class="cms-btn cms-btn--secondary" id="cms-order-modal-close" href="<?= cms_h($listCloseHref) ?>">بستن</a>
      </header>

      <div class="cms-order-modal__body">
        <?php if (orders_is_archived($cur)): ?>
          <div class="cms-payment-warn" style="margin:0 0 1rem">
            <strong class="cms-payment-warn__badge">بسته و بایگانی‌شده</strong>
            <p class="cms-payment-warn__text">این سفارش به‌خاطر رد انبار بسته شده است. تغییر وضعیت ممکن نیست.</p>
          </div>
        <?php endif; ?>

        <div class="cms-vtabs" data-default-tab="<?= cms_h($defaultDetailTab) ?>">
          <nav class="cms-vtabs__nav" role="tablist" aria-orientation="vertical">
            <button type="button" class="cms-vtabs__tab" role="tab" data-tab="actions" aria-selected="false">اقدام وضعیت</button>
            <button type="button" class="cms-vtabs__tab" role="tab" data-tab="items" aria-selected="false">اقلام و قیمت</button>
            <button type="button" class="cms-vtabs__tab" role="tab" data-tab="invoices" aria-selected="false">فاکتورها</button>
            <button type="button" class="cms-vtabs__tab" role="tab" data-tab="payment" aria-selected="false">مدارک پرداخت</button>
            <button type="button" class="cms-vtabs__tab" role="tab" data-tab="history" aria-selected="false">تاریخچه</button>
          </nav>

          <div class="cms-vtabs__panels">
            <section class="cms-vtabs__panel" data-panel="actions" role="tabpanel" hidden>
              <h3 class="cms-vtabs__heading">تغییر وضعیت</h3>
              <?php if ($nextActions !== []): ?>
                <form method="post" class="cms-form" id="order-status-form">
                  <input type="hidden" name="id" value="<?= (int) $viewOrder['id'] ?>">
                  <?php $returnHiddens(); ?>
                  <input type="hidden" name="action" id="order-action" value="">
                  <label class="cms-field">
                    <span><?php
                      if ($cur === 'submitted') {
                          echo 'پیش‌نویس پیام برای مشتری (تأیید: راهنمای پرداخت · رد: علت الزامی)';
                      } elseif ($cur === 'payment_proof_sent') {
                          echo 'پیش‌نویس پیام برای مشتری («هشدار نقص مدارک» الزامی)';
                      } elseif (orders_is_parcel_open($cur)) {
                          echo 'پیش‌نویس یادداشت پیگیری';
                      } else {
                          echo 'پیش‌نویس پیام برای مشتری';
                      }
                    ?></span>
                    <textarea
                      class="cms-input"
                      name="message"
                      id="order-message-draft"
                      rows="4"
                      placeholder="<?= $cur === 'submitted'
                        ? 'تأیید: شماره کارت/شبا…  |  رد: کالا موجود نیست / …'
                        : ($cur === 'payment_proof_sent'
                          ? 'مثلاً تصویر رسید خوانا نیست…'
                          : (orders_is_parcel_open($cur)
                            ? 'مثلاً کد رهگیری پست…'
                            : 'توضیح این مرحله…')) ?>"
                    ><?= cms_h($draftMessage) ?></textarea>
                  </label>
                  <?php if ($cur === 'submitted'): ?>
                    <p class="cms-muted cms-vtabs__hint">برای تأیید انبار به تب «اقلام و قیمت» بروید — قیمت همه اقلام الزامی است و پیش‌فاکتور خودکار برای مشتری ارسال می‌شود. رد انبار سفارش را می‌بندد و بایگانی می‌کند.</p>
                  <?php elseif ($cur === 'payment_proof_sent'): ?>
                    <p class="cms-muted cms-vtabs__hint">هشدار نقص مدارک وضعیت را عوض نمی‌کند؛ مشتری اصلاح می‌کند و دوباره می‌فرستد.</p>
                  <?php elseif (orders_is_parcel_open($cur)): ?>
                    <p class="cms-muted cms-vtabs__hint">پس از ارسال، سفارش تا «تأیید دریافت» تمام نیست.</p>
                  <?php endif; ?>
                  <div class="cms-btn-row" style="margin-top:.85rem">
                    <?php foreach ($nextActions as $btn): ?>
                      <button
                        class="cms-btn <?= cms_h($btn['class']) ?> order-stage-btn"
                        type="button"
                        data-action="<?= cms_h($btn['action']) ?>"
                        data-label="<?= cms_h($btn['label']) ?>"
                      ><?= cms_h($btn['label']) ?></button>
                    <?php endforeach; ?>
                  </div>
                </form>
              <?php else: ?>
                <?php if ($cur === 'accepted'): ?>
                  <p class="cms-muted">در انتظار ارسال مدارک پرداخت توسط مشتری. پیش‌فاکتور هنگام تأیید انبار ارسال شده؛ در صورت نیاز از تب «فاکتورها» دوباره بفرستید.</p>
                <?php elseif (orders_is_archived($cur)): ?>
                  <p class="cms-muted">این سفارش بسته و بایگانی شده است — اقدامی باقی نمانده.</p>
                <?php elseif (orders_is_finished($cur)): ?>
                  <p class="cms-muted">تحویل تأیید شده و سفارش تمام است.</p>
                <?php else: ?>
                  <p class="cms-muted">این سفارش در وضعیت پایانی است.</p>
                <?php endif; ?>
              <?php endif; ?>
            </section>

            <section class="cms-vtabs__panel" data-panel="items" role="tabpanel" hidden>
              <h3 class="cms-vtabs__heading">اقلام و قیمت</h3>
              <p class="cms-muted cms-vtabs__hint" style="margin-top:0">قیمت واحد را فقط به <strong>تومان</strong> وارد کنید (نه ریال). جمع هر ردیف = قیمت واحد × تعداد.</p>
              <?php if ($viewItems === []): ?>
                <p class="cms-empty">قلمی ثبت نشده.</p>
              <?php else: ?>
                <?php $itemTotals = invoices_totals_from_items($viewItems); ?>
                <form method="post" class="cms-invoice-form" id="order-prices-form">
                  <input type="hidden" name="id" value="<?= (int) $viewOrder['id'] ?>">
                  <?php $returnHiddens(); ?>
                  <?php if ($cur !== 'submitted'): ?>
                    <input type="hidden" name="pre_invoice_due_at" value="<?= cms_h($dueDefault) ?>">
                  <?php endif; ?>
                  <table class="cms-table">
                    <thead>
                      <tr>
                        <th>محصول</th>
                        <th>واحد / تعداد</th>
                        <th>قیمت واحد (تومان)</th>
                        <th>مبلغ ردیف</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($viewItems as $idx => $line): ?>
                      <?php
                        $lineMeta = $itemTotals['lines'][$idx] ?? null;
                        $unitVal = '';
                        try {
                            $parsedUnit = invoices_parse_toman_amount(
                                isset($line['price_text']) ? (string) $line['price_text'] : null
                            );
                            $unitVal = $parsedUnit !== null ? (string) $parsedUnit : '';
                        } catch (Throwable $e) {
                            $unitVal = trim((string) ($line['price_text'] ?? ''));
                        }
                      ?>
                      <tr>
                        <td>
                          <?= cms_h((string) $line['name']) ?>
                          <?php
                            $bits = array_filter([
                                $line['factory_name'] ?? null,
                                $line['model_name'] ?? null,
                                $line['category_name'] ?? null,
                            ]);
                            if ($bits !== []):
                          ?>
                            <div class="cms-muted" style="font-size:.8rem;margin-top:.2rem"><?= cms_h(implode(' · ', $bits)) ?></div>
                          <?php endif; ?>
                        </td>
                        <td><?= cms_h(invoices_unit_label($line)) ?></td>
                        <td>
                          <?php if ($canEditPrices): ?>
                            <input
                              class="cms-input cms-price-toman"
                              name="price[<?= (int) $line['id'] ?>]"
                              value="<?= cms_h($unitVal) ?>"
                              inputmode="numeric"
                              placeholder="مثلاً 125000"
                              data-qty="<?= (int) ($line['quantity'] ?? 1) ?>"
                              dir="ltr"
                              autocomplete="off"
                              <?= $cur === 'submitted' ? 'required' : '' ?>
                            >
                          <?php else: ?>
                            <?= cms_h((string) ($lineMeta['unit_label'] ?? ($line['price_text'] ?? '—'))) ?>
                          <?php endif; ?>
                        </td>
                        <td class="cms-line-total" dir="ltr">
                          <?= cms_h((string) ($lineMeta['line_label'] ?? '—')) ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                      <tr>
                        <th colspan="3" style="text-align:left">جمع کل (تومان)</th>
                        <th id="order-prices-grand" dir="ltr"><?= cms_h(invoices_format_toman((int) $itemTotals['total'])) ?></th>
                      </tr>
                    </tfoot>
                  </table>
                  <?php if ($canEditPrices): ?>
                    <?php if ($cur === 'submitted'): ?>
                      <label class="cms-field" style="max-width:16rem;margin-top:1rem">
                        <span class="cms-label">تاریخ سررسید پیش‌فاکتور</span>
                        <input class="cms-input" type="date" name="pre_invoice_due_at" dir="ltr" value="<?= cms_h($dueDefault) ?>" required>
                      </label>
                      <div class="cms-btn-row" style="margin-top:1rem">
                        <button class="cms-btn" type="submit" name="action" value="accept">
                          تأیید انبار و ارسال پیش‌فاکتور
                        </button>
                        <button class="cms-btn cms-btn--ghost" type="submit" name="action" value="save_prices">
                          فقط ذخیره قیمت‌ها
                        </button>
                      </div>
                      <p class="cms-muted cms-vtabs__hint">با تأیید انبار، قیمت‌ها ذخیره می‌شود، پیش‌فاکتور برای مشتری ارسال می‌شود و وضعیت به «تأیید انبار» می‌رود.</p>
                    <?php else: ?>
                      <div class="cms-btn-row" style="margin-top:1rem">
                        <button class="cms-btn" type="submit" name="action" value="save_prices">ذخیره قیمت‌ها</button>
                      </div>
                      <p class="cms-muted cms-vtabs__hint">پس از ذخیره، از تب «فاکتورها» می‌توانید پیش‌فاکتور را دوباره بفرستید.</p>
                    <?php endif; ?>
                    <script>
                    (function () {
                      var form = document.getElementById('order-prices-form');
                      if (!form) return;
                      var grand = document.getElementById('order-prices-grand');
                      function digits(s) {
                        return String(s || '')
                          .replace(/[۰-۹]/g, function (c) { return String(c.charCodeAt(0) - 1728); })
                          .replace(/[٠-٩]/g, function (c) { return String(c.charCodeAt(0) - 1632); })
                          .replace(/[^\d]/g, '');
                      }
                      function fmt(n) {
                        try { return (n || 0).toLocaleString('fa-IR') + ' تومان'; }
                        catch (e) { return String(n) + ' تومان'; }
                      }
                      function recalc() {
                        var total = 0;
                        form.querySelectorAll('.cms-price-toman').forEach(function (input) {
                          var row = input.closest('tr');
                          var cell = row ? row.querySelector('.cms-line-total') : null;
                          var qty = parseInt(input.getAttribute('data-qty') || '1', 10) || 1;
                          var raw = digits(input.value);
                          if (!raw) { if (cell) cell.textContent = '—'; return; }
                          var unit = parseInt(raw, 10) || 0;
                          var line = unit * qty;
                          total += line;
                          if (cell) cell.textContent = fmt(line);
                        });
                        if (grand) grand.textContent = fmt(total);
                      }
                      form.addEventListener('input', function (e) {
                        if (e.target && e.target.classList && e.target.classList.contains('cms-price-toman')) recalc();
                      });
                    })();
                    </script>
                  <?php endif; ?>
                </form>
              <?php endif; ?>
            </section>

            <section class="cms-vtabs__panel" data-panel="invoices" role="tabpanel" hidden>
              <h3 class="cms-vtabs__heading">پیش‌فاکتور و فاکتور</h3>
              <div class="cms-order-cards">
                <div class="cms-order-card">
                  <h4>پیش‌فاکتور</h4>
                  <?php if ($preFile !== ''): ?>
                    <p><a class="cms-btn cms-btn--secondary" href="<?= cms_h(rtrim(cms_site_base(), '/') . $preFile) ?>" target="_blank" rel="noopener">دانلود آخرین PDF</a></p>
                    <p class="cms-muted" style="margin:.5rem 0 0">
                      <?php if ($preCreated !== ''): ?>صدور: <span dir="ltr"><?= cms_h(cms_jalali_format_from_timestamp($preCreated)) ?></span><?php endif; ?>
                      <?php if ($preDue !== ''): ?> · سررسید: <span dir="ltr"><?= cms_h(cms_jalali_format_date($preDue)) ?></span><?php endif; ?>
                    </p>
                  <?php else: ?>
                    <p class="cms-muted">هنوز پیش‌فاکتوری صادر نشده.</p>
                  <?php endif; ?>
                </div>
                <div class="cms-order-card">
                  <h4>فاکتور نهایی</h4>
                  <?php if ($finalFile !== ''): ?>
                    <p><a class="cms-btn cms-btn--secondary" href="<?= cms_h(rtrim(cms_site_base(), '/') . $finalFile) ?>" target="_blank" rel="noopener">دانلود PDF</a></p>
                    <?php if ($finalCreated !== ''): ?>
                      <p class="cms-muted" style="margin:.5rem 0 0">صدور: <span dir="ltr"><?= cms_h(cms_jalali_format_from_timestamp($finalCreated)) ?></span></p>
                    <?php endif; ?>
                  <?php else: ?>
                    <p class="cms-muted">پس از تأیید پرداخت خودکار صادر می‌شود.</p>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($canEditPrices && $cur !== 'submitted'): ?>
                <form method="post" class="cms-invoice-form" style="margin-top:1.1rem">
                  <input type="hidden" name="id" value="<?= (int) $viewOrder['id'] ?>">
                  <?php $returnHiddens(); ?>
                  <?php foreach ($viewItems as $line): ?>
                    <input type="hidden" name="price[<?= (int) $line['id'] ?>]" value="<?= cms_h((string) ($line['price_text'] ?? '')) ?>">
                  <?php endforeach; ?>
                  <label class="cms-field" style="max-width:16rem">
                    <span class="cms-label">تاریخ سررسید پیش‌فاکتور</span>
                    <input class="cms-input" type="date" name="pre_invoice_due_at" dir="ltr" value="<?= cms_h($dueDefault) ?>" required>
                  </label>
                  <div class="cms-btn-row" style="margin-top:.75rem">
                    <button class="cms-btn" type="submit" name="action" value="issue_pre_invoice">
                      <?= $preFile !== '' ? 'ارسال مجدد پیش‌فاکتور' : 'صدور پیش‌فاکتور' ?>
                    </button>
                  </div>
                  <p class="cms-muted cms-vtabs__hint">مشتری همیشه آخرین پیش‌فاکتور را می‌بیند. مبالغ PDF به تومان و شامل جمع کل است.</p>
                </form>
              <?php elseif ($cur === 'submitted'): ?>
                <p class="cms-muted" style="margin-top:1rem">پیش‌فاکتور با «تأیید انبار و ارسال پیش‌فاکتور» از تب اقلام و قیمت صادر می‌شود.</p>
              <?php endif; ?>
            </section>

            <section class="cms-vtabs__panel" data-panel="payment" role="tabpanel" hidden>
              <h3 class="cms-vtabs__heading">مدارک پرداخت مشتری</h3>
              <?php if ($payWarnState === 'answered'): ?>
                <div class="cms-payment-answered">
                  <strong class="cms-payment-answered__badge">پاسخ مشتری</strong>
                  <p class="cms-payment-answered__text">مشتری به آخرین هشدار با مدارک زیر پاسخ داد</p>
                  <?php if ($payWarn !== ''): ?>
                    <p class="cms-muted" style="margin:.5rem 0 0;white-space:pre-wrap">آخرین هشدار ادمین: <?= cms_h($payWarn) ?></p>
                  <?php endif; ?>
                </div>
              <?php elseif ($payWarnState === 'open' && $payWarn !== ''): ?>
                <div class="cms-payment-warn">
                  <strong class="cms-payment-warn__badge">هشدار فعلی برای مشتری</strong>
                  <p class="cms-payment-warn__text"><?= cms_h($payWarn) ?></p>
                  <p class="cms-muted" style="margin:.5rem 0 0">در انتظار اصلاح و ارسال مجدد مدارک توسط مشتری.</p>
                </div>
              <?php endif; ?>

              <?php if ($payNote === '' && $payFiles === []): ?>
                <p class="cms-muted">هنوز مدارکی ارسال نشده<?= $cur === 'accepted' ? ' — پس از پیش‌فاکتور، مشتری مدارک را اینجا می‌فرستد.' : '.' ?></p>
              <?php else: ?>
                <?php if ($payAt !== '' && $payAt !== '0000-00-00 00:00:00'): ?>
                  <p class="cms-muted" style="margin:0 0 .5rem">زمان ارسال: <span dir="ltr"><?= cms_h($payAt) ?></span></p>
                <?php endif; ?>
                <?php if ($payNote !== ''): ?>
                  <div class="cms-client-msg">
                    <strong class="cms-client-msg__badge">پیام مشتری</strong>
                    <p class="cms-client-msg__text"><?= cms_h($payNote) ?></p>
                  </div>
                <?php endif; ?>
                <?php if ($payFiles !== []): ?>
                  <div class="cms-order-files">
                    <?php foreach ($payFiles as $payFile): ?>
                      <?php
                        $fileUrl = $payFile;
                        if (strpos($fileUrl, 'http') !== 0) {
                            $fileUrl = rtrim(cms_site_base(), '/') . $payFile;
                        }
                        $isImg = (bool) preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $payFile);
                      ?>
                      <?php if ($isImg): ?>
                        <a href="<?= cms_h($fileUrl) ?>" target="_blank" rel="noopener" class="cms-order-files__link" data-lightbox="payment">
                          <img src="<?= cms_h($fileUrl) ?>" alt="مدارک پرداخت" class="cms-order-files__thumb">
                        </a>
                      <?php else: ?>
                        <a class="cms-btn cms-btn--secondary" href="<?= cms_h($fileUrl) ?>" target="_blank" rel="noopener">فایل</a>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </section>

            <section class="cms-vtabs__panel" data-panel="history" role="tabpanel" hidden>
              <h3 class="cms-vtabs__heading">تاریخچه وضعیت</h3>
              <?php if ($viewEvents === []): ?>
                <p class="cms-empty">رویدادی ثبت نشده.</p>
              <?php else: ?>
                <table class="cms-table">
                  <thead>
                    <tr>
                      <th>از</th>
                      <th>به</th>
                      <th>پیام</th>
                      <th>توسط</th>
                      <th>تاریخ</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($viewEvents as $ev): ?>
                    <?php $isClient = (string) ($ev['actor'] ?? '') === 'client'; ?>
                    <tr class="<?= $isClient ? 'cms-row--client' : '' ?>">
                      <td><?= cms_h($statusLabels[(string) ($ev['from_status'] ?? '')] ?? ((string) ($ev['from_status'] ?? '—'))) ?></td>
                      <td><?= cms_h($statusLabels[(string) $ev['to_status']] ?? (string) $ev['to_status']) ?></td>
                      <td style="max-width:280px;white-space:pre-wrap" class="<?= $isClient ? 'cms-client-msg__text' : '' ?>"><?= cms_h((string) ($ev['message'] ?? '—')) ?></td>
                      <td>
                        <?php if ($isClient): ?>
                          <span class="cms-client-msg__badge">مشتری</span>
                        <?php else: ?>
                          ادمین
                        <?php endif; ?>
                      </td>
                      <td dir="ltr"><?= cms_h((string) $ev['created_at']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </section>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($nextActions !== []): ?>
    <div id="order-confirm-modal" class="cms-confirm-modal" hidden>
      <div class="cms-confirm-modal__panel" role="dialog" aria-modal="true" aria-labelledby="order-confirm-title">
        <div class="cms-confirm-modal__head">
          <strong id="order-confirm-title">تأیید تغییر وضعیت</strong>
          <button type="button" class="cms-btn cms-btn--ghost" id="order-confirm-close">بستن</button>
        </div>
        <p class="cms-muted" style="margin:0 0 .75rem">
          سفارش <span dir="ltr"><?= cms_h($orderCode) ?></span>
          — از «<?= cms_h($statusLabels[$cur] ?? $cur) ?>»
        </p>
        <div class="cms-confirm-modal__block">
          <strong class="cms-confirm-modal__label">پیام مشتری</strong>
          <?php if ($clientMessage !== ''): ?>
            <div class="cms-client-msg cms-confirm-modal__client">
              <p class="cms-client-msg__text" style="margin:0;white-space:pre-wrap"><?= cms_h($clientMessage) ?></p>
            </div>
          <?php else: ?>
            <p class="cms-confirm-modal__warn">مشتری پیامی نفرستاده است.</p>
          <?php endif; ?>
        </div>
        <label class="cms-field" style="margin-top:.85rem">
          <span>پیام ادمین برای این مرحله (قابل ویرایش)</span>
          <textarea class="cms-input" id="order-confirm-message" rows="4" placeholder="متن پیام برای مشتری…"></textarea>
        </label>
        <div class="cms-btn-row" style="margin-top:1rem">
          <button type="button" class="cms-btn cms-btn--ghost" id="order-confirm-cancel">انصراف</button>
          <button type="button" class="cms-btn" id="order-confirm-submit">تأیید و ادامه</button>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var form = document.getElementById('order-status-form');
      var modal = document.getElementById('order-confirm-modal');
      var draft = document.getElementById('order-message-draft');
      var actionInput = document.getElementById('order-action');
      var confirmMsg = document.getElementById('order-confirm-message');
      var titleEl = document.getElementById('order-confirm-title');
      var pendingAction = '';
      if (!form || !modal || !draft || !actionInput || !confirmMsg || !titleEl) return;
      function openModal(action, label) {
        pendingAction = action;
        titleEl.textContent = label + ' — <?= cms_h($orderCode) ?>';
        confirmMsg.value = draft.value;
        modal.hidden = false;
        confirmMsg.focus();
      }
      function closeModal() { modal.hidden = true; pendingAction = ''; }
      document.querySelectorAll('.order-stage-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          openModal(btn.getAttribute('data-action') || '', btn.getAttribute('data-label') || 'تغییر وضعیت');
        });
      });
      document.getElementById('order-confirm-close').addEventListener('click', closeModal);
      document.getElementById('order-confirm-cancel').addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { e.preventDefault(); closeModal(); }
      });
      document.getElementById('order-confirm-submit').addEventListener('click', function () {
        if (!pendingAction) return;
        draft.value = confirmMsg.value;
        actionInput.value = pendingAction;
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      });
    })();
    </script>
  <?php endif; ?>

  <div id="cms-lightbox" class="cms-lightbox" role="dialog" aria-modal="true" aria-hidden="true" hidden>
    <button type="button" class="cms-lightbox__close" id="cms-lightbox-close" aria-label="بستن">&times;</button>
    <button type="button" class="cms-lightbox__nav cms-lightbox__nav--prev" id="cms-lightbox-prev" aria-label="قبلی">&#8249;</button>
    <div class="cms-lightbox__stage">
      <img id="cms-lightbox-img" class="cms-lightbox__img" src="" alt="مدارک پرداخت">
    </div>
    <button type="button" class="cms-lightbox__nav cms-lightbox__nav--next" id="cms-lightbox-next" aria-label="بعدی">&#8250;</button>
    <div class="cms-lightbox__counter" id="cms-lightbox-counter"></div>
  </div>
  <script>
  (function () {
    var links = Array.prototype.slice.call(document.querySelectorAll('[data-lightbox="payment"]'));
    var box = document.getElementById('cms-lightbox');
    if (!links.length || !box) return;
    var imgEl = document.getElementById('cms-lightbox-img');
    var counterEl = document.getElementById('cms-lightbox-counter');
    var prevBtn = document.getElementById('cms-lightbox-prev');
    var nextBtn = document.getElementById('cms-lightbox-next');
    var closeBtn = document.getElementById('cms-lightbox-close');
    var urls = links.map(function (a) { return a.getAttribute('href'); });
    var idx = 0;

    function show(i) {
      idx = (i + urls.length) % urls.length;
      imgEl.src = urls[idx];
      counterEl.textContent = urls.length > 1 ? (idx + 1) + ' / ' + urls.length : '';
      var multi = urls.length > 1;
      prevBtn.hidden = !multi;
      nextBtn.hidden = !multi;
    }
    function openBox(i) {
      show(i);
      box.hidden = false;
      box.setAttribute('aria-hidden', 'false');
      document.body.classList.add('cms-lightbox-open');
    }
    function closeBox() {
      box.hidden = true;
      box.setAttribute('aria-hidden', 'true');
      imgEl.src = '';
      document.body.classList.remove('cms-lightbox-open');
    }
    links.forEach(function (a, i) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        openBox(i);
      });
    });
    closeBtn.addEventListener('click', closeBox);
    prevBtn.addEventListener('click', function () { show(idx - 1); });
    nextBtn.addEventListener('click', function () { show(idx + 1); });
    box.addEventListener('click', function (e) {
      if (e.target === box || e.target.classList.contains('cms-lightbox__stage')) closeBox();
    });
    document.addEventListener('keydown', function (e) {
      if (box.hidden) return;
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeBox(); }
      else if (e.key === 'ArrowLeft') show(idx - 1);
      else if (e.key === 'ArrowRight') show(idx + 1);
    }, true);
  })();
  </script>

  <script>
  (function () {
    var orderModal = document.getElementById('cms-order-modal');
    var closeHref = <?= json_encode($listCloseHref, JSON_UNESCAPED_UNICODE) ?>;
    if (orderModal) {
      orderModal.addEventListener('click', function (e) {
        if (e.target === orderModal) window.location.href = closeHref;
      });
      document.addEventListener('keydown', function (e) {
        var confirm = document.getElementById('order-confirm-modal');
        if (e.key === 'Escape' && (!confirm || confirm.hidden)) {
          window.location.href = closeHref;
        }
      });
    }
    var root = document.querySelector('.cms-vtabs');
    if (!root) return;
    var tabs = Array.prototype.slice.call(root.querySelectorAll('.cms-vtabs__tab'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('.cms-vtabs__panel'));
    var def = root.getAttribute('data-default-tab') || 'actions';
    function activate(id) {
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-tab') === id;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach(function (p) {
        var on = p.getAttribute('data-panel') === id;
        p.hidden = !on;
        p.classList.toggle('is-active', on);
      });
    }
    tabs.forEach(function (t) {
      t.addEventListener('click', function () { activate(t.getAttribute('data-tab') || 'actions'); });
    });
    activate(def);
  })();
  </script>
<?php endif; ?>

<?php cms_layout_end(); ?>
