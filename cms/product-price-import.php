<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/price-import.php';

cms_require_login();
cms_session_start();
$pdo = cms_pdo();
cms_ensure_product_car_models_schema($pdo);
cms_ensure_product_categories_schema($pdo);
price_import_ensure_schema($pdo);

const PRICE_IMPORT_SESSION_KEY = 'price_import_preview';

function price_import_merge_form_row(array $row, array $input): array
{
    $merged = $row;
    $merged['include'] = !empty($input['include']);
    $merged['name'] = trim((string) ($input['name'] ?? $row['name'] ?? ''));
    $merged['price_text'] = trim((string) ($input['price_text'] ?? $row['price_text'] ?? ''));
    $packRaw = trim((string) ($input['pack_size'] ?? ''));
    $merged['pack_size'] = $packRaw === '' ? ($row['pack_size'] ?? null) : max(0, (int) $packRaw);
    if ((int) ($merged['pack_size'] ?? 0) === 0) {
        $merged['pack_size'] = null;
    }
    if (($row['action'] ?? '') === 'create') {
        $merged['category_id'] = (int) ($input['category_id'] ?? $row['category_id'] ?? 0);
    }

    $carOverrides = [];
    if (isset($input['car_pick']) && is_array($input['car_pick'])) {
        foreach ($input['car_pick'] as $norm => $carId) {
            $carId = (int) $carId;
            if ($carId > 0) {
                $carOverrides[(string) $norm] = $carId;
            }
        }
    }
    $merged['car_overrides'] = $carOverrides;

    $issues = price_import_row_issues(
        $merged,
        is_array($merged['car_matches'] ?? null) ? $merged['car_matches'] : [],
        $carOverrides
    );
    $merged['ready'] = $issues['ready'];
    $merged['issues'] = $issues['issues'];

    return $merged;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'clear') {
            unset($_SESSION[PRICE_IMPORT_SESSION_KEY]);
            cms_flash('پیش‌نمایش پاک شد');
            cms_redirect('product-price-import.php');
        }

        if ($action === 'upload') {
            if (!isset($_FILES['price_file']) || !is_array($_FILES['price_file'])) {
                throw new RuntimeException('فایل انتخاب نشده است');
            }
            if ((int) ($_FILES['price_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('خطا در آپلود فایل');
            }

            $tmp = (string) ($_FILES['price_file']['tmp_name'] ?? '');
            $originalName = (string) ($_FILES['price_file']['name'] ?? 'import.xlsx');
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'csv'], true)) {
                throw new RuntimeException('فقط .xlsx یا .csv پشتیبانی می‌شود');
            }

            $stored = price_import_temp_dir() . DIRECTORY_SEPARATOR . 'import-' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($tmp, $stored)) {
                throw new RuntimeException('ذخیره فایل موقت ناموفق بود');
            }

            $parsed = price_import_parse_file($stored, $ext);
            if ($parsed === []) {
                @unlink($stored);
                throw new RuntimeException('هیچ ردیف محصولی در فایل یافت نشد');
            }

            $preview = price_import_build_preview($pdo, $parsed);
            $_SESSION[PRICE_IMPORT_SESSION_KEY] = [
                'source_name' => $originalName,
                'stored_path' => $stored,
                'parsed' => $parsed,
                'preview' => $preview,
            ];
            cms_flash(count($parsed) . ' ردیف خوانده شد — موارد نیازمند بررسی را تکمیل کنید');
            cms_redirect('product-price-import.php');
        }

        if ($action === 'apply') {
            $session = $_SESSION[PRICE_IMPORT_SESSION_KEY] ?? null;
            if (!is_array($session) || empty($session['preview']['rows'])) {
                throw new RuntimeException('ابتدا فایل را آپلود کنید');
            }

            $rows = $session['preview']['rows'];
            $formRows = [];
            $postedRows = $_POST['rows'] ?? [];
            if (!is_array($postedRows)) {
                throw new RuntimeException('داده فرم نامعتبر است');
            }

            foreach ($rows as $row) {
                $index = (int) ($row['index'] ?? -1);
                $input = is_array($postedRows[$index] ?? null) ? $postedRows[$index] : [];
                $formRows[$index] = price_import_merge_form_row($row, $input);
            }

            $saveAliases = !empty($_POST['save_aliases']);
            $result = price_import_apply_batch($pdo, $rows, $formRows, $saveAliases);

            unset($_SESSION[PRICE_IMPORT_SESSION_KEY]);
            if (!empty($session['stored_path']) && is_file($session['stored_path'])) {
                @unlink($session['stored_path']);
            }

            cms_flash(sprintf(
                'انجام شد: %d ایجاد، %d به‌روزرسانی، %d رد شد%s',
                $result['created'],
                $result['updated'],
                $result['skipped'],
                !empty($result['errors']) ? ' — ' . count($result['errors']) . ' خطا' : ''
            ), !empty($result['errors']) && $result['created'] + $result['updated'] === 0 ? 'error' : 'ok');
            cms_redirect('product-price-import.php');
        }

        throw new RuntimeException('عملیات نامعتبر');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect('product-price-import.php');
    }
}

$session = $_SESSION[PRICE_IMPORT_SESSION_KEY] ?? null;
$preview = is_array($session) ? ($session['preview'] ?? null) : null;
$rows = is_array($preview) ? ($preview['rows'] ?? []) : [];
$categories = is_array($preview) ? ($preview['categories'] ?? price_import_load_categories($pdo)) : price_import_load_categories($pdo);
$carModels = is_array($preview) ? ($preview['car_models'] ?? price_import_load_car_models($pdo)) : price_import_load_car_models($pdo);
$sourceName = is_array($session) ? (string) ($session['source_name'] ?? '') : '';

$readyCount = 0;
$reviewCount = 0;
foreach ($rows as $row) {
    if (!empty($row['ready'])) {
        $readyCount++;
    } else {
        $reviewCount++;
    }
}

cms_layout_start('ورود قیمت', cms_current_username(), 'shop');
?>
<div class="price-import-page">
<h1 style="margin-top:0">ورود قیمت از Excel</h1>
<p class="cms-muted">
  فایل لیست قیمت (.xlsx یا .csv) را آپلود کنید. روی cPanel نیازی به ریستارت Apache نیست —
  اگر xlsx کار نکرد، در Excel «Save As → CSV UTF-8» بزنید.
</p>
<p class="cms-muted"><?= cms_h(price_import_xlsx_support_hint()) ?></p>

<div class="cms-card price-import-upload">
  <h2>۱. آپلود فایل</h2>
  <form method="post" enctype="multipart/form-data" class="cms-form">
    <input type="hidden" name="action" value="upload">
    <label class="cms-label">فایل .xlsx یا .csv</label>
    <input class="cms-input" type="file" name="price_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
    <div class="cms-form__actions">
      <button class="cms-btn cms-btn--primary" type="submit">خواندن و پیش‌نمایش</button>
      <?php if ($rows !== []): ?>
        <button class="cms-btn" type="submit" formaction="product-price-import.php" formmethod="post" name="action" value="clear">پاک کردن پیش‌نمایش</button>
      <?php endif; ?>
    </div>
  </form>
  <?php if ($sourceName !== ''): ?>
    <p class="cms-muted">فایل فعلی: <strong><?= cms_h($sourceName) ?></strong>
      — <?= count($rows) ?> ردیف —
      <span class="price-import-badge price-import-badge--ok"><?= $readyCount ?> آماده</span>
      <?php if ($reviewCount > 0): ?>
        <span class="price-import-badge price-import-badge--warn"><?= $reviewCount ?> نیاز به بررسی</span>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>

<?php if ($rows !== []): ?>
  <form method="post" class="cms-card price-import-preview">
    <input type="hidden" name="action" value="apply">
    <h2>۲. بررسی و تأیید</h2>

    <div class="price-import-summary">
      <span><strong><?= count($rows) ?></strong> ردیف</span>
      <span class="price-import-badge price-import-badge--ok"><?= $readyCount ?> آماده</span>
      <?php if ($reviewCount > 0): ?>
        <span class="price-import-badge price-import-badge--warn"><?= $reviewCount ?> نیاز به بررسی</span>
      <?php endif; ?>
      <span class="cms-muted">فیلدهای قرمز را تکمیل کنید</span>
    </div>

    <div class="price-import-toolbar">
      <label class="cms-check">
        <input type="checkbox" name="save_aliases" value="1" checked>
        ذخیره انتخاب‌های خودرو برای ورود بعدی
      </label>
      <label class="cms-check">
        <input type="checkbox" id="price-import-show-review" checked>
        فقط ردیف‌های نیازمند بررسی
      </label>
    </div>

    <div class="price-import-rows">
      <?php foreach ($rows as $row):
        $index = (int) ($row['index'] ?? 0);
        $ready = !empty($row['ready']);
        $action = (string) ($row['action'] ?? 'create');
        $issues = is_array($row['issues'] ?? null) ? $row['issues'] : [];
        $needsCategory = $action === 'create' && (int) ($row['category_id'] ?? 0) <= 0;
        $needsPrice = trim((string) ($row['price_text'] ?? '')) === '';
        $needsPack = $action === 'create' && (int) ($row['pack_size'] ?? 0) <= 0;
        $needsName = $action === 'create' && trim((string) ($row['name'] ?? '')) === '';
        ?>
        <article class="price-import-row-card <?= $ready ? 'is-ready' : 'needs-review' ?>" data-ready="<?= $ready ? '1' : '0' ?>">
          <header class="price-import-row-card__head">
            <label class="cms-check" title="اعمال این ردیف">
              <input type="checkbox" name="rows[<?= $index ?>][include]" value="1" <?= !isset($row['include']) || !empty($row['include']) ? 'checked' : '' ?>>
            </label>
            <div class="price-import-row-card__title">
              <strong><?= cms_h((string) ($row['name'] ?? '')) ?></strong>
              <div class="price-import-row-card__meta">
                <span dir="ltr">کد <?= cms_h((string) ($row['visual_id'] ?? '')) ?></span>
                <span><?= $action === 'update' ? 'به‌روزرسانی قیمت' : 'محصول جدید' ?></span>
                <?php if (!empty($row['section_hint'])): ?>
                  <span>بخش: <?= cms_h((string) $row['section_hint']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($ready): ?>
              <span class="price-import-badge price-import-badge--ok">آماده</span>
            <?php else: ?>
              <span class="price-import-badge price-import-badge--warn">نیاز به بررسی</span>
            <?php endif; ?>
          </header>

          <?php if (!$ready && $issues !== []): ?>
            <div class="price-import-issues-box">
              <strong>کارهای لازم:</strong>
              <ul class="price-import-issues">
                <?php foreach ($issues as $issue): ?>
                  <li><?= cms_h($issue) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="price-import-row-card__body">
            <div class="price-import-field<?= $needsName ? ' needs-attention' : '' ?>">
              <span class="price-import-field__label">نام محصول</span>
              <?php if ($action === 'create'): ?>
                <input class="cms-input" name="rows[<?= $index ?>][name]" value="<?= cms_h((string) ($row['name'] ?? '')) ?>">
              <?php else: ?>
                <div><?= cms_h((string) ($row['existing_name'] ?? $row['name'] ?? '')) ?></div>
                <input type="hidden" name="rows[<?= $index ?>][name]" value="<?= cms_h((string) ($row['name'] ?? '')) ?>">
              <?php endif; ?>
            </div>

            <div class="price-import-field<?= $needsPrice ? ' needs-attention' : '' ?>">
              <span class="price-import-field__label">قیمت (تومان)</span>
              <input class="cms-input" name="rows[<?= $index ?>][price_text]" value="<?= cms_h((string) ($row['price_text'] ?? '')) ?>">
              <?php if ($action === 'update' && !empty($row['existing_price_text'])): ?>
                <span class="price-import-field__hint">قبلی: <?= cms_h((string) $row['existing_price_text']) ?></span>
              <?php endif; ?>
            </div>

            <div class="price-import-field<?= $needsPack ? ' needs-attention' : '' ?>">
              <span class="price-import-field__label">تعداد در کارتن</span>
              <input class="cms-input" type="number" min="0" name="rows[<?= $index ?>][pack_size]" value="<?= cms_h((string) ($row['pack_size'] ?? '')) ?>">
              <?php if ($action === 'update' && !empty($row['existing_pack_size'])): ?>
                <span class="price-import-field__hint">قبلی: <?= cms_h((string) $row['existing_pack_size']) ?></span>
              <?php endif; ?>
            </div>

            <?php if ($action === 'create'): ?>
              <div class="price-import-field<?= $needsCategory ? ' needs-attention' : '' ?>">
                <span class="price-import-field__label">دسته محصول</span>
                <select class="cms-input" name="rows[<?= $index ?>][category_id]" required>
                  <option value="">— انتخاب دسته —</option>
                  <?php foreach ($categories as $cat):
                    $catId = (int) $cat['id'];
                    $selected = (int) ($row['category_id'] ?? 0) === $catId ? 'selected' : '';
                    ?>
                    <option value="<?= $catId ?>" <?= $selected ?>><?= cms_h((string) $cat['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <div class="price-import-field price-import-field--wide">
              <span class="price-import-field__label">خودروها (از فایل)</span>
              <div class="price-import-field__hint"><?= cms_h((string) ($row['cars_raw'] ?? '')) ?></div>
              <?php if ($action === 'update'): ?>
                <div class="price-import-field__hint">برای محصول موجود، خودرو اختیاری است — فقط قیمت به‌روز می‌شود.</div>
              <?php endif; ?>
              <div class="price-import-car-list">
                <?php foreach (($row['car_matches'] ?? []) as $match):
                  $token = (string) ($match['token'] ?? '');
                  $norm = search_normalize($token);
                  $confidence = (string) ($match['confidence'] ?? 'unmatched');
                  if ($confidence === 'certain') {
                      $badgeClass = 'price-import-badge--ok';
                  } elseif ($confidence === 'likely') {
                      $badgeClass = 'price-import-badge--likely';
                  } else {
                      $badgeClass = 'price-import-badge--warn';
                  }
                  $needsCarPick = ($confidence === 'uncertain' || $confidence === 'unmatched');
                  $confidenceLabel = [
                      'certain' => 'قطعی',
                      'likely' => 'احتمالی',
                      'uncertain' => 'مبهم',
                      'unmatched' => 'نامشخص',
                  ][$confidence] ?? $confidence;
                  ?>
                  <div class="price-import-car-item<?= $needsCarPick ? ' needs-attention' : '' ?>">
                    <span class="price-import-badge <?= $badgeClass ?>"><?= cms_h($confidenceLabel) ?></span>
                    <span><?= cms_h($token) ?></span>
                    <?php if ($confidence === 'certain' || $confidence === 'likely'): ?>
                      <span class="cms-muted">→ <?= cms_h((string) ($match['car_model_name'] ?? '')) ?></span>
                    <?php elseif ($needsCarPick): ?>
                      <select class="cms-input" name="rows[<?= $index ?>][car_pick][<?= cms_h($norm) ?>]">
                        <option value="">— انتخاب خودرو —</option>
                        <?php
                        $options = $match['candidates'] ?? $carModels;
                      foreach ($options as $opt):
                          $optId = (int) ($opt['id'] ?? 0);
                          ?>
                          <option value="<?= $optId ?>"><?= cms_h((string) ($opt['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="cms-form__actions">
      <button class="cms-btn cms-btn--primary" type="submit" <?= $reviewCount > 0 ? 'onclick="return confirm(\'برخی ردیف‌ها هنوز نیاز به بررسی دارند. فقط ردیف‌های آماده اعمال می‌شوند. ادامه؟\')"' : '' ?>>
        اعمال تغییرات
      </button>
    </div>
  </form>
  <script>
  (function () {
    var toggle = document.getElementById('price-import-show-review');
    if (!toggle) return;
    var cards = document.querySelectorAll('.price-import-row-card');
    function applyFilter() {
      cards.forEach(function (card) {
        var needsReview = card.getAttribute('data-ready') !== '1';
        card.style.display = toggle.checked && !needsReview ? 'none' : '';
      });
    }
    toggle.addEventListener('change', applyFilter);
    applyFilter();
  })();
  </script>
<?php endif; ?>
</div>

<?php cms_layout_end(); ?>
