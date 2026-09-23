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

$categories = price_sheet_list_categories($pdo);
$activeCategoryId = (int) ($_GET['category_id'] ?? 0);
if ($activeCategoryId <= 0 && $categories !== []) {
    $activeCategoryId = (int) $categories[0]['id'];
}

$publishResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postCategoryId = (int) ($_POST['category_id'] ?? $activeCategoryId);

    try {
        if ($action === 'save_draft') {
            $postedRows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
            $result = price_sheet_save_rows($pdo, $postCategoryId, $postedRows);
            cms_admin_audit($pdo, 'price_sheet.save', [
                'entity_type' => 'category',
                'entity_id' => $postCategoryId,
                'summary' => cms_current_username() . ' — ذخیره پیش‌نویس لیست قیمت (' . $result['saved'] . ' ردیف)',
                'detail' => $result,
            ]);
            cms_flash(sprintf(
                'پیش‌نویس ذخیره شد — %s ردیف',
                cms_to_persian_digits((string) $result['saved'])
            ));
            cms_redirect('price-sheet.php?category_id=' . $postCategoryId);
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
            cms_redirect('price-sheet.php?category_id=' . $postCategoryId);
        }

        if ($action === 'import_google') {
            $sheetUrl = trim((string) ($_POST['google_sheet_url'] ?? ''));
            $importResult = price_sheet_import_from_google_sheet($pdo, $sheetUrl);
            cms_flash($importResult['message']);
            cms_redirect('price-sheet.php?category_id=' . $activeCategoryId);
        }

        if ($action === 'publish_all') {
            $publishResult = price_sheet_publish($pdo, null);
            cms_flash($publishResult['message']);
            cms_redirect('price-sheet.php?category_id=' . $activeCategoryId);
        }

        if ($action === 'publish_category') {
            if ($postCategoryId <= 0) {
                throw new RuntimeException('دسته انتخاب نشده است');
            }
            $publishResult = price_sheet_publish($pdo, $postCategoryId);
            cms_flash($publishResult['message']);
            cms_redirect('price-sheet.php?category_id=' . $postCategoryId);
        }
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        $redirectCategory = $postCategoryId > 0 ? $postCategoryId : $activeCategoryId;
        cms_redirect('price-sheet.php?category_id=' . $redirectCategory);
    }
}

$status = price_sheet_get_status($pdo);
$googleImport = $status['google_import'] ?? price_sheet_get_google_import_info();
$googleSheetUrl = (string) ($googleImport['sheet_url'] ?? '');
$rows = $activeCategoryId > 0 ? price_sheet_list_rows($pdo, $activeCategoryId) : [];
$activeCategoryName = '';
foreach ($categories as $cat) {
    if ((int) $cat['id'] === $activeCategoryId) {
        $activeCategoryName = (string) $cat['name'];
        break;
    }
}

cms_layout_start('لیست قیمت', cms_current_username(), 'shop');
?>
<div class="price-sheet-page">
<h1 style="margin-top:0">لیست قیمت</h1>
<p class="cms-muted">
  قیمت‌ها در پیش‌نویس ذخیره می‌شوند و تا زمان «انتشار» روی سایت و اپ اعمال نمی‌شوند.
  ویرایش روزانه فقط در همین صفحه (یا Excel هر دسته) انجام می‌شود — Google Sheet فقط برای <strong>واردات یک‌باره</strong> است.
  برای ایجاد محصول جدید از <a href="product-price-import.php">ورود قیمت از Excel</a> استفاده کنید.
</p>

<div class="cms-card price-import-sheet" style="margin-bottom:1rem">
  <h2 style="margin-top:0">واردات یک‌باره از Google Sheet</h2>
  <p class="cms-muted">
    لینک اشتراک‌گذاری Google Sheet را بگذارید (دسترسی: «Anyone with the link can view»).
    ردیف‌ها بر اساس <strong>سرتیتر دسته</strong> در شیت به تب‌های CMS نگاشت می‌شوند و در پیش‌نویس ذخیره می‌شوند.
    این کار Google را به‌روز نمی‌کند — بعد از واردات، منبع اصلی همین «لیست قیمت» CMS است.
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
    <p class="cms-muted" style="margin:.35rem 0 0;font-size:.85rem">
      برای پر کردن اولیه پیش‌نویس یک بار «واردات به پیش‌نویس» را بزنید. می‌توانید دوباره بزنید تا ردیف‌های موجود به‌روز شوند (ادغام بر اساس کد کالا).
    </p>
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

<div class="cms-card" style="margin-bottom:1rem">
  <div class="cms-btn-row" style="flex-wrap:wrap;gap:.5rem;align-items:center">
    <form method="post" style="display:inline">
      <input type="hidden" name="action" value="publish_all">
      <button class="cms-btn cms-btn--primary" type="submit" <?= $status['draft_rows'] <= 0 ? 'disabled' : '' ?>>
        انتشار همه
      </button>
    </form>
    <?php if ($activeCategoryId > 0): ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="publish_category">
        <input type="hidden" name="category_id" value="<?= (int) $activeCategoryId ?>">
        <button class="cms-btn" type="submit" <?= $rows === [] ? 'disabled' : '' ?>>
          انتشار این دسته
        </button>
      </form>
    <?php endif; ?>
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
<?php else: ?>
  <nav class="cms-btn-row" style="flex-wrap:wrap;margin-bottom:1rem;gap:.35rem">
    <?php foreach ($categories as $cat): ?>
      <?php
        $catId = (int) $cat['id'];
        $isActive = $catId === $activeCategoryId;
        $rowCount = (int) $cat['row_count'];
      ?>
      <a
        class="cms-btn<?= $isActive ? ' cms-btn--primary' : '' ?>"
        href="price-sheet.php?category_id=<?= $catId ?>"
        style="font-size:.9rem"
      >
        <?= cms_h((string) $cat['name']) ?>
        <?php if ($rowCount > 0): ?>
          <span class="cms-muted">(<?= cms_h(cms_to_persian_digits((string) $rowCount)) ?>)</span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="cms-card" style="margin-bottom:1rem">
    <h2 style="margin-top:0">آپلود Excel — <?= cms_h($activeCategoryName) ?></h2>
    <p class="cms-muted"><?= cms_h(price_import_xlsx_support_hint()) ?></p>
    <form method="post" enctype="multipart/form-data" class="cms-form">
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="category_id" value="<?= (int) $activeCategoryId ?>">
      <label class="cms-label">فایل .xlsx یا .csv</label>
      <input class="cms-input" type="file" name="price_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
      <div class="cms-form__actions">
        <button class="cms-btn cms-btn--primary" type="submit">ادغام در پیش‌نویس این دسته</button>
      </div>
    </form>
  </div>

  <form method="post" class="cms-card">
    <input type="hidden" name="action" value="save_draft">
    <input type="hidden" name="category_id" value="<?= (int) $activeCategoryId ?>">
    <div class="cms-btn-row" style="justify-content:space-between;align-items:center;margin-bottom:.75rem">
      <h2 style="margin:0">جدول — <?= cms_h($activeCategoryName) ?></h2>
      <button class="cms-btn cms-btn--primary" type="submit">ذخیره پیش‌نویس</button>
    </div>

    <div style="overflow-x:auto">
      <table class="cms-table price-sheet-grid">
        <thead>
          <tr>
            <th>کد کالا</th>
            <th>نام</th>
            <th>قیمت (تومان)</th>
            <th>تعداد بسته</th>
            <th>حذف</th>
          </tr>
        </thead>
        <tbody id="price-sheet-rows">
          <?php foreach ($rows as $index => $row): ?>
            <tr>
              <td>
                <input type="hidden" name="rows[<?= $index ?>][id]" value="<?= (int) $row['id'] ?>">
                <input class="cms-input" name="rows[<?= $index ?>][visual_id]" value="<?= cms_h((string) $row['visual_id']) ?>" dir="ltr" style="min-width:5rem">
              </td>
              <td><input class="cms-input" name="rows[<?= $index ?>][name]" value="<?= cms_h((string) $row['name']) ?>"></td>
              <td><input class="cms-input" name="rows[<?= $index ?>][price_text]" value="<?= cms_h((string) $row['price_text']) ?>" dir="ltr" style="min-width:6rem"></td>
              <td><input class="cms-input" name="rows[<?= $index ?>][pack_size]" value="<?= $row['pack_size'] !== null ? cms_h((string) $row['pack_size']) : '' ?>" dir="ltr" style="width:4rem"></td>
              <td style="text-align:center"><input type="checkbox" name="rows[<?= $index ?>][delete]" value="1"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="cms-form__actions" style="margin-top:1rem">
      <button class="cms-btn" type="button" id="price-sheet-add-row">+ ردیف جدید</button>
      <button class="cms-btn cms-btn--primary" type="submit">ذخیره پیش‌نویس</button>
    </div>
  </form>
<?php endif; ?>
</div>

<script>
(function () {
  var tbody = document.getElementById('price-sheet-rows');
  var addBtn = document.getElementById('price-sheet-add-row');
  if (!tbody || !addBtn) return;

  addBtn.addEventListener('click', function () {
    var index = tbody.querySelectorAll('tr').length;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="hidden" name="rows[' + index + '][id]" value="0">' +
      '<input class="cms-input" name="rows[' + index + '][visual_id]" value="" dir="ltr" style="min-width:5rem"></td>' +
      '<td><input class="cms-input" name="rows[' + index + '][name]" value=""></td>' +
      '<td><input class="cms-input" name="rows[' + index + '][price_text]" value="" dir="ltr" style="min-width:6rem"></td>' +
      '<td><input class="cms-input" name="rows[' + index + '][pack_size]" value="" dir="ltr" style="width:4rem"></td>' +
      '<td style="text-align:center"><input type="checkbox" name="rows[' + index + '][delete]" value="1"></td>';
    tbody.appendChild(tr);
    var firstInput = tr.querySelector('input[name*="[visual_id]"]');
    if (firstInput) firstInput.focus();
  });
})();
</script>
<?php
cms_layout_end();
