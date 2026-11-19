<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/festivals.php';

cms_require_login();
$pdo = cms_pdo();
festivals_ensure_schema($pdo);

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : -1;
$participantsId = isset($_GET['participants']) ? (int) $_GET['participants'] : 0;
$errors = [];
$form = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'save'));
    try {
        if (in_array($action, ['mark_gift', 'unmark_gift'], true)) {
            $festivalId = (int) ($_POST['festival_id'] ?? 0);
            festivals_mark_tier_gift(
                $pdo,
                $festivalId,
                (int) ($_POST['sales_user_id'] ?? 0),
                (int) ($_POST['tier_id'] ?? 0),
                $action === 'mark_gift',
                cms_current_admin_id()
            );
            cms_flash($action === 'mark_gift' ? 'تحویل جایزه ثبت شد' : 'ثبت تحویل جایزه برداشته شد');
            $qs = ['participants' => (string) $festivalId];
            $backPage = max(1, (int) ($_POST['page'] ?? 1));
            $backQ = trim((string) ($_POST['q'] ?? ''));
            if ($backPage > 1) {
                $qs['page'] = (string) $backPage;
            }
            if ($backQ !== '') {
                $qs['q'] = $backQ;
            }
            cms_redirect('festivals.php?' . http_build_query($qs));
        }

        if (in_array($action, ['pause', 'resume', 'end', 'archive', 'duplicate', 'delete'], true)) {
            festivals_action(
                $pdo,
                (int) ($_POST['id'] ?? 0),
                $action,
                (int) ($_POST['version'] ?? 0)
            );
            if ($action === 'delete') {
                cms_flash('جشنواره حذف شد');
                cms_redirect('festivals.php');
            }
            if ($action === 'duplicate') {
                cms_flash('پیش‌نویس کپی ساخته شد');
            } else {
                cms_flash('وضعیت جشنواره به‌روز شد');
            }
            $back = (int) ($_POST['id'] ?? 0);
            cms_redirect($editId >= 0 ? 'festivals.php?edit=' . $back : 'festivals.php');
        }

        if ($action === 'save') {
            $tiersJson = (string) ($_POST['tiers_json'] ?? '[]');
            $tiers = json_decode($tiersJson, true);
            if (!is_array($tiers)) {
                throw new InvalidArgumentException('پله‌های جشنواره نامعتبر است');
            }
            $body = [
                'id' => (int) ($_POST['id'] ?? 0),
                'version' => (int) ($_POST['version'] ?? 0),
                'title' => (string) ($_POST['title'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'start_date' => (string) ($_POST['start_date'] ?? ''),
                'start_time' => (string) ($_POST['start_time'] ?? ''),
                'end_date' => (string) ($_POST['end_date'] ?? ''),
                'end_time' => (string) ($_POST['end_time'] ?? ''),
                'display_priority' => (int) ($_POST['display_priority'] ?? 0),
                'intent' => (string) ($_POST['intent'] ?? 'draft'),
                'tiers' => $tiers,
            ];
            $saved = festivals_save($pdo, $body);
            cms_flash('جشنواره ذخیره شد');
            cms_redirect('festivals.php?edit=' . (int) $saved['id']);
        }
    } catch (Throwable $e) {
        error_log('[festivals cms] ' . $e->getMessage());
        cms_flash($e->getMessage(), 'error');
        if ($action === 'save') {
            $form = $_POST;
            $errors['form'] = $e->getMessage();
        }
    }
}

cms_layout_start('جشنواره', cms_current_username(), 'shop');

if ($participantsId > 0) {
    $campaign = festivals_get($pdo, $participantsId);
    if ($campaign === null) {
        echo '<p class="cms-flash cms-flash--error">جشنواره یافت نشد.</p>';
        echo '<p><a class="cms-btn cms-btn--ghost" href="festivals.php">بازگشت</a></p>';
        cms_layout_end();
        exit;
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $list = festivals_admin_participants($pdo, $participantsId, [
        'page' => $page,
        'page_size' => 50,
        'q' => $q,
    ]);
    $tiers = is_array($campaign['tiers'] ?? null) ? $campaign['tiers'] : [];
    echo '<div class="cms-btn-row" style="margin-bottom:1rem">';
    echo '<a class="cms-btn cms-btn--ghost" href="festivals.php">فهرست جشنواره‌ها</a>';
    echo '<a class="cms-btn cms-btn--ghost" href="festivals.php?edit=' . $participantsId . '">ویرایش جشنواره</a>';
    echo '<h1 style="margin:0">نمایندگان — ' . cms_h((string) $campaign['title']) . '</h1>';
    echo '</div>';
    echo '<p class="cms-muted">وضعیت: ' . cms_h((string) $campaign['status_label'])
        . ' — حجم خرید پرداخت‌شده هر نماینده، پله‌های بازشده، و تحویل جایزه.</p>';
    echo '<form method="get" class="cms-btn-row" style="margin-bottom:1rem;flex-wrap:wrap">';
    echo '<input type="hidden" name="participants" value="' . $participantsId . '">';
    echo '<input class="cms-input" name="q" placeholder="نام یا نام کاربری" value="' . cms_h($q) . '">';
    echo '<button class="cms-btn" type="submit">جستجو</button>';
    echo '</form>';
    echo '<p class="cms-muted">' . cms_h(cms_to_persian_digits((string) $list['total'])) . ' نماینده</p>';
    if ($list['items'] === []) {
        echo '<p class="cms-muted">هنوز نماینده‌ای در این جشنواره حجم خرید ندارد.</p>';
    } else {
        echo '<div style="overflow:auto"><table class="cms-table"><thead><tr>';
        echo '<th>نماینده</th><th>حجم خرید</th><th>آخرین پله</th><th>تا پله بعد</th>';
        foreach ($tiers as $tier) {
            echo '<th>' . cms_h((string) $tier['benefit_title'])
                . '<div class="cms-muted">' . cms_h((string) $tier['threshold_text']) . '</div></th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($list['items'] as $row) {
            echo '<tr>';
            $displayName = trim((string) ($row['sales_user_name'] ?? ''));
            echo '<td>' . cms_h($displayName !== '' ? $displayName : ('#' . (int) $row['sales_user_id'])) . '</td>';
            echo '<td>' . cms_h((string) $row['total_text']) . '</td>';
            echo '<td>' . cms_h((string) ($row['highest_tier_title'] ?? '—')) . '</td>';
            echo '<td>' . cms_h((string) ($row['remaining_to_next_text'] ?? 'تمام پله‌ها')) . '</td>';
            $tierStates = is_array($row['tiers'] ?? null) ? $row['tiers'] : [];
            foreach ($tierStates as $state) {
                echo '<td>';
                if (empty($state['unlocked'])) {
                    echo '<span class="cms-muted">هنوز نرسیده</span>';
                } elseif (!empty($state['delivered'])) {
                    echo '<div>تحویل شد</div>';
                    if (!empty($state['delivered_at_jalali'])) {
                        echo '<div class="cms-muted">' . cms_h((string) $state['delivered_at_jalali']) . '</div>';
                    }
                    echo '<form method="post" style="margin-top:.35rem">';
                    echo '<input type="hidden" name="action" value="unmark_gift">';
                    echo '<input type="hidden" name="festival_id" value="' . $participantsId . '">';
                    echo '<input type="hidden" name="sales_user_id" value="' . (int) $row['sales_user_id'] . '">';
                    echo '<input type="hidden" name="tier_id" value="' . (int) $state['tier_id'] . '">';
                    echo '<input type="hidden" name="page" value="' . (int) $list['page'] . '">';
                    echo '<input type="hidden" name="q" value="' . cms_h($q) . '">';
                    echo '<button class="cms-btn cms-btn--ghost" type="submit">برداشت ثبت</button>';
                    echo '</form>';
                } else {
                    echo '<div>رسیده — جایزه نداده</div>';
                    echo '<form method="post" style="margin-top:.35rem">';
                    echo '<input type="hidden" name="action" value="mark_gift">';
                    echo '<input type="hidden" name="festival_id" value="' . $participantsId . '">';
                    echo '<input type="hidden" name="sales_user_id" value="' . (int) $row['sales_user_id'] . '">';
                    echo '<input type="hidden" name="tier_id" value="' . (int) $state['tier_id'] . '">';
                    echo '<input type="hidden" name="page" value="' . (int) $list['page'] . '">';
                    echo '<input type="hidden" name="q" value="' . cms_h($q) . '">';
                    echo '<button class="cms-btn" type="submit">ثبت تحویل جایزه</button>';
                    echo '</form>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if ((int) $list['total_pages'] > 1) {
            echo '<p class="cms-btn-row">';
            for ($i = 1; $i <= (int) $list['total_pages']; $i++) {
                $qs = ['participants' => (string) $participantsId, 'page' => (string) $i];
                if ($q !== '') {
                    $qs['q'] = $q;
                }
                $cls = $i === (int) $list['page'] ? 'cms-btn' : 'cms-btn cms-btn--ghost';
                echo '<a class="' . $cls . '" href="festivals.php?' . cms_h(http_build_query($qs)) . '">'
                    . cms_h(cms_to_persian_digits((string) $i)) . '</a>';
            }
            echo '</p>';
        }
    }
    cms_layout_end();
    exit;
}

if ($editId >= 0) {
    $campaign = $editId > 0 ? festivals_get($pdo, $editId) : null;
    if ($editId > 0 && $campaign === null && $form === null) {
        echo '<p class="cms-flash cms-flash--error">جشنواره یافت نشد.</p>';
        echo '<p><a class="cms-btn cms-btn--ghost" href="festivals.php">بازگشت</a></p>';
        cms_layout_end();
        exit;
    }
    $value = static function (string $key, string $fallback = '') use ($form, $campaign): string {
        if (is_array($form) && array_key_exists($key, $form)) {
            return (string) $form[$key];
        }
        if (is_array($campaign) && array_key_exists($key, $campaign) && !is_array($campaign[$key])) {
            return (string) $campaign[$key];
        }
        return $fallback;
    };
    $tiersForJs = [];
    if (is_array($form) && isset($form['tiers_json'])) {
        $decoded = json_decode((string) $form['tiers_json'], true);
        $tiersForJs = is_array($decoded) ? $decoded : [];
    } elseif (is_array($campaign)) {
        foreach ($campaign['tiers'] ?? [] as $tier) {
            $tiersForJs[] = [
                'threshold_toman' => $tier['threshold_toman'],
                'benefit_title' => $tier['benefit_title'],
                'benefit_description' => $tier['benefit_description'] ?? '',
            ];
        }
    }

    echo '<div class="cms-btn-row" style="margin-bottom:1rem">';
    echo '<a class="cms-btn cms-btn--ghost" href="festivals.php">فهرست جشنواره‌ها</a>';
    echo '<h1 style="margin:0">' . ($editId > 0 ? 'ویرایش جشنواره' : 'جشنواره جدید') . '</h1>';
    echo '</div>';
    if (isset($errors['form'])) {
        echo '<p class="cms-flash cms-flash--error">' . cms_h($errors['form']) . '</p>';
    }
    if ($editId > 0 && is_array($campaign)) {
        echo '<p class="cms-muted">وضعیت: ' . cms_h((string) $campaign['status_label'])
            . ' — نسخه ' . cms_h((string) $campaign['version'])
            . ' — آخرین تغییر ' . cms_h((string) $campaign['updated_at_jalali']) . '</p>';
        echo '<p class="cms-muted">مبلغ سفارش‌ها پس از تأیید پرداخت (وضعیت پرداخت شده) به حجم خرید نماینده اضافه می‌شود.</p>';
    }
    echo '<form method="post" id="festival-form" class="cms-card" style="padding:1rem">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<input type="hidden" name="id" value="' . (int) $value('id', $editId > 0 ? (string) $editId : '0') . '">';
    echo '<input type="hidden" name="version" value="' . cms_h($value('version', '1')) . '">';
    echo '<input type="hidden" name="intent" id="intent-field" value="' . cms_h($value('intent', 'draft')) . '">';
    echo '<label class="cms-field"><span class="cms-label">عنوان</span>';
    echo '<input class="cms-input" name="title" required value="' . cms_h($value('title')) . '"></label>';
    echo '<label class="cms-field"><span class="cms-label">توضیح (اختیاری)</span>';
    echo '<textarea class="cms-input" name="description" rows="3">' . cms_h($value('description')) . '</textarea></label>';
    echo '<input type="hidden" name="tiers_json" id="tiers-json" value="' . cms_h(json_encode($tiersForJs, JSON_UNESCAPED_UNICODE)) . '">';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">';
    echo '<label class="cms-field"><span class="cms-label">شروع (شمسی)</span><input class="cms-input cms-jalali-date" name="start_date" placeholder="1404/07/23" autocomplete="off" value="' . cms_h($value('start_date')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">ساعت شروع</span><input class="cms-input" name="start_time" placeholder="09:00" value="' . cms_h($value('start_time')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">پایان (شمسی)</span><input class="cms-input cms-jalali-date" name="end_date" placeholder="1404/07/23" autocomplete="off" value="' . cms_h($value('end_date')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">ساعت پایان</span><input class="cms-input" name="end_time" placeholder="21:00" value="' . cms_h($value('end_time')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">اولویت نمایش</span><input class="cms-input" type="number" name="display_priority" value="' . cms_h($value('display_priority', '0')) . '"></label>';
    echo '</div>';
    echo '<h2>پله‌های حجم خرید</h2>';
    echo '<p class="cms-muted">آستانه‌ها باید صعودی باشند (مثلاً ۵۰۰ میلیون، ۱ میلیارد تومان).</p>';
    echo '<div style="overflow:auto"><table class="cms-table" id="tier-table"><thead><tr>';
    echo '<th>آستانه (تومان)</th><th>عنوان جایزه</th><th>توضیح</th><th></th>';
    echo '</tr></thead><tbody></tbody></table></div>';
    echo '<button class="cms-btn cms-btn--secondary" type="button" id="add-tier">افزودن پله</button>';
    echo '<div class="cms-btn-row" style="margin-top:1rem">';
    echo '<button class="cms-btn cms-btn--secondary" type="submit" data-intent="draft">ذخیره پیش‌نویس</button>';
    echo '<button class="cms-btn cms-btn--secondary" type="submit" data-intent="schedule">زمان‌بندی</button>';
    echo '<button class="cms-btn" type="submit" data-intent="publish">انتشار</button>';
    echo '</div></form>';

    if ($editId > 0 && is_array($campaign)) {
        $preview = festivals_admin_participants($pdo, (int) $campaign['id'], ['page' => 1, 'page_size' => 10]);
        echo '<h2 style="margin-top:1.5rem">پیشرفت نمایندگان</h2>';
        echo '<p><a class="cms-btn" href="festivals.php?participants=' . (int) $campaign['id'] . '">داشبورد نمایندگان و تحویل جایزه</a></p>';
        if ($preview['items'] !== []) {
            echo '<table class="cms-table"><thead><tr><th>نماینده</th><th>حجم خرید</th><th>آخرین پله</th><th>به‌روزرسانی</th></tr></thead><tbody>';
            foreach ($preview['items'] as $row) {
                $displayName = trim((string) ($row['sales_user_name'] ?? ''));
                echo '<tr><td>' . cms_h($displayName !== '' ? $displayName : ('#' . (int) $row['sales_user_id'])) . '</td>';
                echo '<td>' . cms_h((string) $row['total_text']) . '</td>';
                echo '<td>' . cms_h((string) ($row['highest_tier_title'] ?? '—')) . '</td>';
                echo '<td>' . cms_h((string) ($row['updated_at_jalali'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table>';
            if ((int) $preview['total'] > 10) {
                echo '<p class="cms-muted">و ' . cms_h(cms_to_persian_digits((string) ((int) $preview['total'] - 10))) . ' نماینده دیگر</p>';
            }
        } else {
            echo '<p class="cms-muted">هنوز نماینده‌ای حجم خرید ندارد.</p>';
        }

        echo '<form method="post" class="cms-btn-row" style="margin-top:1rem">';
        echo '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">';
        echo '<input type="hidden" name="version" value="' . (int) $campaign['version'] . '">';
        if (in_array($campaign['status'], ['active', 'scheduled'], true)) {
            echo '<button class="cms-btn cms-btn--secondary" name="action" value="pause">توقف</button>';
        }
        if ($campaign['status'] === 'paused') {
            echo '<button class="cms-btn" name="action" value="resume">ادامه</button>';
        }
        if (!in_array($campaign['status'], ['ended', 'archived'], true)) {
            echo '<button class="cms-btn cms-btn--secondary" name="action" value="end">پایان</button>';
        }
        if ($campaign['status'] !== 'archived') {
            echo '<button class="cms-btn cms-btn--secondary" name="action" value="archive">بایگانی</button>';
        }
        echo '<button class="cms-btn cms-btn--secondary" name="action" value="duplicate">کپی</button>';
        echo '<button class="cms-btn" name="action" value="delete" style="background:#7f1d1d">حذف</button>';
        echo '</form>';
    }

    echo '<script>';
    echo 'const initialTiers = ' . json_encode($tiersForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';';
    echo <<<'JS'
const rows = Array.isArray(initialTiers) ? initialTiers.map(t => ({...t})) : [];
const tbody = document.querySelector('#tier-table tbody');
const hidden = document.getElementById('tiers-json');
function sync() {
  hidden.value = JSON.stringify(rows.map((t, i) => ({
    threshold_toman: t.threshold_toman || '',
    benefit_title: t.benefit_title || '',
    benefit_description: t.benefit_description || '',
    sort_order: i,
  })));
  tbody.innerHTML = '';
  rows.forEach((t, index) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input class="cms-input" data-i="${index}" data-k="threshold_toman" value="${t.threshold_toman ?? ''}"></td>
      <td><input class="cms-input" data-i="${index}" data-k="benefit_title" value="${t.benefit_title ?? ''}"></td>
      <td><input class="cms-input" data-i="${index}" data-k="benefit_description" value="${t.benefit_description ?? ''}"></td>
      <td><button type="button" class="cms-btn cms-btn--secondary" data-remove="${index}">حذف</button></td>`;
    tbody.appendChild(tr);
  });
}
tbody.addEventListener('input', (e) => {
  const el = e.target;
  if (!el.dataset.i) return;
  rows[Number(el.dataset.i)][el.dataset.k] = el.value;
  sync();
});
tbody.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-remove]');
  if (!btn) return;
  rows.splice(Number(btn.dataset.remove), 1);
  sync();
});
document.getElementById('add-tier').addEventListener('click', () => {
  rows.push({ threshold_toman: '', benefit_title: '', benefit_description: '' });
  sync();
});
const festivalForm = document.getElementById('festival-form');
const intentField = document.getElementById('intent-field');
festivalForm.querySelectorAll('button[type="submit"][data-intent]').forEach((btn) => {
  btn.addEventListener('click', () => {
    intentField.value = btn.getAttribute('data-intent') || 'draft';
  });
});
festivalForm.addEventListener('submit', () => sync());
sync();
JS;
    echo '</script>';
    echo '<script src="assets/cms-jalali-datepicker.js?v=1"></script>';
    cms_layout_end();
    exit;
}

$list = festivals_list($pdo, ['status' => 'open'], 1, 50);
echo '<div class="cms-btn-row" style="margin-bottom:1rem">';
echo '<h1 style="margin:0">جشنواره‌ها</h1>';
echo '<a class="cms-btn" href="festivals.php?edit=0">جشنواره جدید</a>';
echo '</div>';
if ($list['items'] === []) {
    echo '<p class="cms-muted">هنوز جشنواره‌ای ثبت نشده است.</p>';
} else {
    echo '<table class="cms-table"><thead><tr>';
    echo '<th>عنوان</th><th>وضعیت</th><th>بازه</th><th>پله‌ها</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ($list['items'] as $item) {
        echo '<tr>';
        echo '<td>' . cms_h((string) $item['title']) . '</td>';
        echo '<td>' . cms_h((string) $item['status_label']) . '</td>';
        echo '<td>' . cms_h((string) $item['starts_at_jalali']) . ' — ' . cms_h((string) $item['ends_at_jalali']) . '</td>';
        echo '<td>' . (int) ($item['tier_count'] ?? 0) . '</td>';
        echo '<td><div class="cms-btn-row" style="gap:.35rem">';
        echo '<a class="cms-btn cms-btn--ghost" href="festivals.php?edit=' . (int) $item['id'] . '">ویرایش</a>';
        echo '<a class="cms-btn cms-btn--ghost" href="festivals.php?participants=' . (int) $item['id'] . '">نمایندگان</a>';
        echo '</div></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

cms_layout_end();
