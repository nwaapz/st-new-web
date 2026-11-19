<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/cheque-control.php';
require_once __DIR__ . '/lib/invoices.php';

cms_require_login();
$pdo = cms_pdo();

$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$filter = trim((string) ($_GET['filter'] ?? 'all'));
$searchQ = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filterLabels = [
    'all' => 'همه',
    'pending' => 'در انتظار',
    'today' => 'سررسید امروز',
    'next_7' => '۷ روز آینده',
    'overdue' => 'سررسید گذشته',
    'returned' => 'برگشتی',
    'pending_verification' => 'منتظر تأیید',
    'high_value' => 'مبلغ بالا',
];
if (!isset($filterLabels[$filter])) {
    $filter = 'all';
}

$actionLabels = [
    'pending_verification' => 'ارسال به تأیید',
    'verify' => 'تأیید',
    'reject' => 'رد',
    'deliver' => 'تحویل به شرکت',
    'deposit' => 'واریز به بانک',
    'mark_due' => 'سررسید',
    'clear' => 'وصول',
    'bounce' => 'برگشت خورد',
    'return' => 'برگشتی',
    'reschedule' => 'تغییر سررسید',
    'cancel' => 'لغو',
];

$listQuery = static function (array $extra = []) use ($filter, $searchQ, $page): string {
    $params = ['filter' => $filter];
    if ($searchQ !== '') {
        $params['q'] = $searchQ;
    }
    if ($page > 1) {
        $params['page'] = (string) $page;
    }
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }
    return 'cheques.php?' . http_build_query($params);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string) ($_POST['action'] ?? ''));
    $chequeId = (int) ($_POST['cheque_id'] ?? 0);
    try {
        if ($postAction !== 'transition' || $chequeId <= 0) {
            throw new InvalidArgumentException('عملیات نامعتبر است');
        }
        cheque_workflow_apply_transition($pdo, $chequeId, [
            'action' => (string) ($_POST['workflow_action'] ?? ''),
            'note' => (string) ($_POST['note'] ?? ''),
            'due_on' => (string) ($_POST['due_on'] ?? ''),
        ], cms_current_admin());
        cms_flash('وضعیت چک به‌روز شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect($listQuery(['id' => (string) $chequeId, 'page' => null]));
}

cms_layout_start('چک‌ها', cms_current_username(), 'customers');

if ($viewId > 0) {
    $detail = cheque_control_detail($pdo, $viewId);
    echo '<div class="cms-btn-row" style="margin-bottom:1rem">';
    echo '<a class="cms-btn cms-btn--ghost" href="' . cms_h($listQuery(['id' => null])) . '">فهرست چک‌ها</a>';
    echo '<h1 style="margin:0">جزئیات چک</h1>';
    echo '</div>';
    if ($detail === null) {
        echo '<p class="cms-flash cms-flash--error">چک یافت نشد.</p>';
        cms_layout_end();
        exit;
    }
    $cheque = is_array($detail['cheque'] ?? null) ? $detail['cheque'] : [];
    $order = is_array($detail['order'] ?? null) ? $detail['order'] : [];
    $amount = isset($cheque['amount_text']) && (string) $cheque['amount_text'] !== ''
        ? (string) $cheque['amount_text']
        : invoices_format_toman((int) ($cheque['amount_toman'] ?? 0));
    echo '<div class="cms-card" style="padding:1rem;margin-bottom:1rem">';
    echo '<p><strong>' . cms_h((string) ($cheque['serial'] ?? 'چک')) . '</strong>';
    if (!empty($cheque['sayad_id'])) {
        echo ' <span class="cms-muted" dir="ltr">' . cms_h((string) $cheque['sayad_id']) . '</span>';
    }
    echo '</p>';
    echo '<p>مبلغ: <strong>' . cms_h($amount) . '</strong></p>';
    echo '<p>وضعیت: ' . cms_h((string) ($cheque['workflow_status_label'] ?? $cheque['status_label'] ?? '')) . '</p>';
    echo '<p>سررسید: ' . cms_h(order_cheques_format_date((string) ($cheque['due_on'] ?? ''))) . '</p>';
    echo '<p>تاریخ دریافت: ' . cms_h(order_cheques_format_date((string) ($cheque['received_on'] ?? ''))) . '</p>';
    if (!empty($cheque['bank_name'])) {
        echo '<p>بانک: ' . cms_h((string) $cheque['bank_name']) . '</p>';
    }
    echo '<p>مشتری: ' . cms_h((string) ($cheque['customer_name'] ?? $order['customer_name'] ?? '—'));
    if (!empty($cheque['phone']) || !empty($order['phone'])) {
        echo ' <span class="cms-muted" dir="ltr">' . cms_h((string) ($cheque['phone'] ?? $order['phone'] ?? '')) . '</span>';
    }
    echo '</p>';
    if (!empty($cheque['sales_user_name']) || !empty($order['sales_user_name'])) {
        echo '<p>نماینده: ' . cms_h((string) ($cheque['sales_user_name'] ?? $order['sales_user_name'])) . '</p>';
    }
    $orderId = (int) ($order['id'] ?? $cheque['order_id'] ?? 0);
    if ($orderId > 0) {
        echo '<p>سفارش: <a href="orders.php?' . cms_h(http_build_query([
            'scope' => 'customers',
            'id' => (string) $orderId,
        ])) . '">' . cms_h((string) ($order['public_code'] ?? $cheque['order_public_code'] ?? ('#' . $orderId))) . '</a></p>';
    }
    $riskReasons = is_array($cheque['risk_reasons'] ?? null) ? $cheque['risk_reasons'] : [];
    if ($riskReasons !== []) {
        echo '<p class="cms-confirm-modal__warn">' . cms_h(implode('، ', $riskReasons)) . '</p>';
    }
    echo '</div>';

    $steps = is_array($detail['workflow_steps'] ?? null) ? $detail['workflow_steps'] : [];
    if ($steps !== []) {
        echo '<p class="cms-muted">گردش وضعیت: ';
        $parts = [];
        foreach ($steps as $step) {
            $label = (string) ($step['label'] ?? '');
            $state = (string) ($step['state'] ?? '');
            if ($state === 'current') {
                $parts[] = '<strong>' . cms_h($label) . '</strong>';
            } else {
                $parts[] = cms_h($label);
            }
        }
        echo implode(' ← ', $parts);
        echo '</p>';
    }

    $allowed = is_array($detail['allowed_actions'] ?? null) ? $detail['allowed_actions'] : [];
    if ($allowed !== []) {
        echo '<form method="post" class="cms-card" style="padding:1rem;margin-bottom:1rem">';
        echo '<input type="hidden" name="action" value="transition">';
        echo '<input type="hidden" name="cheque_id" value="' . $viewId . '">';
        echo '<div class="cms-btn-row" style="flex-wrap:wrap">';
        foreach ($allowed as $workflowAction) {
            $label = $actionLabels[$workflowAction] ?? (string) $workflowAction;
            echo '<button class="cms-btn cms-btn--secondary" type="submit" name="workflow_action" value="'
                . cms_h((string) $workflowAction) . '">' . cms_h($label) . '</button>';
        }
        echo '</div>';
        echo '<label class="cms-field" style="margin-top:.75rem"><span class="cms-label">یادداشت (اختیاری)</span>';
        echo '<input class="cms-input" name="note"></label>';
        echo '<label class="cms-field"><span class="cms-label">سررسید جدید (برای تغییر سررسید)</span>';
        echo '<input class="cms-input" name="due_on" placeholder="1404/09/01 یا 2026-11-19" dir="ltr"></label>';
        echo '</form>';
    }

    $history = is_array($detail['status_history'] ?? null) ? $detail['status_history'] : [];
    if ($history !== []) {
        echo '<h2>تاریخچه وضعیت</h2>';
        echo '<table class="cms-table"><thead><tr><th>از</th><th>به</th><th>توسط</th><th>زمان</th><th>یادداشت</th></tr></thead><tbody>';
        foreach ($history as $row) {
            echo '<tr>';
            echo '<td>' . cms_h((string) ($row['from_label'] ?? '—')) . '</td>';
            echo '<td>' . cms_h((string) ($row['to_label'] ?? '')) . '</td>';
            echo '<td>' . cms_h((string) ($row['changed_by'] ?? '')) . '</td>';
            echo '<td>' . cms_h(cms_jalali_format_datetime((string) ($row['created_at'] ?? ''))) . '</td>';
            echo '<td>' . cms_h((string) ($row['note'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    cms_layout_end();
    exit;
}

$dashboard = cheque_control_dashboard($pdo);
$kpis = is_array($dashboard['kpis'] ?? null) ? $dashboard['kpis'] : [];
$list = cheque_control_list($pdo, $filter, $searchQ, $page, 20);

echo '<h1 style="margin-top:0">چک‌ها</h1>';
echo '<p class="cms-muted">چک‌های ثبت‌شده روی سفارش‌ها — سررسید، وصول و برگشت.</p>';

echo '<div class="cms-log-summary">';
$kpiCards = [
    'outstanding_toman' => 'مانده در جریان',
    'due_7d_toman' => 'سررسید ۷ روز',
    'overdue_toman' => 'سررسید گذشته',
    'returned_toman' => 'برگشتی',
    'collected_month_toman' => 'وصول این ماه',
];
foreach ($kpiCards as $key => $label) {
    echo '<div class="cms-log-stat"><span class="cms-log-stat__label">' . cms_h($label) . '</span>';
    echo '<span class="cms-log-stat__value">' . cms_h(invoices_format_toman((int) ($kpis[$key] ?? 0))) . '</span></div>';
}
echo '</div>';

$actions = is_array($dashboard['actions'] ?? null) ? $dashboard['actions'] : [];
if ($actions !== []) {
    echo '<div class="cms-btn-row" style="margin:1rem 0;flex-wrap:wrap">';
    foreach ($actions as $item) {
        $hrefFilter = (string) ($item['filter'] ?? 'all');
        echo '<a class="cms-btn cms-btn--ghost" href="cheques.php?filter=' . cms_h(urlencode($hrefFilter)) . '">'
            . cms_h((string) ($item['label'] ?? '')) . '</a>';
    }
    echo '</div>';
}

echo '<form method="get" class="cms-btn-row" style="margin-bottom:1rem;flex-wrap:wrap">';
echo '<select class="cms-select" name="filter">';
foreach ($filterLabels as $key => $label) {
    $sel = $filter === $key ? ' selected' : '';
    echo '<option value="' . cms_h($key) . '"' . $sel . '>' . cms_h($label) . '</option>';
}
echo '</select>';
echo '<input class="cms-input" name="q" placeholder="سریال، صیاد، سفارش، مشتری، نماینده" value="' . cms_h($searchQ) . '">';
echo '<button class="cms-btn" type="submit">اعمال</button>';
echo '</form>';

echo '<p class="cms-muted">' . cms_h(cms_to_persian_digits((string) $list['total'])) . ' چک</p>';

if ($list['cheques'] === []) {
    echo '<p class="cms-muted">چکی با این فیلتر نیست.</p>';
} else {
    echo '<div style="overflow:auto"><table class="cms-table"><thead><tr>';
    echo '<th>سریال</th><th>مبلغ</th><th>سررسید</th><th>وضعیت</th><th>مشتری</th><th>نماینده</th><th>سفارش</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ($list['cheques'] as $row) {
        $id = (int) ($row['id'] ?? 0);
        $amount = isset($row['amount_text']) && (string) $row['amount_text'] !== ''
            ? (string) $row['amount_text']
            : invoices_format_toman((int) ($row['amount_toman'] ?? 0));
        echo '<tr>';
        echo '<td>' . cms_h((string) ($row['serial'] ?? '—'));
        if (!empty($row['sayad_id'])) {
            echo '<div class="cms-muted" dir="ltr">' . cms_h((string) $row['sayad_id']) . '</div>';
        }
        echo '</td>';
        echo '<td>' . cms_h($amount) . '</td>';
        echo '<td>' . cms_h(order_cheques_format_date((string) ($row['due_on'] ?? '')));
        $days = (int) ($row['days_to_due'] ?? 0);
        if (!empty($row['is_pending'])) {
            if ($days < 0) {
                echo '<div class="cms-muted">' . cms_h(cms_to_persian_digits((string) abs($days))) . ' روز گذشته</div>';
            } elseif ($days === 0) {
                echo '<div class="cms-muted">امروز</div>';
            }
        }
        echo '</td>';
        echo '<td>' . cms_h((string) ($row['workflow_status_label'] ?? $row['status_label'] ?? '')) . '</td>';
        echo '<td>' . cms_h((string) ($row['customer_name'] ?? '—'));
        if (!empty($row['phone'])) {
            echo '<div class="cms-muted" dir="ltr">' . cms_h((string) $row['phone']) . '</div>';
        }
        echo '</td>';
        echo '<td>' . cms_h((string) ($row['sales_user_name'] ?? '—')) . '</td>';
        echo '<td>' . cms_h((string) ($row['order_public_code'] ?? '—')) . '</td>';
        echo '<td><a class="cms-btn cms-btn--ghost" href="' . cms_h($listQuery(['id' => (string) $id, 'page' => null])) . '">مشاهده</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    if ((int) $list['total_pages'] > 1) {
        echo '<p class="cms-btn-row">';
        for ($i = 1; $i <= (int) $list['total_pages']; $i++) {
            $cls = $i === (int) $list['page'] ? 'cms-btn' : 'cms-btn cms-btn--ghost';
            echo '<a class="' . $cls . '" href="' . cms_h($listQuery(['page' => (string) $i, 'id' => null])) . '">'
                . cms_h(cms_to_persian_digits((string) $i)) . '</a>';
        }
        echo '</p>';
    }
}

cms_layout_end();
