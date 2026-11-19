<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/flash-deals.php';
require_once __DIR__ . '/lib/uploads.php';

cms_require_login();
$pdo = cms_pdo();
flash_deals_ensure_schema($pdo);

if (isset($_GET['product_q'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'products' => flash_deals_search_products($pdo, (string) $_GET['product_q']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : -1;
$errors = [];
$form = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    try {
        if (in_array($action, ['pause', 'resume', 'end', 'archive', 'duplicate', 'delete'], true)) {
            $result = flash_deals_action(
                $pdo,
                (int) ($_POST['id'] ?? 0),
                $action,
                (int) ($_POST['version'] ?? 0)
            );
            if ($action === 'delete') {
                cms_flash('پیشنهاد ویژه حذف شد');
                cms_redirect('flash-deals.php');
            }
            if ($action === 'duplicate') {
                cms_flash('پیش‌نویس جدید ساخته شد. زمان‌بندی را بررسی و سپس منتشر کنید.');
                cms_redirect('flash-deals.php?edit=' . (int) ($result['id'] ?? 0));
            }
            cms_flash('وضعیت پیشنهاد به‌روز شد');
            $back = (int) ($_POST['id'] ?? 0);
            cms_redirect($editId >= 0 ? 'flash-deals.php?edit=' . $back : 'flash-deals.php');
        }

        if ($action === 'save') {
            $postedId = (int) ($_POST['id'] ?? 0);
            $existingBanner = '';
            if ($postedId > 0) {
                $current = flash_deals_get($pdo, $postedId, false);
                $existingBanner = (string) ($current['banner_image'] ?? '');
            }
            $banner = cms_handle_optional_upload('banner_image', $existingBanner);
            $products = json_decode((string) ($_POST['products_json'] ?? '[]'), true);
            if (!is_array($products)) {
                $products = [];
            }
            $body = [
                'id' => $postedId,
                'version' => (int) ($_POST['version'] ?? 0),
                'title' => (string) ($_POST['title'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'banner_image' => $banner,
                'start_date' => (string) ($_POST['start_date'] ?? ''),
                'start_time' => (string) ($_POST['start_time'] ?? ''),
                'end_date' => (string) ($_POST['end_date'] ?? ''),
                'end_time' => (string) ($_POST['end_time'] ?? ''),
                'display_priority' => (int) ($_POST['display_priority'] ?? 0),
                'intent' => (string) ($_POST['intent'] ?? 'draft'),
                'products' => $products,
            ];
            $saved = flash_deals_save($pdo, $body);
            $warn = '';
            if (!empty($saved['warnings'][0])) {
                $warn = ' ' . (string) $saved['warnings'][0];
            }
            cms_flash('پیشنهاد ویژه ذخیره شد.' . $warn);
            cms_redirect('flash-deals.php?edit=' . (int) $saved['id']);
        }
    } catch (FlashDealException $e) {
        flash_deals_audit($pdo, 'flash_deal.failed', (int) ($_POST['id'] ?? 0), trim((string) ($_POST['title'] ?? '')), $e->getMessage(), ['action' => $action, 'code' => $e->errorCode]);
        cms_flash($e->getMessage(), 'error');
        if ($action === 'save') {
            $form = $_POST;
            $form['banner_image'] = (string) ($_POST['banner_image'] ?? '');
            $errors['form'] = $e->getMessage();
        }
    } catch (InvalidArgumentException $e) {
        flash_deals_audit_failure($pdo, (int) ($_POST['id'] ?? 0), $action !== '' ? $action : 'save', $e->getMessage());
        cms_flash($e->getMessage(), 'error');
        if ($action === 'save') {
            $form = $_POST;
            $errors['form'] = $e->getMessage();
        }
    } catch (Throwable $e) {
        error_log('[flash-deals cms] ' . $e->getMessage());
        flash_deals_audit(
            $pdo,
            'flash_deal.failed',
            (int) ($_POST['id'] ?? 0),
            trim((string) ($_POST['title'] ?? '')),
            'عملیات پیشنهاد ویژه ناموفق بود',
            ['action' => $action]
        );
        cms_flash('ذخیره پیشنهاد ناموفق بود', 'error');
        if ($action === 'save') {
            $form = $_POST;
        }
    }
}

cms_layout_start('پیشنهاد ویژه', cms_current_username(), 'shop');

if ($editId >= 0) {
    $campaign = $editId > 0 ? flash_deals_get($pdo, $editId) : null;
    if ($editId > 0 && $campaign === null && $form === null) {
        echo '<p class="cms-flash cms-flash--error">کمپین یافت نشد.</p>';
        echo '<p><a class="cms-btn cms-btn--ghost" href="flash-deals.php">بازگشت</a></p>';
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
    $productsForJs = [];
    if (is_array($form) && isset($form['products_json'])) {
        $decoded = json_decode((string) $form['products_json'], true);
        $productsForJs = is_array($decoded) ? $decoded : [];
    } elseif (is_array($campaign)) {
        foreach ($campaign['products'] as $product) {
            $productsForJs[] = [
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'visual_id' => $product['visual_id'],
                'image' => $product['image'],
                'regular_unit_price' => $product['regular_unit_price'],
                'regular_price_text' => $product['regular_price_text'],
                'promo_unit_price' => $product['promo_unit_price'],
                'discount_percent' => $product['discount_percent'],
                'per_order_qty_limit' => $product['per_order_qty_limit'],
                'per_customer_qty_limit' => $product['per_customer_qty_limit'],
                'campaign_qty_limit' => $product['campaign_qty_limit'],
                'display_priority' => $product['display_priority'],
                'eligibility_status' => $product['eligibility_status'],
            ];
        }
    }
    $bannerValue = $value('banner_image');
    echo '<div class="cms-btn-row" style="margin-bottom:1rem">';
    echo '<a class="cms-btn cms-btn--ghost" href="flash-deals.php">فهرست پیشنهادها</a>';
    echo '<h1 style="margin:0">' . ($editId > 0 ? 'ویرایش پیشنهاد ویژه' : 'پیشنهاد ویژه جدید') . '</h1>';
    echo '</div>';
    if (isset($errors['form'])) {
        echo '<p class="cms-flash cms-flash--error">' . cms_h($errors['form']) . '</p>';
    }
    if ($editId > 0 && is_array($campaign)) {
        echo '<p class="cms-muted">وضعیت: ' . cms_h((string) $campaign['status_label'])
            . ' — نسخه ' . cms_h((string) $campaign['version'])
            . ' — آخرین تغییر ' . cms_h((string) $campaign['updated_at_jalali']) . '</p>';
        echo '<p class="cms-muted">تغییر قیمت، حذف محصول یا تاریخ فقط خریدهای بعدی را عوض می‌کند. سفارش‌های ثبت‌شده قیمت پذیرفته‌شده را نگه می‌دارند.</p>';
    }
    echo '<form method="post" enctype="multipart/form-data" id="flash-deal-form" class="cms-card" style="padding:1rem">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<input type="hidden" name="id" value="' . (int) $value('id', $editId > 0 ? (string) $editId : '0') . '">';
    echo '<input type="hidden" name="version" value="' . cms_h($value('version', '1')) . '">';
    echo '<input type="hidden" name="products_json" id="products-json" value="' . cms_h(json_encode($productsForJs, JSON_UNESCAPED_UNICODE)) . '">';
    echo '<label class="cms-field"><span class="cms-label">عنوان</span>';
    echo '<input class="cms-input" name="title" required value="' . cms_h($value('title')) . '"></label>';
    echo '<label class="cms-field"><span class="cms-label">توضیح (اختیاری)</span>';
    echo '<textarea class="cms-input cms-textarea cms-festival-desc" name="description" rows="8">' . cms_h($value('description')) . '</textarea></label>';
    cms_image_field('banner_image', 'بنر (اختیاری)', $bannerValue);
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem">';
    echo '<label class="cms-field"><span class="cms-label">شروع (شمسی)</span><input class="cms-input cms-jalali-date" name="start_date" placeholder="1404/07/23" autocomplete="off" value="' . cms_h($value('start_date')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">ساعت شروع</span><input class="cms-input" name="start_time" placeholder="09:00" value="' . cms_h($value('start_time')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">پایان (شمسی)</span><input class="cms-input cms-jalali-date" name="end_date" placeholder="1404/07/24" autocomplete="off" value="' . cms_h($value('end_date')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">ساعت پایان</span><input class="cms-input" name="end_time" placeholder="21:00" value="' . cms_h($value('end_time')) . '" required></label>';
    echo '<label class="cms-field"><span class="cms-label">اولویت نمایش</span><input class="cms-input" type="number" name="display_priority" value="' . cms_h($value('display_priority', '0')) . '"></label>';
    echo '</div>';
    echo '<h2>محصولات</h2>';
    echo '<p class="cms-muted">جستجو با کد نمایش کالا (visual ID) — مثلاً ۱۲۳۴ یا ST-1234. Enter فقط جستجو می‌کند، فرم ذخیره نمی‌شود.</p>';
    echo '<div class="cms-btn-row" style="flex-wrap:wrap;align-items:flex-end">';
    echo '<label class="cms-field" style="margin:0;flex:1;min-width:16rem"><span class="cms-label">کد نمایش یا نام</span>';
    echo '<input class="cms-input" id="product-q" type="search" placeholder="کد نمایش کالا" autocomplete="off" dir="ltr"></label>';
    echo '<button class="cms-btn cms-btn--secondary" type="button" id="product-search">جستجو</button>';
    echo '</div>';
    echo '<div id="product-results" class="cms-flash-deal-results cms-muted"></div>';
    echo '<div id="product-list" class="cms-festival-tiers"></div>';
    echo '<h2>پیش‌نمایش کارت مشتری</h2><div id="offer-preview" class="cms-flash-deal-preview"></div>';
    echo '<div class="cms-btn-row" style="margin-top:1rem">';
    echo '<button class="cms-btn cms-btn--secondary" type="submit" name="intent" value="draft">ذخیره پیش‌نویس</button>';
    echo '<button class="cms-btn cms-btn--secondary" type="submit" name="intent" value="schedule">زمان‌بندی</button>';
    echo '<button class="cms-btn" type="submit" name="intent" value="publish">انتشار</button>';
    echo '</div></form>';
    if ($editId > 0 && is_array($campaign)) {
        $confirm = static function (string $message): string {
            return 'return confirm(' . json_encode($message, JSON_UNESCAPED_UNICODE) . ')';
        };
        echo '<form method="post" class="cms-btn-row" style="margin-top:1rem" onsubmit="' . $confirm('این عملیات روی خریدهای جدید اثر می‌گذارد. ادامه می‌دهید؟') . '">';
        echo '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">';
        echo '<input type="hidden" name="version" value="' . (int) $campaign['version'] . '">';
        if (in_array($campaign['status'], ['active', 'scheduled'], true)) {
            echo '<button class="cms-btn cms-btn--secondary" name="action" value="pause">توقف موقت</button>';
        }
        if ($campaign['status'] === 'paused') {
            echo '<button class="cms-btn" name="action" value="resume">ادامه</button>';
        }
        if (!in_array($campaign['status'], ['ended', 'archived'], true)) {
            echo '<button class="cms-btn cms-btn--secondary" name="action" value="end">پایان همین حالا</button>';
        }
        if ($campaign['status'] !== 'archived') {
            echo '<button class="cms-btn cms-btn--secondary" name="action" value="archive">بایگانی</button>';
        }
        echo '<button class="cms-btn cms-btn--secondary" name="action" value="duplicate">کپی به پیش‌نویس</button>';
        echo '<button class="cms-btn" name="action" value="delete" style="background:#7f1d1d">حذف</button>';
        echo '</form>';
        echo '<p class="cms-muted">حذف دائمی فقط وقتی ممکن است که هیچ سفارشی به این پیشنهاد وصل نباشد. در غیر این صورت بایگانی کنید.</p>';
    }
    echo '<script>';
    echo 'const initialProducts = ' . json_encode($productsForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';';
    echo <<<'JS'
const rows = Array.isArray(initialProducts) ? initialProducts.map(p => ({...p})) : [];
const list = document.getElementById('product-list');
const preview = document.getElementById('offer-preview');
const hidden = document.getElementById('products-json');
const searchInput = document.getElementById('product-q');
const searchBtn = document.getElementById('product-search');
const resultsBox = document.getElementById('product-results');

function toman(n) {
  const v = Number(n || 0);
  return v.toLocaleString('fa-IR') + ' تومان';
}
function discountOf(regular, promo) {
  const r = Number(regular || 0);
  const p = Number(promo || 0);
  if (r <= 0 || p <= 0 || p >= r) return '—';
  const amount = r - p;
  const pct = Math.round((amount * 10000) / r) / 100;
  return pct.toLocaleString('fa-IR') + '٪ (' + toman(amount) + ')';
}
function writeJson() {
  hidden.value = JSON.stringify(rows.map(p => ({
    product_id: Number(p.product_id),
    promo_unit_price: Number(p.promo_unit_price || 0),
    per_order_qty_limit: p.per_order_qty_limit || '',
    per_customer_qty_limit: p.per_customer_qty_limit || '',
    campaign_qty_limit: p.campaign_qty_limit || '',
    display_priority: Number(p.display_priority || 0),
  })));
}
function makeField(labelText, control) {
  const wrap = document.createElement('label');
  wrap.className = 'cms-field';
  const span = document.createElement('span');
  span.className = 'cms-label';
  span.textContent = labelText;
  wrap.appendChild(span);
  wrap.appendChild(control);
  return wrap;
}
function bindValue(control, index, key, after) {
  control.addEventListener('input', () => {
    rows[index][key] = control.value;
    writeJson();
    if (after) after();
  });
}
function renderPreview() {
  preview.replaceChildren();
  if (!rows.length) {
    const empty = document.createElement('p');
    empty.className = 'cms-muted';
    empty.textContent = 'محصولی انتخاب نشده است.';
    preview.appendChild(empty);
    return;
  }
  rows.forEach((p) => {
    const card = document.createElement('div');
    card.className = 'cms-flash-deal-preview__card';
    const name = document.createElement('strong');
    name.textContent = p.name || '';
    const code = document.createElement('div');
    code.className = 'cms-muted';
    code.dir = 'ltr';
    code.textContent = p.visual_id || '';
    const oldP = document.createElement('div');
    oldP.className = 'cms-muted';
    oldP.style.textDecoration = 'line-through';
    oldP.textContent = p.regular_price_text || toman(p.regular_unit_price);
    const newP = document.createElement('div');
    newP.textContent = toman(p.promo_unit_price);
    const disc = document.createElement('div');
    disc.textContent = discountOf(p.regular_unit_price, p.promo_unit_price);
    card.appendChild(name);
    card.appendChild(code);
    card.appendChild(oldP);
    card.appendChild(newP);
    card.appendChild(disc);
    preview.appendChild(card);
  });
}
function renderProducts() {
  list.replaceChildren();
  if (!rows.length) {
    const empty = document.createElement('p');
    empty.className = 'cms-muted';
    empty.textContent = 'محصولی در این پیشنهاد نیست. با کد نمایش جستجو کنید و اضافه کنید.';
    list.appendChild(empty);
    writeJson();
    renderPreview();
    return;
  }
  rows.forEach((p, index) => {
    const card = document.createElement('article');
    card.className = 'cms-festival-tier';
    const head = document.createElement('div');
    head.className = 'cms-festival-tier__head';
    const title = document.createElement('div');
    const nameEl = document.createElement('strong');
    nameEl.textContent = p.name || 'محصول';
    const codeEl = document.createElement('div');
    codeEl.className = 'cms-muted';
    codeEl.dir = 'ltr';
    codeEl.textContent = p.visual_id ? String(p.visual_id) : 'بدون کد نمایش';
    title.appendChild(nameEl);
    title.appendChild(codeEl);
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'cms-btn cms-btn--secondary';
    remove.textContent = 'حذف';
    remove.addEventListener('click', () => {
      rows.splice(index, 1);
      renderProducts();
    });
    head.appendChild(title);
    head.appendChild(remove);

    const regular = document.createElement('p');
    regular.className = 'cms-muted';
    regular.textContent = 'قیمت عادی: ' + (p.regular_price_text || toman(p.regular_unit_price));

    const promo = document.createElement('input');
    promo.className = 'cms-input';
    promo.inputMode = 'numeric';
    promo.placeholder = 'قیمت ویژه (تومان)';
    promo.value = p.promo_unit_price != null && p.promo_unit_price !== '' ? String(p.promo_unit_price) : '';

    const disc = document.createElement('p');
    disc.className = 'cms-muted';
    disc.textContent = 'تخفیف: ' + discountOf(p.regular_unit_price, p.promo_unit_price);
    bindValue(promo, index, 'promo_unit_price', () => {
      disc.textContent = 'تخفیف: ' + discountOf(p.regular_unit_price, p.promo_unit_price);
      renderPreview();
    });

    const orderLim = document.createElement('input');
    orderLim.className = 'cms-input';
    orderLim.inputMode = 'numeric';
    orderLim.value = p.per_order_qty_limit != null ? String(p.per_order_qty_limit) : '';
    bindValue(orderLim, index, 'per_order_qty_limit');

    const custLim = document.createElement('input');
    custLim.className = 'cms-input';
    custLim.inputMode = 'numeric';
    custLim.value = p.per_customer_qty_limit != null ? String(p.per_customer_qty_limit) : '';
    bindValue(custLim, index, 'per_customer_qty_limit');

    const campLim = document.createElement('input');
    campLim.className = 'cms-input';
    campLim.inputMode = 'numeric';
    campLim.value = p.campaign_qty_limit != null ? String(p.campaign_qty_limit) : '';
    bindValue(campLim, index, 'campaign_qty_limit');

    const prio = document.createElement('input');
    prio.className = 'cms-input';
    prio.type = 'number';
    prio.value = p.display_priority != null ? String(p.display_priority) : '0';
    bindValue(prio, index, 'display_priority');

    card.appendChild(head);
    card.appendChild(regular);
    card.appendChild(makeField('قیمت ویژه (تومان)', promo));
    card.appendChild(disc);
    const limits = document.createElement('div');
    limits.className = 'cms-flash-deal-limits';
    limits.appendChild(makeField('سقف در هر سفارش', orderLim));
    limits.appendChild(makeField('سقف هر مشتری', custLim));
    limits.appendChild(makeField('سقف کمپین', campLim));
    limits.appendChild(makeField('اولویت نمایش', prio));
    card.appendChild(limits);
    list.appendChild(card);
  });
  writeJson();
  renderPreview();
}

function addProduct(p) {
  if (rows.some(row => Number(row.product_id) === Number(p.product_id))) return;
  const regular = Number(p.regular_unit_price || 0);
  rows.push({
    product_id: p.product_id,
    name: p.name,
    visual_id: p.visual_id,
    image: p.image,
    regular_unit_price: regular > 0 ? regular : p.regular_unit_price,
    regular_price_text: p.regular_price_text,
    promo_unit_price: regular > 0 ? regular : '',
    per_order_qty_limit: '',
    per_customer_qty_limit: '',
    campaign_qty_limit: '',
    display_priority: 0,
  });
  renderProducts();
  const lastPromo = list.querySelector('.cms-festival-tier:last-child input.cms-input');
  if (lastPromo) lastPromo.focus();
}

async function runSearch() {
  const q = searchInput.value.trim();
  if (!q) {
    resultsBox.textContent = 'کد نمایش کالا را وارد کنید';
    return;
  }
  resultsBox.textContent = 'در حال جستجو...';
  try {
    const res = await fetch('flash-deals.php?product_q=' + encodeURIComponent(q), { credentials: 'same-origin' });
    const data = await res.json();
    const products = data && data.products ? data.products : [];
    resultsBox.replaceChildren();
    if (!products.length) {
      resultsBox.textContent = 'محصولی با این کد نمایش پیدا نشد';
      return;
    }
    products.forEach((p) => {
      const row = document.createElement('div');
      row.className = 'cms-flash-deal-results__row';
      const label = document.createElement('span');
      label.dir = 'ltr';
      label.textContent = (p.visual_id ? String(p.visual_id) + ' — ' : '') + (p.name || '');
      const add = document.createElement('button');
      add.type = 'button';
      add.className = 'cms-btn cms-btn--secondary';
      const already = rows.some(r => Number(r.product_id) === Number(p.product_id));
      add.textContent = already ? 'اضافه شده' : 'افزودن';
      add.disabled = already;
      add.addEventListener('click', () => {
        addProduct(p);
        add.textContent = 'اضافه شده';
        add.disabled = true;
      });
      row.appendChild(label);
      row.appendChild(add);
      resultsBox.appendChild(row);
    });
  } catch (e) {
    resultsBox.textContent = 'جستجو ناموفق بود';
  }
}

searchBtn.addEventListener('click', () => { void runSearch(); });
searchInput.addEventListener('keydown', (event) => {
  if (event.key === 'Enter') {
    event.preventDefault();
    void runSearch();
  }
});
document.getElementById('flash-deal-form').addEventListener('submit', () => writeJson());
renderProducts();
JS;
    echo '</script>';
    echo '<script src="assets/cms-jalali-datepicker.js?v=1"></script>';
    cms_layout_end();
    exit;
}

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
try {
    $list = flash_deals_list($pdo, $filters, $page);
} catch (Throwable $e) {
    $list = ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
    echo '<p class="cms-flash cms-flash--error">' . cms_h($e->getMessage()) . '</p>';
}
echo '<div class="cms-btn-row" style="justify-content:space-between;margin-bottom:1rem">';
echo '<h1 style="margin:0">پیشنهادهای ویژه</h1>';
echo '<a class="cms-btn" href="flash-deals.php?edit=0">ایجاد پیشنهاد ویژه</a>';
echo '</div>';
echo '<form method="get" class="cms-btn-row" style="margin-bottom:1rem;flex-wrap:wrap">';
echo '<input class="cms-input" name="q" placeholder="جستجوی عنوان" value="' . cms_h($filters['q']) . '">';
echo '<select class="cms-select" name="status">';
foreach (['' => 'فعال مدیریتی', 'all' => 'همه', 'draft' => 'پیش‌نویس', 'scheduled' => 'زمان‌بندی', 'active' => 'فعال', 'paused' => 'متوقف', 'ended' => 'پایان', 'archived' => 'بایگانی'] as $key => $label) {
    $sel = $filters['status'] === (string) $key ? ' selected' : '';
    echo '<option value="' . cms_h((string) $key) . '"' . $sel . '>' . cms_h($label) . '</option>';
}
echo '</select>';
echo '<input class="cms-input" name="date_from" placeholder="از تاریخ" value="' . cms_h($filters['date_from']) . '">';
echo '<input class="cms-input" name="date_to" placeholder="تا تاریخ" value="' . cms_h($filters['date_to']) . '">';
echo '<button class="cms-btn" type="submit">اعمال فیلتر</button>';
echo '</form>';
if ($list['items'] === []) {
    echo '<p class="cms-muted">پیشنهادی با این فیلتر نیست.</p>';
} else {
    echo '<div style="overflow:auto"><table class="cms-table"><thead><tr>';
    echo '<th>عنوان</th><th>بازه</th><th>وضعیت</th><th>محصول</th><th>آخرین تغییر</th><th>عملیات</th>';
    echo '</tr></thead><tbody>';
    foreach ($list['items'] as $row) {
        $rowId = (int) $row['id'];
        $rowVersion = (int) $row['version'];
        $rowStatus = (string) $row['status'];
        $description = trim((string) ($row['description'] ?? ''));
        echo '<tr>';
        echo '<td><strong>' . cms_h((string) $row['title']) . '</strong>';
        if ($description !== '') {
            echo '<div class="cms-muted">' . cms_h(mb_substr($description, 0, 120)) . '</div>';
        }
        echo '</td>';
        echo '<td>' . cms_h((string) $row['start_date'] . ' ' . $row['start_time']) . ' تا ' . cms_h((string) $row['end_date'] . ' ' . $row['end_time']) . '</td>';
        echo '<td>' . cms_h((string) $row['status_label']) . '</td>';
        echo '<td>' . cms_h((string) $row['product_count']) . '</td>';
        echo '<td>' . cms_h((string) $row['updated_at_jalali']) . '</td>';
        echo '<td><div class="cms-btn-row" style="gap:.35rem">';
        echo '<a class="cms-btn cms-btn--ghost" href="flash-deals.php?edit=' . $rowId . '">مشاهده / ویرایش</a>';
        $actionBtn = static function (string $name, string $label, string $confirm) use ($rowId, $rowVersion): void {
            echo '<form method="post" style="display:inline" onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ')">';
            echo '<input type="hidden" name="id" value="' . $rowId . '">';
            echo '<input type="hidden" name="version" value="' . $rowVersion . '">';
            echo '<button class="cms-btn cms-btn--ghost" name="action" value="' . cms_h($name) . '">' . cms_h($label) . '</button>';
            echo '</form>';
        };
        if (in_array($rowStatus, ['active', 'scheduled'], true)) {
            $actionBtn('pause', 'توقف', 'خرید جدید با این پیشنهاد متوقف شود؟');
        }
        if ($rowStatus === 'paused') {
            $actionBtn('resume', 'ادامه', 'پیشنهاد دوباره برای خرید فعال شود؟');
        }
        if (!in_array($rowStatus, ['ended', 'archived'], true)) {
            $actionBtn('end', 'پایان', 'پیشنهاد همین حالا پایان یابد؟');
        }
        if ($rowStatus !== 'archived') {
            $actionBtn('archive', 'بایگانی', 'پیشنهاد از فهرست فعال پنهان شود؟ تاریخچه سفارش حفظ می‌شود.');
        }
        $actionBtn('duplicate', 'کپی', 'یک پیش‌نویس جدید با شناسه تازه ساخته شود؟');
        $actionBtn('delete', 'حذف', 'حذف فقط وقتی ممکن است که سفارشی به این پیشنهاد وصل نباشد. ادامه می‌دهید؟');
        echo '</div></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    if ((int) $list['total_pages'] > 1) {
        echo '<p class="cms-btn-row">';
        for ($i = 1; $i <= (int) $list['total_pages']; $i++) {
            $qs = $_GET;
            $qs['page'] = (string) $i;
            $cls = $i === (int) $list['page'] ? 'cms-btn' : 'cms-btn cms-btn--ghost';
            echo '<a class="' . $cls . '" href="flash-deals.php?' . cms_h(http_build_query($qs)) . '">' . $i . '</a>';
        }
        echo '</p>';
    }
}
cms_layout_end();
