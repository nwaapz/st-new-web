<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/jalali.php';
require_once __DIR__ . '/lib/price-sheet.php';

cms_require_login();
cms_session_start();
$pdo = cms_pdo();
cms_ensure_product_categories_schema($pdo);
price_sheet_ensure_schema($pdo);

$fromWarehouse = (string) ($_GET['from'] ?? '') === 'warehouse';
$pageUrl = price_sheet_page_url($fromWarehouse);
$layoutSection = $fromWarehouse ? 'customers' : 'shop';

$categories = price_sheet_list_categories($pdo);
$publishResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postCategoryId = (int) ($_POST['category_id'] ?? 0);
    $postFromWarehouse = !empty($_POST['from_warehouse']);

    try {
        if ($action === 'save_draft_all') {
            $postedFrames = is_array($_POST['frames'] ?? null) ? $_POST['frames'] : [];
            $result = price_sheet_save_frames($pdo, $postedFrames);
            cms_admin_audit($pdo, 'price_sheet.save', [
                'entity_type' => 'price_sheet',
                'summary' => cms_current_username() . ' — ذخیره پیش‌نویس لیست قیمت (' . $result['saved'] . ' ردیف)',
                'detail' => $result,
            ]);
            cms_flash(sprintf(
                'پیش‌نویس ذخیره شد — %s ردیف',
                cms_to_persian_digits((string) $result['saved'])
            ));
            cms_redirect(price_sheet_page_url($postFromWarehouse));
        }

        if ($action === 'upload') {
            if ($postCategoryId <= 0) {
                throw new RuntimeException('دسته انتخاب نشده است');
            }
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

            $stored = price_import_temp_dir() . DIRECTORY_SEPARATOR . 'sheet-' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($tmp, $stored)) {
                throw new RuntimeException('ذخیره فایل موقت ناموفق بود');
            }

            try {
                $importResult = price_sheet_import_file($pdo, $postCategoryId, $stored, $ext);
                cms_admin_audit($pdo, 'price_sheet.import', [
                    'entity_type' => 'category',
                    'entity_id' => $postCategoryId,
                    'summary' => cms_current_username() . ' — ورود Excel به لیست قیمت (' . $importResult['imported'] . ' ردیف)',
                    'detail' => array_merge($importResult, ['file' => $originalName]),
                ]);
                cms_flash(sprintf(
                    '%s ردیف وارد شد — %s ردیف نادیده گرفته شد',
                    cms_to_persian_digits((string) $importResult['imported']),
                    cms_to_persian_digits((string) $importResult['skipped'])
                ));
            } finally {
                if (is_file($stored)) {
                    @unlink($stored);
                }
            }
            cms_redirect(price_sheet_page_url($postFromWarehouse));
        }

        if ($action === 'import_google') {
            $sheetUrl = trim((string) ($_POST['google_sheet_url'] ?? ''));
            $importResult = price_sheet_import_from_google_sheet($pdo, $sheetUrl);
            cms_flash($importResult['message']);
            cms_redirect(price_sheet_page_url($postFromWarehouse));
        }

        if ($action === 'publish_all') {
            $publishResult = price_sheet_publish($pdo, null);
            cms_flash($publishResult['message']);
            cms_redirect(price_sheet_page_url($postFromWarehouse));
        }

        if ($action === 'publish_category') {
            if ($postCategoryId <= 0) {
                throw new RuntimeException('دسته انتخاب نشده است');
            }
            $publishResult = price_sheet_publish($pdo, $postCategoryId);
            cms_flash($publishResult['message']);
            cms_redirect(price_sheet_page_url($postFromWarehouse));
        }
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect(price_sheet_page_url($postFromWarehouse));
    }
}

$status = price_sheet_get_status($pdo);
$googleImport = $status['google_import'] ?? price_sheet_get_google_import_info();
$googleSheetUrl = (string) ($googleImport['sheet_url'] ?? '');
$frames = price_sheet_list_frames($pdo);

cms_layout_start('لیست قیمت', cms_current_username(), $layoutSection);
?>
<div class="price-sheet-page cms-price-sheet">
  <header class="cms-price-sheet__intro">
    <h1 style="margin:0">لیست قیمت<?= $fromWarehouse ? ' — انبار' : '' ?></h1>
    <p class="cms-muted">
      همه ستون‌ها قابل ویرایش هستند. برای <strong>کیت‌ها</strong>، خودرو را دستی در ستون «خودرو» وارد کنید (مثلاً «پژو ۲۰۶ · ۴۰۵») — از قطعات کیت پر نمی‌شود. قیمت‌ها در پیش‌نویس ذخیره می‌شوند و با «انتشار» روی سایت و پورتال نمایندگان اعمال می‌شوند.
      موجودی انبار با «ذخیره پیش‌نویس» روی محصول مرتبط (با همان کد کالا) به‌روز می‌شود.
      قیمت بسته از واحد × کارتن محاسبه می‌شود؛ در صورت ویرایش قیمت بسته، قیمت واحد تنظیم می‌شود.
      <?php if (!$fromWarehouse): ?>
        برای ایجاد محصول جدید از <a href="product-price-import.php">ورود قیمت از Excel</a> استفاده کنید.
      <?php endif; ?>
    </p>
  </header>

  <div class="cms-card cms-price-sheet__toolbar" style="margin-bottom:1rem">
    <div class="cms-btn-row" style="flex-wrap:wrap;gap:.5rem;align-items:center">
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="publish_all">
        <?php if ($fromWarehouse): ?>
          <input type="hidden" name="from_warehouse" value="1">
        <?php endif; ?>
        <button class="cms-btn cms-btn--primary" type="submit" <?= $status['draft_rows'] <= 0 ? 'disabled' : '' ?>>
          انتشار همه
        </button>
      </form>
      <span class="cms-muted" style="margin-right:auto">
        <?= cms_to_persian_digits((string) $status['draft_rows']) ?> ردیف پیش‌نویس
        <?php if ($status['last_published_at_display'] !== ''): ?>
          — آخرین انتشار: <strong><?= cms_h($status['last_published_at_display']) ?></strong>
          <?php if ($status['last_published_count'] > 0): ?>
            (<?= cms_h(cms_to_persian_digits((string) $status['last_published_count'])) ?> مورد)
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($status['draft_updated_at_display'] !== ''): ?>
          — آخرین ویرایش پیش‌نویس: <?= cms_h($status['draft_updated_at_display']) ?>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <?php if ($categories === []): ?>
    <div class="cms-card">
      <p class="cms-muted">هنوز دسته‌بندی تعریف نشده است. ابتدا از <a href="categories.php">دسته‌بندی‌ها</a> یک دسته بسازید.</p>
    </div>
  <?php elseif ($frames === []): ?>
    <div class="cms-card">
      <p class="cms-muted">پیش‌نویس خالی است. از «ابزارهای مدیریت» Google Sheet یا Excel را وارد کنید، یا ردیف جدید اضافه کنید.</p>
    </div>
  <?php else: ?>
    <form method="post" id="price-sheet-form">
      <input type="hidden" name="action" value="save_draft_all">
      <?php if ($fromWarehouse): ?>
        <input type="hidden" name="from_warehouse" value="1">
      <?php endif; ?>

      <div class="cms-btn-row" style="justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <p class="cms-muted" style="margin:0">همه دسته‌ها — همان چیدمان پورتال نمایندگان</p>
        <button class="cms-btn cms-btn--primary" type="submit">ذخیره پیش‌نویس</button>
      </div>

      <?php foreach ($frames as $frame): ?>
        <?php
          $categoryId = (int) $frame['category_id'];
          $categoryName = (string) $frame['category_name'];
          $frameRows = is_array($frame['rows'] ?? null) ? $frame['rows'] : [];
        ?>
        <article class="cms-price-sheet__frame" data-category-id="<?= $categoryId ?>">
          <header class="cms-price-sheet__frame-head">
            <h2><?= cms_h($categoryName) ?></h2>
            <span><?= cms_h(cms_to_persian_digits((string) count($frameRows))) ?> محصول</span>
            <button
              class="cms-btn cms-btn--ghost cms-price-sheet__publish-cat"
              type="submit"
              form="price-sheet-publish-<?= $categoryId ?>"
              style="font-size:.78rem;padding:.2rem .55rem;margin-inline-start:auto"
              <?= $frameRows === [] ? 'disabled' : '' ?>
            >
              انتشار این دسته
            </button>
          </header>
          <div class="cms-price-sheet__scroll">
            <table class="cms-price-sheet__table">
              <thead>
                <tr>
                  <th scope="col">کد کالا</th>
                  <th scope="col">نام</th>
                  <th scope="col">خودرو</th>
                  <th scope="col">گارانتی</th>
                  <th scope="col">تعداد در کارتن</th>
                  <th scope="col">قیمت واحد</th>
                  <th scope="col">قیمت بسته</th>
                  <th scope="col">موجودی انبار</th>
                  <th scope="col">حذف</th>
                </tr>
              </thead>
              <tbody class="cms-price-sheet__rows" data-category-id="<?= $categoryId ?>">
                <?php foreach ($frameRows as $index => $row): ?>
                  <?php
                    $packPriceValue = (string) ($row['pack_price_text'] ?? '');
                    if ($packPriceValue === '—') {
                        $packPriceValue = '';
                    }
                    $stockValue = $row['stock_qty'] !== null ? (string) $row['stock_qty'] : '';
                  ?>
                  <tr>
                    <td class="cms-price-sheet__code">
                      <input type="hidden" name="frames[<?= $categoryId ?>][<?= $index ?>][id]" value="<?= (int) $row['id'] ?>">
                      <input class="cms-input cms-price-sheet__input" name="frames[<?= $categoryId ?>][<?= $index ?>][visual_id]" value="<?= cms_h((string) $row['visual_id']) ?>" dir="ltr">
                    </td>
                    <td class="cms-price-sheet__name">
                      <input class="cms-input cms-price-sheet__input" name="frames[<?= $categoryId ?>][<?= $index ?>][name]" value="<?= cms_h((string) $row['name']) ?>">
                    </td>
                    <td>
                      <input class="cms-input cms-price-sheet__input" name="frames[<?= $categoryId ?>][<?= $index ?>][model_name]" value="<?= cms_h((string) ($row['model_name'] ?? '')) ?>">
                    </td>
                    <td>
                      <input class="cms-input cms-price-sheet__input" name="frames[<?= $categoryId ?>][<?= $index ?>][warranty_text]" value="<?= cms_h((string) ($row['warranty_text'] ?? '')) ?>">
                    </td>
                    <td>
                      <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[<?= $categoryId ?>][<?= $index ?>][pack_size]" value="<?= $row['pack_size'] !== null ? cms_h((string) $row['pack_size']) : '' ?>" dir="ltr" data-pack-input>
                    </td>
                    <td class="cms-price-sheet__price">
                      <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[<?= $categoryId ?>][<?= $index ?>][price_text]" value="<?= cms_h((string) $row['price_text']) ?>" dir="ltr" data-price-input>
                    </td>
                    <td class="cms-price-sheet__price">
                      <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[<?= $categoryId ?>][<?= $index ?>][pack_price_text]" value="<?= cms_h($packPriceValue) ?>" dir="ltr" data-pack-price-input>
                    </td>
                    <td>
                      <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[<?= $categoryId ?>][<?= $index ?>][stock_qty]" value="<?= cms_h($stockValue) ?>" dir="ltr" inputmode="numeric">
                    </td>
                    <td class="cms-price-sheet__delete"><input type="checkbox" name="frames[<?= $categoryId ?>][<?= $index ?>][delete]" value="1"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="cms-price-sheet__frame-actions">
            <button class="cms-btn" type="button" data-add-row="<?= $categoryId ?>">+ ردیف جدید</button>
          </div>
        </article>
      <?php endforeach; ?>

      <div class="cms-form__actions" style="margin-top:1rem">
        <button class="cms-btn cms-btn--primary" type="submit">ذخیره پیش‌نویس</button>
      </div>
    </form>

    <?php foreach ($frames as $frame): ?>
      <?php $categoryId = (int) $frame['category_id']; ?>
      <form method="post" id="price-sheet-publish-<?= $categoryId ?>" hidden>
        <input type="hidden" name="action" value="publish_category">
        <input type="hidden" name="category_id" value="<?= $categoryId ?>">
        <?php if ($fromWarehouse): ?>
          <input type="hidden" name="from_warehouse" value="1">
        <?php endif; ?>
      </form>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!$fromWarehouse): ?>
    <details class="cms-card cms-price-sheet__tools" style="margin-top:1.25rem">
      <summary style="cursor:pointer;font-weight:700">ابزارهای مدیریت</summary>

      <div class="price-import-sheet" style="margin-top:1rem">
        <h2 style="margin-top:0">واردات یک‌باره از Google Sheet</h2>
        <p class="cms-muted">
          لینک اشتراک‌گذاری Google Sheet را بگذارید (دسترسی: «Anyone with the link can view»).
          ردیف‌ها بر اساس سرتیتر دسته در شیت به پیش‌نویس نگاشت می‌شوند.
        </p>
        <form method="post" class="cms-form">
          <input type="hidden" name="action" value="import_google">
          <label class="cms-label">آدرس Google Sheet</label>
          <input
            class="cms-input"
            type="url"
            name="google_sheet_url"
            value="<?= cms_h($googleSheetUrl) ?>"
            placeholder="https://docs.google.com/spreadsheets/d/..."
            dir="ltr"
            style="text-align:left"
            required
          >
          <div class="cms-form__actions">
            <button class="cms-btn cms-btn--primary" type="submit">واردات به پیش‌نویس</button>
          </div>
        </form>
        <?php if (($googleImport['imported_at_display'] ?? '') !== ''): ?>
          <p class="cms-muted" style="margin-top:1rem">
            آخرین واردات Google Sheet:
            <strong><?= cms_h((string) $googleImport['imported_at_display']) ?></strong>
            <?php if ((int) ($googleImport['imported_count'] ?? 0) > 0): ?>
              — <?= cms_h(cms_to_persian_digits((string) $googleImport['imported_count'])) ?> ردیف در پیش‌نویس
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>

      <div style="margin-top:1.5rem">
        <h2 style="margin-top:0">آپلود Excel به پیش‌نویس (هر دسته)</h2>
        <p class="cms-muted"><?= cms_h(price_import_xlsx_support_hint()) ?></p>
        <?php foreach ($categories as $cat): ?>
          <?php $catId = (int) $cat['id']; ?>
          <details style="margin-top:.75rem">
            <summary style="cursor:pointer">
              <?= cms_h((string) $cat['name']) ?>
              <?php if ((int) $cat['row_count'] > 0): ?>
                <span class="cms-muted">(<?= cms_h(cms_to_persian_digits((string) $cat['row_count'])) ?>)</span>
              <?php endif; ?>
            </summary>
            <form method="post" enctype="multipart/form-data" class="cms-form" style="margin-top:.5rem">
              <input type="hidden" name="action" value="upload">
              <input type="hidden" name="category_id" value="<?= $catId ?>">
              <label class="cms-label">فایل .xlsx یا .csv</label>
              <input class="cms-input" type="file" name="price_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
              <div class="cms-form__actions">
                <button class="cms-btn" type="submit">ادغام در پیش‌نویس</button>
              </div>
            </form>
          </details>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>
</div>

<script>
(function () {
  var persianDigits = {'0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹'};

  function normalizeDigits(text) {
    return String(text || '').replace(/[۰-۹٠-٩]/g, function (ch) {
      var map = {'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9','٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'};
      return map[ch] || ch;
    }).replace(/[٬،,\s\u00A0]/g, '').replace(/تومان$/u, '').trim();
  }

  function parseToman(raw) {
    var normalized = normalizeDigits(raw);
    if (!normalized || /ریال/u.test(raw || '')) return null;
    if (!/^\d+$/.test(normalized) || normalized.length > 12) return null;
    var amount = Number(normalized);
    return Number.isFinite(amount) ? amount : null;
  }

  function formatToman(amount) {
    var grouped = Math.max(0, Math.floor(amount)).toLocaleString('en-US').replace(/,/g, '٬');
    return grouped.replace(/\d/g, function (d) { return persianDigits[d] || d; }) + ' تومان';
  }

  function updatePackPrice(row, fromUnit) {
    var priceInput = row.querySelector('[data-price-input]');
    var packInput = row.querySelector('[data-pack-input]');
    var packPriceInput = row.querySelector('[data-pack-price-input]');
    if (!priceInput || !packInput || !packPriceInput) return;
    var packSize = parseInt(normalizeDigits(packInput.value), 10);
    if (!packSize || packSize <= 0) {
      if (fromUnit) packPriceInput.value = '';
      return;
    }
    if (fromUnit === false) {
      var packAmount = parseToman(packPriceInput.value);
      if (packAmount !== null && packAmount > 0) {
        var unitAmount = Math.floor(packAmount / packSize);
        if (unitAmount > 0) {
          priceInput.value = formatToman(unitAmount);
        }
      }
      return;
    }
    var unit = parseToman(priceInput.value);
    if (unit === null) {
      packPriceInput.value = '';
      return;
    }
    packPriceInput.value = formatToman(unit * packSize);
  }

  function bindRow(row) {
    var priceInput = row.querySelector('[data-price-input]');
    var packInput = row.querySelector('[data-pack-input]');
    var packPriceInput = row.querySelector('[data-pack-price-input]');
    if (priceInput) priceInput.addEventListener('input', function () { updatePackPrice(row, true); });
    if (packInput) packInput.addEventListener('input', function () { updatePackPrice(row, true); });
    if (packPriceInput) packPriceInput.addEventListener('input', function () { updatePackPrice(row, false); });
  }

  document.querySelectorAll('.cms-price-sheet__rows tr').forEach(function (row) {
    bindRow(row);
    updatePackPrice(row, true);
  });

  document.querySelectorAll('[data-add-row]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var categoryId = btn.getAttribute('data-add-row');
      var tbody = document.querySelector('.cms-price-sheet__rows[data-category-id="' + categoryId + '"]');
      if (!tbody) return;
      var index = tbody.querySelectorAll('tr').length;
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td class="cms-price-sheet__code">' +
          '<input type="hidden" name="frames[' + categoryId + '][' + index + '][id]" value="0">' +
          '<input class="cms-input cms-price-sheet__input" name="frames[' + categoryId + '][' + index + '][visual_id]" value="" dir="ltr">' +
        '</td>' +
        '<td class="cms-price-sheet__name">' +
          '<input class="cms-input cms-price-sheet__input" name="frames[' + categoryId + '][' + index + '][name]" value="">' +
        '</td>' +
        '<td><input class="cms-input cms-price-sheet__input" name="frames[' + categoryId + '][' + index + '][model_name]" value=""></td>' +
        '<td><input class="cms-input cms-price-sheet__input" name="frames[' + categoryId + '][' + index + '][warranty_text]" value=""></td>' +
        '<td><input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[' + categoryId + '][' + index + '][pack_size]" value="" dir="ltr" data-pack-input></td>' +
        '<td class="cms-price-sheet__price">' +
          '<input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[' + categoryId + '][' + index + '][price_text]" value="" dir="ltr" data-price-input>' +
        '</td>' +
        '<td class="cms-price-sheet__price">' +
          '<input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[' + categoryId + '][' + index + '][pack_price_text]" value="" dir="ltr" data-pack-price-input>' +
        '</td>' +
        '<td><input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[' + categoryId + '][' + index + '][stock_qty]" value="" dir="ltr" inputmode="numeric"></td>' +
        '<td class="cms-price-sheet__delete"><input type="checkbox" name="frames[' + categoryId + '][' + index + '][delete]" value="1"></td>';
      tbody.appendChild(tr);
      bindRow(tr);
      var firstInput = tr.querySelector('input[name*="[visual_id]"]');
      if (firstInput) firstInput.focus();
    });
  });
})();
</script>
<?php
cms_layout_end();
