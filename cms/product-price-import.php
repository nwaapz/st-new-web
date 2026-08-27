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

    $carCategoryPick = [];
    if (isset($input['car_category']) && is_array($input['car_category'])) {
        foreach ($input['car_category'] as $norm => $categoryId) {
            $categoryId = (int) $categoryId;
            if ($categoryId > 0) {
                $carCategoryPick[(string) $norm] = $categoryId;
            }
        }
    }

    $extraCars = price_import_parse_extra_cars($input);
    $merged['extra_cars'] = $extraCars;
    $merged['car_category_map'] = price_import_build_car_category_map(
        is_array($merged['car_matches'] ?? null) ? $merged['car_matches'] : [],
        $carOverrides,
        $carCategoryPick,
        $extraCars
    );

    $issues = price_import_row_issues(
        $merged,
        is_array($merged['car_matches'] ?? null) ? $merged['car_matches'] : [],
        $carOverrides,
        $extraCars
    );
    $merged['ready'] = $issues['ready'];
    $merged['issues'] = $issues['issues'];

    return $merged;
}

function price_import_page_handle_apply(PDO $pdo, array $session, array $postedRows, bool $saveAliases, ?int $onlyIndex): array
{
    $session = price_import_session_merge_posted_rows($session, $postedRows, 'price_import_merge_form_row');

    $rows = is_array($session['preview']['rows'] ?? null) ? $session['preview']['rows'] : [];
    $formRows = [];
    foreach ($rows as $row) {
        $index = (int) ($row['index'] ?? -1);
        if ($onlyIndex !== null) {
            $row['include'] = $index === $onlyIndex;
        }
        $formRows[$index] = $row;
    }

    $onlyIndices = $onlyIndex !== null ? [$onlyIndex] : null;
    $result = price_import_apply_batch($pdo, $rows, $formRows, $saveAliases, $onlyIndices);

    if (!empty($result['applied_indices'])) {
        $session = price_import_session_remove_rows($session, $result['applied_indices']);
    }

    $session['preview']['rows'] = price_import_refresh_session_rows(
        $pdo,
        is_array($session['preview']['rows'] ?? null) ? $session['preview']['rows'] : []
    );

    return [
        'session' => $session,
        'result' => $result,
    ];
}

function price_import_page_store_session(array $session): int
{
    $remaining = count($session['preview']['rows'] ?? []);
    if ($remaining === 0) {
        if (!empty($session['stored_path']) && is_file($session['stored_path'])) {
            @unlink($session['stored_path']);
        }
        unset($_SESSION[PRICE_IMPORT_SESSION_KEY]);

        return 0;
    }

    $_SESSION[PRICE_IMPORT_SESSION_KEY] = $session;

    return $remaining;
}

function price_import_render_car_picker(string $fieldName, array $carModels, ?int $selectedId = null): void
{
    ?>
    <div class="cms-check-list-filter price-import-car-picker" data-cms-check-list-filter>
      <input
        type="search"
        class="cms-input cms-check-list-filter__input"
        placeholder="جستجو کارخانه یا مدل…"
        autocomplete="off"
        aria-label="جستجو مدل خودرو"
      >
      <p class="cms-check-list-filter__empty cms-muted" hidden>موردی یافت نشد</p>
      <div class="cms-check-list">
        <?php foreach ($carModels as $opt):
            $optId = (int) ($opt['id'] ?? 0);
            if ($optId <= 0) {
                continue;
            }
            $searchHaystack = trim((string) (($opt['factory_name'] ?? '') . ' ' . ($opt['name'] ?? '')));
            $checked = $selectedId !== null && $selectedId === $optId ? 'checked' : '';
            ?>
          <label
            class="cms-check cms-check-list__item"
            data-cms-check-search="<?= cms_h($searchHaystack) ?>"
          >
            <input type="radio" name="<?= cms_h($fieldName) ?>" value="<?= $optId ?>" <?= $checked ?>>
            <span><?= cms_h(price_import_car_model_label($opt)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $applyOneIndex = isset($_POST['apply_row']) && $_POST['apply_row'] !== ''
        ? (int) $_POST['apply_row']
        : null;

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

        if ($action === 'apply' || $applyOneIndex !== null) {
            $session = $_SESSION[PRICE_IMPORT_SESSION_KEY] ?? null;
            if (!is_array($session) || empty($session['preview']['rows'])) {
                throw new RuntimeException('ابتدا فایل را آپلود کنید');
            }

            $postedRows = $_POST['rows'] ?? [];
            if (!is_array($postedRows)) {
                throw new RuntimeException('داده فرم نامعتبر است');
            }

            $saveAliases = !empty($_POST['save_aliases']);
            $handled = price_import_page_handle_apply($pdo, $session, $postedRows, $saveAliases, $applyOneIndex);
            $result = $handled['result'];
            $remaining = price_import_page_store_session($handled['session']);
            $appliedCount = count($result['applied_indices']);

            if ($applyOneIndex !== null && $appliedCount === 0) {
                $message = !empty($result['errors'])
                    ? (string) $result['errors'][0]
                    : 'ذخیره این ردیف ناموفق بود — موارد لازم را تکمیل کنید';
                cms_flash($message, 'error');
                cms_redirect('product-price-import.php');
            }

            if ($remaining === 0) {
                cms_flash(sprintf(
                    'همه ردیف‌ها ذخیره شد — %d ایجاد، %d به‌روزرسانی%s',
                    $result['created'],
                    $result['updated'],
                    !empty($result['errors']) ? ' — ' . count($result['errors']) . ' خطا' : ''
                ), !empty($result['errors']) ? 'error' : 'ok');
            } elseif ($applyOneIndex !== null) {
                cms_flash(sprintf(
                    '%d ردیف ذخیره شد — %d ردیف باقی مانده%s',
                    $appliedCount,
                    $remaining,
                    !empty($result['errors']) ? ' — ' . implode('؛ ', $result['errors']) : ''
                ), $appliedCount > 0 ? 'ok' : 'error');
            } else {
                cms_flash(sprintf(
                    'انجام شد: %d ایجاد، %d به‌روزرسانی، %d رد شد — %d ردیف باقی مانده%s',
                    $result['created'],
                    $result['updated'],
                    $result['skipped'],
                    $remaining,
                    !empty($result['errors']) ? ' — ' . count($result['errors']) . ' خطا' : ''
                ), !empty($result['errors']) && $appliedCount === 0 ? 'error' : 'ok');
            }
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
if ($rows !== []) {
    $rows = price_import_refresh_session_rows($pdo, $rows);
    if (is_array($session)) {
        $_SESSION[PRICE_IMPORT_SESSION_KEY]['preview']['rows'] = $rows;
    }
}
$categories = price_import_load_categories($pdo);
$carModels = price_import_load_car_models($pdo);
$sourceName = is_array($session) ? (string) ($session['source_name'] ?? '') : '';

$readyCount = 0;
$carSetupCount = 0;
foreach ($rows as $row) {
    if (!empty($row['ready'])) {
        $readyCount++;
    }
    if (!empty($row['needs_car_setup'])) {
        $carSetupCount++;
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
      <?php if ($carSetupCount > 0): ?>
        <span class="price-import-badge price-import-badge--warn"><?= $carSetupCount ?> نیاز به تعریف خودرو</span>
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
      <?php if ($carSetupCount > 0): ?>
        <span class="price-import-badge price-import-badge--warn"><?= $carSetupCount ?> نیاز به تعریف خودرو</span>
      <?php endif; ?>
      <span class="cms-muted">فیلدهای قرمز را تکمیل کنید</span>
    </div>

    <div class="price-import-toolbar">
      <label class="cms-check">
        <input type="checkbox" name="save_aliases" value="1" checked>
        ذخیره انتخاب‌های خودرو برای ورود بعدی
      </label>
      <label class="cms-check">
        <input type="checkbox" id="price-import-show-car-setup" checked>
        فقط ردیف‌های نیازمند تعریف خودرو
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
        $skipCars = !empty($row['skip_cars']);
        $needsCarSetup = !empty($row['needs_car_setup']);
        ?>
        <article class="price-import-row-card <?= $ready ? 'is-ready' : 'needs-review' ?>" data-ready="<?= $ready ? '1' : '0' ?>" data-needs-car-setup="<?= $needsCarSetup ? '1' : '0' ?>" data-row-index="<?= $index ?>" data-action="<?= cms_h($action) ?>" data-skip-cars="<?= $skipCars ? '1' : '0' ?>">
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
              <span class="price-import-badge price-import-badge--ok price-import-row-ready-badge">آماده</span>
            <?php else: ?>
              <span class="price-import-badge price-import-badge--warn price-import-row-ready-badge">نیاز به بررسی</span>
            <?php endif; ?>
            <?php if ($skipCars): ?>
              <span class="price-import-badge price-import-badge--car-done">خودرو ثبت شده</span>
            <?php endif; ?>
            <button
              type="submit"
              name="apply_row"
              value="<?= $index ?>"
              class="cms-btn price-import-save-row"
              <?= $ready ? '' : 'disabled' ?>
            >ذخیره این ردیف</button>
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
              <?php if ($skipCars): ?>
                <span class="price-import-field__label">خودروها</span>
                <p class="price-import-field__hint price-import-skip-cars-note">خودروها از قبل ثبت شده — فقط قیمت به‌روز می‌شود.</p>
                <?php if (!empty($row['existing_car_names'])): ?>
                  <p class="price-import-existing-cars"><?= cms_h((string) $row['existing_car_names']) ?></p>
                <?php endif; ?>
              <?php else: ?>
              <span class="price-import-field__label">خودروها (از فایل)</span>
              <div class="price-import-field__hint"><?= cms_h((string) ($row['cars_raw'] ?? '')) ?: '—' ?></div>
              <?php if ($action === 'update'): ?>
                <div class="price-import-field__hint">برای محصول موجود بدون خودرو، می‌توانید خودرو اضافه کنید — در غیر این صورت فقط قیمت به‌روز می‌شود.</div>
              <?php endif; ?>
              <?php if ($carModels === []): ?>
                <p class="cms-muted" style="margin:0">هنوز مدلی ثبت نشده. ابتدا از <a href="car-models.php">مدل‌ها</a> اضافه کنید.</p>
              <?php else: ?>
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
                  <div class="price-import-car-item<?= $needsCarPick ? ' needs-attention' : '' ?>"<?= $needsCarPick ? ' data-requires-car-pick="1"' : '' ?>>
                    <div class="price-import-car-item__head">
                      <span class="price-import-badge <?= $badgeClass ?>"><?= cms_h($confidenceLabel) ?></span>
                      <span class="price-import-car-item__token"><?= cms_h($token) ?></span>
                    </div>
                    <div class="price-import-car-item__controls">
                      <?php
                      $pickFieldName = 'rows[' . $index . '][car_pick][' . $norm . ']';
                      $selectedCarId = null;
                      if ($confidence === 'certain' || $confidence === 'likely') {
                          $selectedCarId = (int) ($match['car_model_id'] ?? 0);
                          if ($selectedCarId <= 0) {
                              $selectedCarId = null;
                          }
                      }
                      price_import_render_car_picker($pickFieldName, $carModels, $selectedCarId);
                      ?>
                      <select
                        class="cms-input price-import-category-select"
                        name="rows[<?= $index ?>][car_category][<?= cms_h($norm) ?>]"
                        aria-label="دسته این خودرو (اختیاری)"
                      >
                        <option value="">پیش‌فرض (دسته محصول)</option>
                        <?php foreach ($categories as $cat):
                            $catId = (int) $cat['id'];
                            ?>
                          <option value="<?= $catId ?>"><?= cms_h((string) $cat['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="cms-btn price-import-add-car">افزودن خودرو</button>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="cms-form__actions">
      <button class="cms-btn cms-btn--primary" type="submit" name="action" value="apply" <?= $reviewCount > 0 ? 'onclick="return confirm(\'فقط ردیف‌های آماده و تیک‌خورده اعمال می‌شوند. ادامه؟\')"' : '' ?>>
        اعمال ردیف‌های آماده
      </button>
    </div>
  </form>

  <?php if ($carModels !== []): ?>
  <template id="price-import-extra-car-template">
    <div class="price-import-car-item price-import-car-item--extra needs-attention">
      <div class="price-import-car-item__head">
        <span class="price-import-badge price-import-badge--likely">دستی</span>
        <span class="price-import-car-item__token">خودرو اضافه‌شده</span>
        <button type="button" class="cms-btn price-import-remove-car" aria-label="حذف خودرو">حذف</button>
      </div>
      <div class="price-import-car-item__controls">
        <?php price_import_render_car_picker('rows[__INDEX__][extra_cars][__EXTRA_IDX__][car_id]', $carModels); ?>
        <select class="cms-input price-import-category-select" data-name="rows[__INDEX__][extra_cars][__EXTRA_IDX__][category_id]" aria-label="دسته این خودرو (اختیاری)">
          <option value="">پیش‌فرض (دسته محصول)</option>
          <?php foreach ($categories as $cat):
              $catId = (int) $cat['id'];
              ?>
            <option value="<?= $catId ?>"><?= cms_h((string) $cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </template>
  <?php endif; ?>

  <script>
  (function () {
    function rowFieldValue(card, suffix) {
      var index = card.getAttribute('data-row-index') || '0';
      var el = card.querySelector('[name="rows[' + index + ']' + suffix + '"]');
      return el ? String(el.value || '').trim() : '';
    }

    function countConfirmedCars(card) {
      var count = 0;
      card.querySelectorAll('.price-import-car-item input[type="radio"]:checked').forEach(function () {
        count += 1;
      });
      return count;
    }

    function allRequiredCarsPicked(card) {
      var required = card.querySelectorAll(
        '.price-import-car-item[data-requires-car-pick="1"], .price-import-car-item--extra'
      );
      for (var i = 0; i < required.length; i += 1) {
        if (!required[i].querySelector('input[type="radio"]:checked')) {
          return false;
        }
      }
      return true;
    }

    function evaluateRowReadiness(card) {
      if (!card) {
        return false;
      }

      var action = card.getAttribute('data-action') || 'create';
      var skipCars = card.getAttribute('data-skip-cars') === '1';

      if (!rowFieldValue(card, '[price_text]')) {
        return false;
      }

      if (action === 'create') {
        if (!rowFieldValue(card, '[name]')) {
          return false;
        }
        if (!(parseInt(rowFieldValue(card, '[pack_size]'), 10) > 0)) {
          return false;
        }
        if (!(parseInt(rowFieldValue(card, '[category_id]'), 10) > 0)) {
          return false;
        }
        if (!skipCars) {
          if (!allRequiredCarsPicked(card) || countConfirmedCars(card) === 0) {
            return false;
          }
        }
      }

      return true;
    }

    function updateRowReadyUi(card) {
      var ready = evaluateRowReadiness(card);
      card.setAttribute('data-ready', ready ? '1' : '0');
      card.classList.toggle('is-ready', ready);
      card.classList.toggle('needs-review', !ready);

      var saveBtn = card.querySelector('.price-import-save-row');
      if (saveBtn) {
        saveBtn.disabled = !ready;
      }

      var badge = card.querySelector('.price-import-row-ready-badge');
      if (badge) {
        badge.textContent = ready ? 'آماده' : 'نیاز به بررسی';
        badge.classList.toggle('price-import-badge--ok', ready);
        badge.classList.toggle('price-import-badge--warn', !ready);
      }
    }

    function bindRowReadiness(card) {
      card.addEventListener('input', function () {
        updateRowReadyUi(card);
      });
      card.addEventListener('change', function () {
        updateRowReadyUi(card);
      });
      updateRowReadyUi(card);
    }

    document.querySelectorAll('.price-import-row-card').forEach(bindRowReadiness);

    var toggle = document.getElementById('price-import-show-car-setup');
    if (toggle) {
      function applyFilter() {
        document.querySelectorAll('.price-import-row-card').forEach(function (card) {
          var needsCarSetup = card.getAttribute('data-needs-car-setup') === '1';
          card.classList.toggle('is-filtered-out', toggle.checked && !needsCarSetup);
        });
      }
      toggle.addEventListener('change', applyFilter);
      applyFilter();
    }

    var extraTemplate = document.getElementById('price-import-extra-car-template');

    function replaceExtraCarFieldNames(root, rowIndex, extraIdx) {
      root.querySelectorAll('[data-name]').forEach(function (el) {
        el.name = el.getAttribute('data-name')
          .replace('__INDEX__', rowIndex)
          .replace('__EXTRA_IDX__', String(extraIdx));
        el.removeAttribute('data-name');
      });
      root.querySelectorAll('input[type="radio"][name*="__INDEX__"]').forEach(function (el) {
        el.name = el.name
          .replace('__INDEX__', rowIndex)
          .replace('__EXTRA_IDX__', String(extraIdx));
      });
    }

    function initCarPicker(root) {
      if (window.cmsInitCheckListFilter) {
        window.cmsInitCheckListFilter(root);
      }
    }

    if (!extraTemplate) {
      return;
    }

    function nextExtraIndex(list) {
      var maxIdx = -1;
      list.querySelectorAll('[name*="[extra_cars]"]').forEach(function (el) {
        var match = el.name.match(/\[extra_cars\]\[(\d+)\]/);
        if (match) {
          maxIdx = Math.max(maxIdx, parseInt(match[1], 10));
        }
      });
      return maxIdx + 1;
    }

    function bindRemove(btn) {
      btn.addEventListener('click', function () {
        var item = btn.closest('.price-import-car-item--extra');
        var card = btn.closest('.price-import-row-card');
        if (item) item.remove();
        if (card) updateRowReadyUi(card);
      });
    }

    document.querySelectorAll('.price-import-add-car').forEach(function (addBtn) {
      addBtn.addEventListener('click', function () {
        var card = addBtn.closest('.price-import-row-card');
        if (!card) return;
        var rowIndex = card.getAttribute('data-row-index') || '0';
        var list = card.querySelector('.price-import-car-list');
        if (!list) return;
        var extraIdx = nextExtraIndex(list);
        var clone = extraTemplate.content.cloneNode(true);
        replaceExtraCarFieldNames(clone, rowIndex, extraIdx);
        var removeBtn = clone.querySelector('.price-import-remove-car');
        if (removeBtn) bindRemove(removeBtn);
        list.appendChild(clone);
        var picker = list.querySelector('.price-import-car-item--extra:last-child [data-cms-check-list-filter]');
        if (picker) initCarPicker(picker);
        updateRowReadyUi(card);
      });
    });
  })();
  </script>
<?php endif; ?>
</div>

<?php cms_layout_end(); ?>
