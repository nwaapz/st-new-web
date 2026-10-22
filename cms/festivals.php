<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/festivals.php';

cms_require_login();
$pdo = cms_pdo();
festivals_ensure_schema($pdo);

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : -1;
$errors = [];
$form = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'save'));
    try {
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
    echo '<input type="hidden" name="tiers_json" id="tiers-json" value="' . cms_h(json_encode($tiersForJs, JSON_UNESCAPED_UNICODE)) . '">';
    echo '<label class="cms-field"><span class="cms-label">عنوان</span>';
    echo '<input class="cms-input" name="title" required value="' . cms_h($value('title')) . '"></label>';
    echo '<label class="cms-field"><span class="cms-label">توضیح (اختیاری)</span>';
    echo '<textarea class="cms-input" name="description" rows="3">' . cms_h($value('description')) . '</textarea></label>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">';
    echo '<label class="cms-field"><span class="cms-label">شروع (شمسی)</span><input class="cms-input" name="start_date" value="' . cms_h($value('start_date')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">ساعت شروع</span><input class="cms-input" name="start_time" placeholder="09:00" value="' . cms_h($value('start_time')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">پایان (شمسی)</span><input class="cms-input" name="end_date" value="' . cms_h($value('end_date')) . '" required></label>';
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
    echo '<button class="cms-btn cms-btn--secondary" type="submit" name="intent" value="draft">ذخیره پیش‌نویس</button>';
    echo '<button class="cms-btn cms-btn--secondary" type="submit" name="intent" value="schedule">زمان‌بندی</button>';
    echo '<button class="cms-btn" type="submit" name="intent" value="publish">انتشار</button>';
    echo '</div></form>';

    if ($editId > 0 && is_array($campaign)) {
        $participants = festivals_admin_participants($pdo, (int) $campaign['id'], 20);
        if ($participants !== []) {
            echo '<h2 style="margin-top:1.5rem">پیشرفت نمایندگان (۲۰ نفر اول)</h2>';
            echo '<table class="cms-table"><thead><tr><th>نماینده</th><th>حجم خرید</th><th>به‌روزرسانی</th></tr></thead><tbody>';
            foreach ($participants as $row) {
                echo '<tr><td>' . cms_h((string) ($row['sales_user_name'] ?? ('#' . $row['sales_user_id']))) . '</td>';
                echo '<td>' . cms_h((string) $row['total_text']) . '</td>';
                echo '<td>' . cms_h(cms_jalali_format_datetime((string) $row['updated_at'])) . '</td></tr>';
            }
            echo '</tbody></table>';
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
sync();
JS;
    echo '</script>';
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
        echo '<td><a class="cms-btn cms-btn--ghost" href="festivals.php?edit=' . (int) $item['id'] . '">ویرایش</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

cms_layout_end();
