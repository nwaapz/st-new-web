<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/jalali.php';
require_once __DIR__ . '/lib/price-sheet.php';

cms_require_login();
cms_session_start();

function price_sheet_wants_json(): bool
{
    if ((string) ($_POST['ajax'] ?? '') === '1') {
        return true;
    }

    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');

    return str_contains($accept, 'application/json');
}

function price_sheet_json_exit(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = cms_pdo();
cms_ensure_product_categories_schema($pdo);
price_sheet_ensure_schema($pdo);

$fromWarehouse = (string) ($_GET['from'] ?? '') === 'warehouse';
$pageUrl = price_sheet_page_url($fromWarehouse);
$exportIncludeStockDefault = price_sheet_export_include_stock_default($fromWarehouse);
$layoutSection = $fromWarehouse ? 'customers' : 'shop';
$highlightVisualId = price_import_normalize_visual_id((string) ($_GET['highlight'] ?? ''));

if ($fromWarehouse && isset($_GET['find'])) {
    $findCode = trim((string) $_GET['find']);
    if ($findCode !== '') {
        try {
            $findResult = price_sheet_find_or_add_by_visual_id($pdo, $findCode);
            $highlightVisualId = $findResult['visual_id'];
            cms_flash($findResult['added']
                ? sprintf(
                    'کد %s به پیش‌نویس اضافه شد — دسته «%s»',
                    cms_to_persian_digits($highlightVisualId),
                    $findResult['category_name']
                )
                : sprintf(
                    'کد %s در دسته «%s» یافت شد',
                    cms_to_persian_digits($highlightVisualId),
                    $findResult['category_name']
                ));
        } catch (Throwable $e) {
            cms_flash($e->getMessage(), 'error');
            $highlightVisualId = '';
        }

        $redirectParams = [];
        if ($highlightVisualId !== '') {
            $redirectParams['highlight'] = $highlightVisualId;
        }
        $redirectParams['from'] = 'warehouse';
        cms_redirect('price-sheet.php?' . http_build_query($redirectParams));
    }
}

$categories = price_sheet_list_categories($pdo);
$publishResult = null;

if (isset($_GET['export']) && (string) $_GET['export'] === '1') {
    try {
        $includeStock = price_sheet_export_include_stock_from_query($_GET, $fromWarehouse);
        price_sheet_send_xlsx_export($pdo, $fromWarehouse, $includeStock);
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect($pageUrl);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postCategoryId = (int) ($_POST['category_id'] ?? 0);
    $postFromWarehouse = !empty($_POST['from_warehouse']);

    try {
        if ($action === 'save_draft_row') {
            $rowInput = [
                'id' => (int) ($_POST['id'] ?? 0),
                'visual_id' => (string) ($_POST['visual_id'] ?? ''),
                'name' => (string) ($_POST['name'] ?? ''),
                'warranty_text' => (string) ($_POST['warranty_text'] ?? ''),
                'pack_size' => (string) ($_POST['pack_size'] ?? ''),
                'price_text' => (string) ($_POST['price_text'] ?? ''),
                'pack_price_text' => (string) ($_POST['pack_price_text'] ?? ''),
                'stock_qty' => (string) ($_POST['stock_qty'] ?? ''),
                'delete' => !empty($_POST['delete']),
            ];
            $result = price_sheet_save_one_row($pdo, $postCategoryId, $rowInput);
            price_sheet_json_exit($result, !empty($result['ok']) ? 200 : 400);
        }

        if ($action === 'save_draft_summary') {
            $saved = max(0, (int) ($_POST['saved'] ?? 0));
            $failed = max(0, (int) ($_POST['failed'] ?? 0));
            $deleted = max(0, (int) ($_POST['deleted'] ?? 0));
            cms_admin_audit($pdo, 'price_sheet.save', [
                'entity_type' => 'price_sheet',
                'summary' => cms_current_username() . ' — ذخیره پیش‌نویس لیست قیمت (' . $saved . ' ردیف)',
                'detail' => [
                    'saved' => $saved,
                    'failed' => $failed,
                    'deleted' => $deleted,
                ],
            ]);
            price_sheet_json_exit([
                'ok' => true,
                'saved' => $saved,
                'failed' => $failed,
                'deleted' => $deleted,
                'message' => sprintf(
                    'پیش‌نویس ذخیره شد — %s ردیف',
                    cms_to_persian_digits((string) $saved)
                ),
                'redirect' => price_sheet_page_url($postFromWarehouse),
            ]);
        }

        if ($action === 'save_draft_all') {
            $postedFrames = is_array($_POST['frames'] ?? null) ? $_POST['frames'] : [];
            $result = price_sheet_save_frames($pdo, $postedFrames);
            cms_admin_audit($pdo, 'price_sheet.save', [
                'entity_type' => 'price_sheet',
                'summary' => cms_current_username() . ' — ذخیره پیش‌نویس لیست قیمت (' . $result['saved'] . ' ردیف)',
                'detail' => $result,
            ]);
            $savedMessage = sprintf(
                'پیش‌نویس ذخیره شد — %s ردیف',
                cms_to_persian_digits((string) $result['saved'])
            );
            if (price_sheet_wants_json()) {
                price_sheet_json_exit([
                    'ok' => true,
                    'saved' => (int) $result['saved'],
                    'deleted' => (int) ($result['deleted'] ?? 0),
                    'message' => $savedMessage,
                    'redirect' => price_sheet_page_url($postFromWarehouse),
                ]);
            }
            cms_flash($savedMessage);
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

        if ($action === 'sync_categories') {
            $syncResult = price_sheet_sync_categories_from_catalog($pdo);
            cms_admin_audit($pdo, 'price_sheet.sync_categories', [
                'entity_type' => 'price_sheet',
                'summary' => cms_current_username() . ' — هماهنگ‌سازی دسته‌های لیست قیمت با فروشگاه',
                'detail' => $syncResult,
            ]);
            cms_flash(sprintf(
                'دسته‌ها هماهنگ شد — %s منتقل، %s بدون تغییر، %s بدون دسته در فروشگاه',
                cms_to_persian_digits((string) $syncResult['moved']),
                cms_to_persian_digits((string) $syncResult['unchanged']),
                cms_to_persian_digits((string) $syncResult['unresolved'])
            ));
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
        if (in_array($action, ['save_draft_all', 'save_draft_row', 'save_draft_summary'], true)
            && price_sheet_wants_json()) {
            price_sheet_json_exit([
                'ok' => false,
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
            ], 400);
        }
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
<div class="price-sheet-page cms-price-sheet<?= $fromWarehouse ? ' cms-price-sheet--warehouse' : '' ?>">
  <div id="price-sheet-save-modal" class="cms-confirm-modal" hidden>
    <div class="cms-confirm-modal__panel" role="dialog" aria-modal="true" aria-labelledby="price-sheet-save-title" style="width:min(640px,100%)">
      <div class="cms-confirm-modal__head">
        <strong id="price-sheet-save-title">در حال ذخیره</strong>
      </div>
      <div id="price-sheet-save-body">
        <p id="price-sheet-save-now" style="margin:0 0 .75rem;font-weight:700"></p>
        <div class="cms-price-sheet__save-progress" id="price-sheet-save-progress-wrap" hidden>
          <progress id="price-sheet-save-progress" max="1" value="0"></progress>
        </div>
        <ol id="price-sheet-save-log" class="cms-price-sheet__save-log"></ol>
      </div>
      <div class="cms-btn-row" id="price-sheet-save-actions" hidden>
        <button type="button" class="cms-btn cms-btn--primary" id="price-sheet-save-dismiss">Close</button>
      </div>
    </div>
  </div>
  <p id="price-sheet-save-error" class="cms-error" hidden></p>
  <header class="cms-price-sheet__intro">
    <h1 style="margin:0">لیست قیمت<?= $fromWarehouse ? ' — انبار' : '' ?></h1>
    <p class="cms-muted">
      ردیف‌ها بر اساس <strong>دسته محصول در فروشگاه</strong> (از <a href="products.php">محصولات</a> و <a href="product-series.php">سری محصولات</a>) در قاب‌های دسته‌بندی چیده می‌شوند — نه بر اساس سرتیتر Excel.
      ستون «خودرو» فقط خواندنی است.
      <strong>قیمت واحد منبع اصلی است:</strong> از کاتالوگ فروشگاه (محصول / سری) خوانده می‌شود و با «ذخیره پیش‌نویس» بلافاصله روی فروشگاه آنلاین و اپ مشتری به‌روز می‌شود. «انتشار» برای همگام‌سازی نهایی و گزارش است.
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
        <input type="hidden" name="action" value="sync_categories">
        <?php if ($fromWarehouse): ?>
          <input type="hidden" name="from_warehouse" value="1">
        <?php endif; ?>
        <button class="cms-btn cms-btn--secondary" type="submit" <?= $status['draft_rows'] <= 0 ? 'disabled' : '' ?>>
          هماهنگ‌سازی دسته‌ها با فروشگاه
        </button>
      </form>
      <?php if ($status['draft_rows'] <= 0): ?>
        <span class="cms-btn cms-btn--secondary" aria-disabled="true" style="opacity:.55;cursor:not-allowed">خروجی Excel</span>
      <?php else: ?>
        <form
          method="get"
          action="price-sheet.php"
          class="cms-price-sheet__export-form"
          style="display:inline-flex;align-items:center;gap:.45rem;flex-wrap:wrap"
        >
          <input type="hidden" name="export" value="1">
          <?php if ($fromWarehouse): ?>
            <input type="hidden" name="from" value="warehouse">
          <?php endif; ?>
          <label class="cms-check cms-price-sheet__export-toggle" style="margin:0;font-size:.85rem">
            <input type="checkbox" name="include_stock" value="1" <?= $exportIncludeStockDefault ? 'checked' : '' ?>>
            شامل موجودی انبار
          </label>
          <button class="cms-btn cms-btn--secondary" type="submit">خروجی Excel</button>
        </form>
      <?php endif; ?>
      <?php if ($fromWarehouse): ?>
        <form
          method="get"
          action="price-sheet.php"
          class="cms-price-sheet__find-form"
          style="display:inline-flex;align-items:center;gap:.35rem;flex-wrap:wrap"
        >
          <input type="hidden" name="from" value="warehouse">
          <label class="cms-muted" for="price-sheet-find" style="margin:0;font-size:.85rem">جستجو با کد:</label>
          <input
            id="price-sheet-find"
            class="cms-input cms-price-sheet__find-input"
            name="find"
            value=""
            placeholder="کد کالا"
            dir="ltr"
            inputmode="numeric"
            autocomplete="off"
            required
          >
          <button class="cms-btn cms-btn--secondary" type="submit">یافتن</button>
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
                    $carDisplay = trim((string) ($row['model_name'] ?? ''));
                    $priceInputValue = price_sheet_price_input_value((string) ($row['price_text'] ?? ''));
                    $packPriceInputValue = $packPriceValue !== ''
                        ? price_sheet_price_input_value($packPriceValue)
                        : '';
                  ?>
                  <tr
                    data-visual-id="<?= cms_h((string) $row['visual_id']) ?>"
                    <?= $highlightVisualId !== '' && $highlightVisualId === (string) $row['visual_id'] ? 'class="cms-price-sheet__row--highlight"' : '' ?>
                  >
                    <td class="cms-price-sheet__code">
                      <input type="hidden" name="frames[<?= $categoryId ?>][<?= $index ?>][id]" value="<?= (int) $row['id'] ?>">
                      <input class="cms-input cms-price-sheet__input" name="frames[<?= $categoryId ?>][<?= $index ?>][visual_id]" value="<?= cms_h((string) $row['visual_id']) ?>" dir="ltr">
                    </td>
                    <td class="cms-price-sheet__name">
                      <input class="cms-input cms-price-sheet__input" name="frames[<?= $categoryId ?>][<?= $index ?>][name]" value="<?= cms_h((string) $row['name']) ?>">
                    </td>
                    <td class="cms-price-sheet__car">
                      <span class="cms-price-sheet__readonly"><?= cms_h($carDisplay !== '' ? $carDisplay : '—') ?></span>
                    </td>
                    <td>
                      <input class="cms-input cms-price-sheet__input" name="frames[<?= $categoryId ?>][<?= $index ?>][warranty_text]" value="<?= cms_h((string) ($row['warranty_text'] ?? '')) ?>">
                    </td>
                    <td>
                      <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[<?= $categoryId ?>][<?= $index ?>][pack_size]" value="<?= $row['pack_size'] !== null ? cms_h((string) $row['pack_size']) : '' ?>" dir="ltr" data-pack-input>
                    </td>
                    <td class="cms-price-sheet__price">
                      <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[<?= $categoryId ?>][<?= $index ?>][price_text]" value="<?= cms_h($priceInputValue) ?>" dir="ltr" data-price-input>
                    </td>
                    <td class="cms-price-sheet__price">
                      <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[<?= $categoryId ?>][<?= $index ?>][pack_price_text]" value="<?= cms_h($packPriceInputValue) ?>" dir="ltr" data-pack-price-input>
                    </td>
                    <td class="cms-price-sheet__stock">
                      <?php if ($fromWarehouse): ?>
                        <div class="cms-price-sheet__stock-row">
                          <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num cms-price-sheet__input--stock" name="frames[<?= $categoryId ?>][<?= $index ?>][stock_qty]" value="<?= cms_h($stockValue) ?>" dir="ltr" inputmode="numeric" data-stock-input>
                          <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num cms-price-sheet__input--delta" type="text" inputmode="numeric" dir="ltr" data-stock-delta aria-label="تعداد تغییر" placeholder="تعداد">
                          <button type="button" class="cms-btn cms-btn--ghost cms-price-sheet__stock-btn" data-stock-add aria-label="افزودن" title="افزودن">+</button>
                          <button type="button" class="cms-btn cms-btn--ghost cms-price-sheet__stock-btn" data-stock-sub aria-label="کم کردن" title="کم کردن">−</button>
                        </div>
                      <?php else: ?>
                        <input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[<?= $categoryId ?>][<?= $index ?>][stock_qty]" value="<?= cms_h($stockValue) ?>" dir="ltr" inputmode="numeric">
                      <?php endif; ?>
                    </td>
                    <td class="cms-price-sheet__delete">
                      <div class="cms-price-sheet__delete-tools">
                        <input type="checkbox" name="frames[<?= $categoryId ?>][<?= $index ?>][delete]" value="1">
                        <button type="button" class="cms-price-sheet__trash" data-delete-row aria-label="حذف ردیف" title="حذف">
                          <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                            <path d="M10 11v6"></path>
                            <path d="M14 11v6"></path>
                            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
                          </svg>
                        </button>
                      </div>
                    </td>
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
    </form>
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
  var warehouseSheet = <?= $fromWarehouse ? 'true' : 'false' ?>;
  var stockMax = 999999;
  var persianDigits = {'0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹'};
  var digitChar = /[0-9۰-۹٠-٩]/;

  function normalizeDigits(text) {
    return String(text || '').replace(/[۰-۹٠-٩]/g, function (ch) {
      var map = {'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9','٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'};
      return map[ch] || ch;
    }).replace(/[٬،,\s\u00A0]/g, '').replace(/تومان$/u, '').trim();
  }

  function hasPersianDigits(text) {
    return /[۰-۹]/.test(String(text || ''));
  }

  function hasLatinDigits(text) {
    return /[0-9]/.test(String(text || ''));
  }

  function getDigitMode(input) {
    if (input && (input.dataset.digitMode === 'fa' || input.dataset.digitMode === 'en')) {
      return input.dataset.digitMode;
    }
    var value = input ? String(input.value || '') : '';
    if (hasPersianDigits(value)) {
      return 'fa';
    }
    if (hasLatinDigits(value)) {
      return 'en';
    }
    return 'fa';
  }

  function setDigitMode(input, mode) {
    if (!input) return;
    input.dataset.digitMode = mode === 'en' ? 'en' : 'fa';
  }

  function initDigitMode(input) {
    setDigitMode(input, getDigitMode(input));
  }

  function parseToman(raw) {
    var normalized = normalizeDigits(raw);
    if (!normalized || /ریال/u.test(raw || '')) return null;
    if (!/^\d+$/.test(normalized) || normalized.length > 12) return null;
    var amount = Number(normalized);
    return Number.isFinite(amount) ? amount : null;
  }

  function formatAmount(amount, mode) {
    var grouped = Math.max(0, Math.floor(amount)).toLocaleString('en-US');
    if (mode === 'fa') {
      grouped = grouped.replace(/,/g, '٬');
      return grouped.replace(/\d/g, function (d) { return persianDigits[d] || d; });
    }
    return grouped;
  }

  function countDigits(text) {
    var count = 0;
    String(text || '').split('').forEach(function (ch) {
      if (digitChar.test(ch)) count++;
    });
    return count;
  }

  function cursorAfterDigits(formatted, digitCount) {
    if (digitCount <= 0) return 0;
    var seen = 0;
    for (var i = 0; i < formatted.length; i++) {
      if (digitChar.test(formatted.charAt(i))) {
        seen++;
        if (seen >= digitCount) {
          return i + 1;
        }
      }
    }
    return formatted.length;
  }

  function normalizePriceInput(input) {
    if (!input) return;
    var mode = getDigitMode(input);
    setDigitMode(input, mode);
    var before = input.selectionStart;
    var digitsBefore = before === null ? countDigits(input.value) : countDigits(String(input.value || '').slice(0, before));
    var digits = normalizeDigits(input.value);
    input.value = digits === '' ? '' : formatAmount(parseInt(digits, 10), mode);
    if (before !== null && typeof input.setSelectionRange === 'function') {
      var pos = cursorAfterDigits(input.value, digitsBefore);
      try { input.setSelectionRange(pos, pos); } catch (e) {}
    }
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
          var packMode = getDigitMode(packPriceInput);
          setDigitMode(priceInput, packMode);
          priceInput.value = formatAmount(unitAmount, packMode);
        }
      }
      return;
    }
    var unit = parseToman(priceInput.value);
    if (unit === null) {
      packPriceInput.value = '';
      return;
    }
    var priceMode = getDigitMode(priceInput);
    setDigitMode(packPriceInput, priceMode);
    packPriceInput.value = formatAmount(unit * packSize, priceMode);
  }

  function parseStockCount(raw) {
    var digits = normalizeDigits(raw).replace(/\D/g, '');
    if (digits === '') return null;
    var amount = parseInt(digits, 10);
    return Number.isFinite(amount) ? amount : null;
  }

  function applyStockDelta(row, sign) {
    var stockInput = row.querySelector('[data-stock-input]');
    var deltaInput = row.querySelector('[data-stock-delta]');
    if (!stockInput || !deltaInput) return;
    var delta = parseStockCount(deltaInput.value);
    if (delta === null || delta <= 0) return;
    var current = parseStockCount(stockInput.value);
    if (current === null) current = 0;
    var next = current + (sign * delta);
    if (next < 0) next = 0;
    if (next > stockMax) next = stockMax;
    stockInput.value = String(next);
  }

  function bindStockAdjuster(row) {
    var addBtn = row.querySelector('[data-stock-add]');
    var subBtn = row.querySelector('[data-stock-sub]');
    if (addBtn) addBtn.addEventListener('click', function () { applyStockDelta(row, 1); });
    if (subBtn) subBtn.addEventListener('click', function () { applyStockDelta(row, -1); });
  }

  function stockCellHtml(categoryId, index) {
    if (!warehouseSheet) {
      return '<td class="cms-price-sheet__stock">' +
        '<input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[' + categoryId + '][' + index + '][stock_qty]" value="" dir="ltr" inputmode="numeric">' +
        '</td>';
    }
    return '<td class="cms-price-sheet__stock">' +
      '<div class="cms-price-sheet__stock-row">' +
        '<input class="cms-input cms-price-sheet__input cms-price-sheet__input--num cms-price-sheet__input--stock" name="frames[' + categoryId + '][' + index + '][stock_qty]" value="" dir="ltr" inputmode="numeric" data-stock-input>' +
        '<input class="cms-input cms-price-sheet__input cms-price-sheet__input--num cms-price-sheet__input--delta" type="text" inputmode="numeric" dir="ltr" data-stock-delta aria-label="تعداد تغییر" placeholder="تعداد">' +
        '<button type="button" class="cms-btn cms-btn--ghost cms-price-sheet__stock-btn" data-stock-add aria-label="افزودن" title="افزودن">+</button>' +
        '<button type="button" class="cms-btn cms-btn--ghost cms-price-sheet__stock-btn" data-stock-sub aria-label="کم کردن" title="کم کردن">−</button>' +
      '</div></td>';
  }

  function bindRow(row) {
    var priceInput = row.querySelector('[data-price-input]');
    var packInput = row.querySelector('[data-pack-input]');
    var packPriceInput = row.querySelector('[data-pack-price-input]');
    if (priceInput) {
      initDigitMode(priceInput);
      priceInput.addEventListener('input', function () {
        normalizePriceInput(priceInput);
        updatePackPrice(row, true);
      });
    }
    if (packPriceInput) {
      initDigitMode(packPriceInput);
      packPriceInput.addEventListener('input', function () {
        normalizePriceInput(packPriceInput);
        updatePackPrice(row, false);
      });
    }
    if (packInput) packInput.addEventListener('input', function () { updatePackPrice(row, true); });
    bindStockAdjuster(row);
    bindDeleteRow(row);
  }

  function rowInput(row, field) {
    return row.querySelector('input[name*="[' + field + ']"]');
  }

  function rowInputValue(row, field) {
    var el = rowInput(row, field);
    return el ? String(el.value || '') : '';
  }

  function snapshotRow(row) {
    var del = rowInput(row, 'delete');
    row.dataset.origVisual = rowInputValue(row, 'visual_id');
    row.dataset.origName = rowInputValue(row, 'name');
    row.dataset.origWarranty = rowInputValue(row, 'warranty_text');
    row.dataset.origPack = rowInputValue(row, 'pack_size');
    row.dataset.origPrice = rowInputValue(row, 'price_text');
    row.dataset.origStock = rowInputValue(row, 'stock_qty');
    row.dataset.origDelete = del && del.checked ? '1' : '0';
  }

  function isRowDirty(row) {
    var del = rowInput(row, 'delete');
    if (del && del.checked) return true;
    if (parseInt(rowInputValue(row, 'id'), 10) === 0) return true;
    return rowInputValue(row, 'visual_id') !== (row.dataset.origVisual || '')
      || rowInputValue(row, 'name') !== (row.dataset.origName || '')
      || rowInputValue(row, 'warranty_text') !== (row.dataset.origWarranty || '')
      || rowInputValue(row, 'pack_size') !== (row.dataset.origPack || '')
      || rowInputValue(row, 'price_text') !== (row.dataset.origPrice || '')
      || rowInputValue(row, 'stock_qty') !== (row.dataset.origStock || '');
  }

  function collectDirtyRows() {
    var out = [];
    document.querySelectorAll('.cms-price-sheet__rows tr').forEach(function (row) {
      if (!isRowDirty(row)) return;
      var tbody = row.closest('.cms-price-sheet__rows');
      var categoryId = tbody ? parseInt(tbody.getAttribute('data-category-id') || '0', 10) : 0;
      var del = rowInput(row, 'delete');
      out.push({
        categoryId: categoryId,
        visualId: rowInputValue(row, 'visual_id'),
        payload: {
          id: rowInputValue(row, 'id'),
          visual_id: rowInputValue(row, 'visual_id'),
          name: rowInputValue(row, 'name'),
          warranty_text: rowInputValue(row, 'warranty_text'),
          pack_size: rowInputValue(row, 'pack_size'),
          price_text: rowInputValue(row, 'price_text'),
          pack_price_text: rowInputValue(row, 'pack_price_text'),
          stock_qty: rowInputValue(row, 'stock_qty'),
          delete: del && del.checked ? '1' : ''
        }
      });
    });
    return out;
  }

  function setRowDeleteState(row, checked) {
    if (checked) {
      row.classList.add('cms-price-sheet__row--delete');
    } else {
      row.classList.remove('cms-price-sheet__row--delete');
    }
  }

  function bindDeleteRow(row) {
    var box = row.querySelector('input[name*="[delete]"]');
    var trash = row.querySelector('[data-delete-row]');
    if (!box) return;
    setRowDeleteState(row, box.checked);
    if (trash) {
      trash.addEventListener('click', function () {
        box.checked = true;
        setRowDeleteState(row, true);
      });
    }
    box.addEventListener('change', function () {
      setRowDeleteState(row, box.checked);
    });
  }

  document.querySelectorAll('.cms-price-sheet__rows tr').forEach(function (row) {
    bindRow(row);
    updatePackPrice(row, true);
    snapshotRow(row);
  });

  var highlightId = <?= json_encode($highlightVisualId, JSON_UNESCAPED_UNICODE) ?>;
  if (highlightId) {
    var highlightRow = null;
    document.querySelectorAll('.cms-price-sheet__rows tr[data-visual-id]').forEach(function (row) {
      if (row.getAttribute('data-visual-id') === highlightId) {
        highlightRow = row;
      }
    });
    if (highlightRow) {
      highlightRow.scrollIntoView({ block: 'center', behavior: 'smooth' });
      var highlightFrame = highlightRow.closest('.cms-price-sheet__frame');
      if (highlightFrame) {
        highlightFrame.classList.add('cms-price-sheet__frame--focus');
      }
      var codeInput = highlightRow.querySelector('input[name*="[visual_id]"]');
      if (codeInput) {
        window.setTimeout(function () { codeInput.focus({ preventScroll: true }); }, 400);
      }
    }
  }

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
        '<td class="cms-price-sheet__car"><span class="cms-price-sheet__readonly">—</span></td>' +
        '<td><input class="cms-input cms-price-sheet__input" name="frames[' + categoryId + '][' + index + '][warranty_text]" value=""></td>' +
        '<td><input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[' + categoryId + '][' + index + '][pack_size]" value="" dir="ltr" data-pack-input></td>' +
        '<td class="cms-price-sheet__price">' +
          '<input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[' + categoryId + '][' + index + '][price_text]" value="" dir="ltr" data-price-input>' +
        '</td>' +
        '<td class="cms-price-sheet__price">' +
          '<input class="cms-input cms-price-sheet__input cms-price-sheet__input--num" name="frames[' + categoryId + '][' + index + '][pack_price_text]" value="" dir="ltr" data-pack-price-input>' +
        '</td>' +
        stockCellHtml(categoryId, index) +
        '<td class="cms-price-sheet__delete"><div class="cms-price-sheet__delete-tools">' +
          '<input type="checkbox" name="frames[' + categoryId + '][' + index + '][delete]" value="1">' +
          '<button type="button" class="cms-price-sheet__trash" data-delete-row aria-label="حذف ردیف" title="حذف">' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
              '<polyline points="3 6 5 6 21 6"></polyline>' +
              '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>' +
              '<path d="M10 11v6"></path>' +
              '<path d="M14 11v6"></path>' +
              '<path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>' +
            '</svg>' +
          '</button></div></td>';
      tbody.appendChild(tr);
      bindRow(tr);
      snapshotRow(tr);
      var firstInput = tr.querySelector('input[name*="[visual_id]"]');
      if (firstInput) firstInput.focus();
    });
  });

  var form = document.getElementById('price-sheet-form');
  var saveErrorEl = document.getElementById('price-sheet-save-error');
  var saveModal = document.getElementById('price-sheet-save-modal');
  var saveModalTitle = document.getElementById('price-sheet-save-title');
  var saveNowEl = document.getElementById('price-sheet-save-now');
  var saveLogEl = document.getElementById('price-sheet-save-log');
  var saveProgressWrap = document.getElementById('price-sheet-save-progress-wrap');
  var saveProgressEl = document.getElementById('price-sheet-save-progress');
  var saveModalActions = document.getElementById('price-sheet-save-actions');
  var saveModalDismiss = document.getElementById('price-sheet-save-dismiss');
  var saveInFlight = false;
  var saveFailed = false;
  var saveSucceeded = false;
  var saveRedirectUrl = '';
  var saveWaitTimer = null;
  var saveWaitStarted = 0;

  function saveButtons() {
    return document.querySelectorAll('button[form="price-sheet-form"], #price-sheet-form button[type="submit"]');
  }

  function setSaveBusy(busy) {
    saveInFlight = busy;
    saveButtons().forEach(function (btn) {
      btn.disabled = busy;
    });
  }

  function faNum(value) {
    return String(value).replace(/\d/g, function (d) { return persianDigits[d] || d; });
  }

  function clockNow() {
    try {
      return new Date().toLocaleTimeString('fa-IR', { hour12: false });
    } catch (e) {
      return '';
    }
  }

  function formatBytes(n) {
    if (n < 1024) return faNum(n) + ' بایت';
    return faNum((n / 1024).toFixed(1)) + ' کیلوبایت';
  }

  function setSaveNow(text) {
    if (saveNowEl) saveNowEl.textContent = text;
    if (saveModalTitle && !saveFailed && !saveSucceeded) saveModalTitle.textContent = text;
  }

  function appendSaveLog(text, className) {
    if (!saveLogEl) return;
    var li = document.createElement('li');
    if (className) li.className = className;
    var time = document.createElement('time');
    time.textContent = clockNow();
    li.appendChild(time);
    li.appendChild(document.createTextNode(text));
    saveLogEl.appendChild(li);
    try { li.scrollIntoView({ block: 'nearest' }); } catch (e) {}
  }

  function stopSaveWaitTimer() {
    if (saveWaitTimer) {
      clearInterval(saveWaitTimer);
      saveWaitTimer = null;
    }
  }

  function startSaveWaitTimer() {
    stopSaveWaitTimer();
    saveWaitStarted = Date.now();
    function tick() {
      var sec = Math.max(0, Math.floor((Date.now() - saveWaitStarted) / 1000));
      setSaveNow('منتظر پاسخ سرور… ' + faNum(sec) + ' ثانیه');
    }
    tick();
    saveWaitTimer = setInterval(tick, 500);
  }

  function openSaveModal() {
    if (!saveModal) return;
    saveFailed = false;
    saveSucceeded = false;
    saveRedirectUrl = '';
    saveModal.hidden = false;
    document.body.style.overflow = 'hidden';
    if (saveModalActions) saveModalActions.hidden = true;
    if (saveLogEl) saveLogEl.textContent = '';
    if (saveProgressWrap) saveProgressWrap.hidden = true;
    if (saveProgressEl) {
      saveProgressEl.max = 1;
      saveProgressEl.value = 0;
    }
    if (saveModalTitle) {
      saveModalTitle.classList.remove('cms-ok');
      saveModalTitle.textContent = 'در حال ذخیره';
    }
    if (saveNowEl) saveNowEl.classList.remove('cms-ok');
    setSaveNow('در حال خواندن فرم');
  }

  function failSave(message) {
    stopSaveWaitTimer();
    saveFailed = true;
    saveSucceeded = false;
    setSaveBusy(false);
    if (saveModalTitle) {
      saveModalTitle.classList.remove('cms-ok');
      saveModalTitle.textContent = 'ذخیره انجام نشد';
    }
    if (saveNowEl) {
      saveNowEl.classList.remove('cms-ok');
      saveNowEl.textContent = message;
    }
    appendSaveLog(message);
    if (saveModalActions) saveModalActions.hidden = false;
    if (saveErrorEl) {
      saveErrorEl.hidden = false;
      saveErrorEl.textContent = message;
    }
  }

  function succeedSave(data) {
    stopSaveWaitTimer();
    saveFailed = false;
    saveSucceeded = true;
    saveRedirectUrl = (data && data.redirect) ? data.redirect : window.location.href;
    setSaveBusy(false);
    appendSaveLog(
      ((data && data.message) ? data.message : 'ذخیره شد') +
      (data && typeof data.deleted === 'number' && data.deleted > 0
        ? ' — حذف: ' + faNum(data.deleted)
        : '')
    );
    if (saveModalTitle) {
      saveModalTitle.textContent = 'save successful';
      saveModalTitle.classList.add('cms-ok');
    }
    if (saveNowEl) {
      saveNowEl.textContent = 'save successful';
      saveNowEl.classList.add('cms-ok');
    }
    if (saveModalActions) saveModalActions.hidden = false;
  }

  function hideSaveModal() {
    stopSaveWaitTimer();
    if (!saveModal) return;
    saveModal.hidden = true;
    saveFailed = false;
    saveSucceeded = false;
    saveRedirectUrl = '';
    document.body.style.overflow = '';
    if (saveModalTitle) saveModalTitle.classList.remove('cms-ok');
    if (saveNowEl) saveNowEl.classList.remove('cms-ok');
  }

  function finishSaveDialog() {
    if (saveInFlight) return;
    if (!saveFailed && !saveSucceeded) return;
    var shouldReload = saveSucceeded;
    var redirect = saveRedirectUrl || window.location.href;
    hideSaveModal();
    if (shouldReload) {
      window.location.assign(redirect);
    }
  }

  if (saveModalDismiss) {
    saveModalDismiss.addEventListener('click', function () {
      finishSaveDialog();
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      finishSaveDialog();
    }
  });

  function warehouseFlag() {
    return form && form.querySelector('[name="from_warehouse"]') ? '1' : '';
  }

  function postFormFields(fields) {
    var params = new URLSearchParams();
    Object.keys(fields).forEach(function (key) {
      if (fields[key] === '' || fields[key] === null || fields[key] === undefined) {
        if (key === 'delete') return;
      }
      params.set(key, String(fields[key]));
    });
    return fetch(window.location.href, {
      method: 'POST',
      body: params.toString(),
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then(function (res) {
      return res.text().then(function (raw) {
        var data = null;
        try {
          data = raw ? JSON.parse(raw) : null;
        } catch (e) {
          data = null;
        }
        return { res: res, data: data, raw: raw || '' };
      });
    });
  }

  function finishRowSave(saved, failed, deleted) {
    if (saved + deleted + failed === 0) {
      failSave('تغییری برای ذخیره نیست');
      return Promise.resolve();
    }
    var summary = {
      saved: saved,
      failed: failed,
      deleted: deleted,
      message: 'پیش‌نویس ذخیره شد — ' + faNum(saved) + ' ردیف',
      redirect: window.location.href
    };
    return postFormFields({
      action: 'save_draft_summary',
      ajax: '1',
      from_warehouse: warehouseFlag(),
      saved: saved,
      failed: failed,
      deleted: deleted
    }).then(function (result) {
      if (result.data && result.data.redirect) {
        summary.redirect = result.data.redirect;
      }
      if (result.data && result.data.message) {
        summary.message = result.data.message;
      }
    }).catch(function () {
      return null;
    }).then(function () {
      if (saved + deleted > 0) {
        if (failed > 0) {
          summary.message += ' — ناموفق: ' + faNum(failed);
        }
        succeedSave(summary);
        return;
      }
      failSave(failed > 0 ? 'هیچ ردیفی ذخیره نشد' : 'تغییری برای ذخیره نیست');
    });
  }

  function saveDirtyRows(rows) {
    var total = rows.length;
    var index = 0;
    var saved = 0;
    var failed = 0;
    var deleted = 0;
    if (saveProgressWrap) saveProgressWrap.hidden = false;
    if (saveProgressEl) {
      saveProgressEl.max = total;
      saveProgressEl.value = 0;
    }

    function next() {
      if (index >= total) {
        setSaveNow('در حال ثبت خلاصه ذخیره');
        return finishRowSave(saved, failed, deleted);
      }
      var item = rows[index];
      var n = index + 1;
      var codeLabel = item.visualId !== '' ? faNum(item.visualId) : '—';
      setSaveNow('در حال به‌روزرسانی کد ' + codeLabel + ' — ' + faNum(n) + ' از ' + faNum(total));
      var fields = {
        action: 'save_draft_row',
        ajax: '1',
        category_id: item.categoryId,
        from_warehouse: warehouseFlag(),
        id: item.payload.id,
        visual_id: item.payload.visual_id,
        name: item.payload.name,
        warranty_text: item.payload.warranty_text,
        pack_size: item.payload.pack_size,
        price_text: item.payload.price_text,
        pack_price_text: item.payload.pack_price_text,
        stock_qty: item.payload.stock_qty
      };
      if (item.payload.delete === '1') {
        fields.delete = '1';
      }
      return postFormFields(fields).then(function (result) {
        var data = result.data || {};
        var ok = !!data.ok;
        var visual = data.visual_id || item.visualId || '—';
        var message = data.message || data.error || (ok ? 'ذخیره شد' : 'ناموفق');
        appendSaveLog(faNum(visual) + ' — ' + message, ok ? 'is-ok' : 'is-error');
        if (ok) {
          if (data.action === 'deleted') deleted++;
          else if (data.action === 'saved') saved++;
        } else {
          failed++;
        }
        index++;
        if (saveProgressEl) saveProgressEl.value = index;
        return next();
      }).catch(function (err) {
        failed++;
        appendSaveLog(
          faNum(item.visualId || '—') + ' — ' + (err && err.message ? err.message : String(err || 'خطا')),
          'is-error'
        );
        index++;
        if (saveProgressEl) saveProgressEl.value = index;
        return next();
      });
    }

    return next();
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (saveInFlight) return;
      setSaveBusy(true);
      if (saveErrorEl) {
        saveErrorEl.hidden = true;
        saveErrorEl.textContent = '';
      }
      openSaveModal();

      var dirty = collectDirtyRows();
      appendSaveLog(
        'تغییرات: ' + faNum(dirty.length) + ' ردیف از ' +
        faNum(form.querySelectorAll('.cms-price-sheet__rows tr').length)
      );
      if (dirty.length === 0) {
        failSave('تغییری برای ذخیره نیست');
        return;
      }
      saveDirtyRows(dirty);
    });
  }
})();
</script>
<?php
cms_layout_end();
