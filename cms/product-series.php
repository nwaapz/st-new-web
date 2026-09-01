<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/product-car-models.php';
require_once __DIR__ . '/lib/product-categories.php';
require_once __DIR__ . '/lib/product-series-categories.php';

const SERIES_GALLERY_MAX = 12;

cms_require_login();
$pdo = cms_pdo();
cms_ensure_product_car_models_schema($pdo);
cms_ensure_product_categories_schema($pdo);

function product_series_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $visualCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'visual_id'")->fetchAll();
    if (count($visualCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');
    }
    $priceCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'price_text'")->fetchAll();
    if (count($priceCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN price_text VARCHAR(128) NULL AFTER description');
    }
    $detailCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'detail_lead_image'")->fetchAll();
    if (count($detailCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN detail_lead_image VARCHAR(512) NULL AFTER image');
    }
    $overrideCol = $pdo->query("SHOW COLUMNS FROM product_series LIKE 'image_setup_override'")->fetchAll();
    if (count($overrideCol) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD COLUMN image_setup_override VARCHAR(512) NULL AFTER detail_lead_image');
    }
    $visualIdx = $pdo->query("SHOW INDEX FROM product_series WHERE Key_name = 'uq_series_visual_id'")->fetchAll();
    if (count($visualIdx) === 0) {
        $pdo->exec('ALTER TABLE product_series ADD UNIQUE KEY uq_series_visual_id (visual_id)');
    }
    cms_series_ensure_categories_schema($pdo);
    $imagesTable = $pdo->query("SHOW TABLES LIKE 'product_series_images'")->fetchAll();
    if (count($imagesTable) === 0) {
        $pdo->exec(
            'CREATE TABLE product_series_images (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              series_id INT UNSIGNED NOT NULL,
              image VARCHAR(512) NOT NULL,
              alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
              sort_order INT NOT NULL DEFAULT 0,
              PRIMARY KEY (id),
              KEY idx_series_images_series (series_id),
              CONSTRAINT fk_series_image_series
                FOREIGN KEY (series_id) REFERENCES product_series (id)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    $ready = true;
}

function series_blank_gallery_slide(): array
{
    return ['image' => '', 'alt_text' => ''];
}

function series_load_gallery(PDO $pdo, int $seriesId): array
{
    product_series_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT image, alt_text, sort_order
         FROM product_series_images
         WHERE series_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$seriesId]);
    $slides = [];
    foreach ($stmt->fetchAll() as $row) {
        $slides[] = [
            'image' => (string) $row['image'],
            'alt_text' => (string) ($row['alt_text'] ?? ''),
        ];
    }
    return $slides;
}

function series_collect_gallery_from_post(int $count): array
{
    $slides = [];
    for ($i = 0; $i < $count; $i++) {
        $image = cms_handle_optional_upload(
            'gallery_image_' . $i,
            (string) ($_POST['gallery_image_' . $i] ?? '')
        );
        $alt = trim((string) ($_POST['gallery_alt_' . $i] ?? ''));
        if ($image === '') {
            continue;
        }
        $slides[] = [
            'image' => $image,
            'alt_text' => $alt,
        ];
    }
    return $slides;
}

function series_replace_gallery(PDO $pdo, int $seriesId, array $slides): void
{
    product_series_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM product_series_images WHERE series_id = ?')->execute([$seriesId]);
    if ($slides === []) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO product_series_images (series_id, image, alt_text, sort_order) VALUES (?, ?, ?, ?)'
    );
    foreach ($slides as $index => $slide) {
        $stmt->execute([
            $seriesId,
            $slide['image'],
            $slide['alt_text'],
            $index,
        ]);
    }
}

/** @return list<array{value:string,label:string}> */
function series_image_picker_options(string $coverImage, array $gallery): array
{
    $options = [];
    if ($coverImage !== '') {
        $options[] = ['value' => $coverImage, 'label' => 'تصویر اصلی'];
    }
    foreach ($gallery as $index => $slide) {
        $image = trim((string) ($slide['image'] ?? ''));
        if ($image === '') {
            continue;
        }
        $options[] = [
            'value' => $image,
            'label' => 'گالری ' . ($index + 1),
        ];
    }
    return $options;
}

product_series_ensure_schema($pdo);

$edit = null;
$showForm = isset($_GET['new']) || isset($_GET['edit']);
$selectedProductIds = [];
$selectedCategoryIds = [];
$gallery = [];

$categories = $pdo->query(
    'SELECT id, name FROM categories WHERE published = 1 ORDER BY sort_order ASC, name ASC'
)->fetchAll();

$modelNamesSql = cms_product_model_names_sql('p');
$categoryNamesSql = cms_product_category_names_sql('p');
$allProducts = $pdo->query(
    "SELECT p.id, p.name, p.visual_id, {$categoryNamesSql} AS category_name, {$modelNamesSql} AS model_name
     FROM products p
     ORDER BY p.sort_order ASC, p.name ASC"
)->fetchAll();

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM product_series WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('مورد یافت نشد', 'error');
        cms_redirect('product-series.php');
    }
    $showForm = true;
    $idsStmt = $pdo->prepare(
        'SELECT product_id FROM product_series_items WHERE series_id = ? ORDER BY sort_order ASC, product_id ASC'
    );
    $idsStmt->execute([(int) $edit['id']]);
    $selectedProductIds = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));
    $selectedCategoryIds = cms_series_load_category_ids($pdo, (int) $edit['id']);
    $gallery = series_load_gallery($pdo, (int) $edit['id']);
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM product_series WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('سری حذف شد');
    cms_redirect('product-series.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? 'save'));
    $galleryCount = max(0, (int) ($_POST['gallery_count'] ?? 0));
    $collectedGallery = series_collect_gallery_from_post($galleryCount);
    try {
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if ($slug === '') {
            $slug = cms_slugify($name);
        }
        $visualId = trim((string) ($_POST['visual_id'] ?? ''));
        $visualId = $visualId !== '' ? $visualId : null;
        $priceText = trim((string) ($_POST['price_text'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $image = cms_handle_optional_upload('image', (string) ($_POST['image'] ?? ''));
        $detailLeadImage = trim((string) ($_POST['detail_lead_image'] ?? ''));
        $imageSetupOverride = trim((string) ($_POST['image_setup_override'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;
        $productIds = array_values(array_unique(array_map(
            'intval',
            (array) ($_POST['product_ids'] ?? [])
        )));
        $productIds = array_values(array_filter($productIds, static fn (int $pid): bool => $pid > 0));
        $categoryIds = [];
        $cat1 = (int) ($_POST['category_id_1'] ?? 0);
        $cat2 = (int) ($_POST['category_id_2'] ?? 0);
        if ($cat1 > 0) {
            $categoryIds[] = $cat1;
        }
        if ($cat2 > 0) {
            $categoryIds[] = $cat2;
        }

        if ($name === '') {
            throw new RuntimeException('نام سری الزامی است');
        }

        $slugCheck = $pdo->prepare('SELECT id FROM product_series WHERE slug = ? AND id <> ? LIMIT 1');
        $slugCheck->execute([$slug, $id]);
        if ($slugCheck->fetch()) {
            throw new RuntimeException('این اسلاگ قبلاً استفاده شده است');
        }
        if ($visualId !== null) {
            $visualCheck = $pdo->prepare('SELECT id FROM product_series WHERE visual_id = ? AND id <> ? LIMIT 1');
            $visualCheck->execute([$visualId, $id]);
            if ($visualCheck->fetch()) {
                throw new RuntimeException('این شناسه نمایشی قبلاً استفاده شده است');
            }
        }

        if ($action === 'add_gallery') {
            if (count($collectedGallery) >= SERIES_GALLERY_MAX) {
                throw new RuntimeException('حداکثر ' . SERIES_GALLERY_MAX . ' تصویر گالری مجاز است');
            }
            $collectedGallery[] = series_blank_gallery_slide();
        }

        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE product_series SET name=?, slug=?, visual_id=?, description=?, price_text=?, image=?,
                 detail_lead_image=?, image_setup_override=?, sort_order=?, published=? WHERE id=?'
            );
            $stmt->execute([
                $name,
                $slug,
                $visualId,
                $description !== '' ? $description : null,
                $priceText !== '' ? $priceText : null,
                $image !== '' ? $image : null,
                $detailLeadImage !== '' ? $detailLeadImage : null,
                $imageSetupOverride !== '' ? $imageSetupOverride : null,
                $sortOrder,
                $published,
                $id,
            ]);
            $seriesId = $id;
            cms_flash('سری به‌روز شد');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO product_series (name, slug, visual_id, description, price_text, image,
                 detail_lead_image, image_setup_override, sort_order, published)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $name,
                $slug,
                $visualId,
                $description !== '' ? $description : null,
                $priceText !== '' ? $priceText : null,
                $image !== '' ? $image : null,
                $detailLeadImage !== '' ? $detailLeadImage : null,
                $imageSetupOverride !== '' ? $imageSetupOverride : null,
                $sortOrder,
                $published,
            ]);
            $seriesId = (int) $pdo->lastInsertId();
            cms_flash('سری اضافه شد');
        }

        cms_series_save_category_ids($pdo, $seriesId, $categoryIds);

        $pdo->prepare('DELETE FROM product_series_items WHERE series_id = ?')->execute([$seriesId]);
        if ($productIds !== []) {
            $insert = $pdo->prepare(
                'INSERT INTO product_series_items (series_id, product_id, sort_order) VALUES (?,?,?)'
            );
            foreach ($productIds as $index => $productId) {
                $insert->execute([$seriesId, $productId, $index]);
            }
        }

        $persistGallery = array_values(array_filter(
            $collectedGallery,
            static fn (array $s): bool => ($s['image'] ?? '') !== ''
        ));
        if ($action === 'add_gallery') {
            series_replace_gallery($pdo, $seriesId, $persistGallery);
            $pdo->commit();
            cms_flash('تصویر گالری اضافه شد — پس از انتخاب تصویر ذخیره کنید');
            cms_redirect('product-series.php?edit=' . $seriesId . '&gallery_extra=1');
        }

        series_replace_gallery($pdo, $seriesId, $persistGallery);
        $pdo->commit();
        cms_redirect('product-series.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'product-series.php?edit=' . $id : 'product-series.php?new=1');
    }
}

if ($showForm && isset($_GET['gallery_extra']) && $edit) {
    $gallery = series_load_gallery($pdo, (int) $edit['id']);
    $gallery[] = series_blank_gallery_slide();
}

$items = $pdo->query(
    'SELECT s.*,
            (SELECT COUNT(*) FROM product_series_items i WHERE i.series_id = s.id) AS product_count
     FROM product_series s
     ORDER BY s.sort_order ASC, s.name ASC'
)->fetchAll();

$imagePickerOptions = series_image_picker_options(
    (string) ($edit['image'] ?? ''),
    $gallery
);
$selectedDetailLead = (string) ($edit['detail_lead_image'] ?? '');

cms_layout_start('سری محصولات', cms_current_username(), 'shop');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">سری محصولات</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">هر سری = یک کیت در فروشگاه با شناسه نمایشی؛ قطعات انتخاب‌شده در صفحه جزئیات سری نمایش داده می‌شوند</p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="product-series.php?new=1">افزودن سری</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2><?= $edit ? 'ویرایش سری' : 'سری جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <input type="hidden" name="action" id="series-form-action" value="save">

  <p class="cms-muted" style="margin:0 0 .75rem;font-size:.85rem">دستهٔ اول و دوم: دسته‌های سطح محصول (حداکثر ۲) — همانند محصولات عادی.</p>
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">دسته اول (الزامی)</span>
      <select class="cms-select" name="category_id_1" required>
        <option value="">انتخاب…</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) ($selectedCategoryIds[0] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
            <?= cms_h($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="cms-field"><span class="cms-label">دسته دوم (اختیاری)</span>
      <select class="cms-select" name="category_id_2">
        <option value="">—</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) ($selectedCategoryIds[1] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
            <?= cms_h($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">نام سری</span>
      <input class="cms-input" name="name" required value="<?= cms_h($edit['name'] ?? '') ?>">
    </label>
    <label class="cms-field"><span class="cms-label">اسلاگ</span>
      <input class="cms-input" name="slug" dir="ltr" value="<?= cms_h($edit['slug'] ?? '') ?>">
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">شناسه نمایشی</span>
    <input class="cms-input" name="visual_id" dir="ltr" value="<?= cms_h($edit['visual_id'] ?? '') ?>" placeholder="KIT-1001">
    <span class="cms-muted" style="display:block;margin-top:.35rem;font-size:.85rem">کد یکتا برای نمایش در کارت فروشگاه و جستجو</span>
  </label>
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">قیمت (متن نمایشی)</span>
      <input class="cms-input" name="price_text" value="<?= cms_h($edit['price_text'] ?? '') ?>" placeholder="۱٬۲۵۰٬۰۰۰ تومان">
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">توضیحات</span>
    <textarea class="cms-textarea" name="description"><?= cms_h($edit['description'] ?? '') ?></textarea>
  </label>

  <h3 style="margin:1.25rem 0 .5rem;font-size:1rem">تنظیم تصاویر</h3>
  <label class="cms-field"><span class="cms-label">تنظیم تصاویر (جایگزین)</span>
    <input class="cms-input" name="image_setup_override" dir="ltr"
      value="<?= cms_h($edit['image_setup_override'] ?? '') ?>"
      placeholder="RAVI9750.png#st-web.png#app-image.png">
    <span class="cms-muted" style="display:block;margin-top:.35rem;font-size:.85rem">نام فایل‌ها را با # جدا کنید. اولین تصویر = تصویر اصلی و اسلاید اول؛ بقیه به ترتیب در اسلایدر.</span>
  </label>

  <?php cms_image_field('image', 'تصویر اصلی / فروشگاه (پوشش)', (string) ($edit['image'] ?? '')); ?>

  <h3 style="margin:1.25rem 0 .5rem;font-size:1rem">گالری تصاویر</h3>
  <p class="cms-muted" style="margin:0 0 .75rem">تصاویر اسلایدر صفحه جزئیات و پنل صفحه اصلی. در صورت پر بودن «تنظیم تصاویر (جایگزین)»، این بخش نادیده گرفته می‌شود.</p>
  <input type="hidden" name="gallery_count" value="<?= count($gallery) ?>">
  <?php if ($gallery === []): ?>
    <p class="cms-muted">هنوز تصویری در گالری نیست.</p>
  <?php else: ?>
    <?php foreach ($gallery as $gi => $slide): ?>
      <div class="cms-panel" style="margin-bottom:.75rem;padding:1rem">
        <strong style="display:block;margin-bottom:.5rem">اسلاید <?= (int) ($gi + 1) ?></strong>
        <?php cms_image_field('gallery_image_' . $gi, 'تصویر', (string) ($slide['image'] ?? '')); ?>
        <label class="cms-field"><span class="cms-label">متن جایگزین</span>
          <input class="cms-input" name="gallery_alt_<?= (int) $gi ?>" value="<?= cms_h($slide['alt_text'] ?? '') ?>">
        </label>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if (count($gallery) < SERIES_GALLERY_MAX): ?>
    <button class="cms-btn cms-btn--secondary" type="submit"
      onclick="document.getElementById('series-form-action').value='add_gallery'">
      افزودن تصویر گالری
    </button>
  <?php endif; ?>

  <h3 style="margin:1.25rem 0 .5rem;font-size:1rem">نمایش در اسلایدر</h3>
  <label class="cms-field"><span class="cms-label">اسلاید اول (جزئیات)</span>
    <select class="cms-select" name="detail_lead_image">
      <option value="">— پیش‌فرض (تصویر اصلی) —</option>
      <?php foreach ($imagePickerOptions as $opt): ?>
        <option value="<?= cms_h($opt['value']) ?>" <?= $selectedDetailLead === $opt['value'] ? 'selected' : '' ?>>
          <?= cms_h($opt['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="cms-field"><span class="cms-label">ترتیب</span>
    <input class="cms-input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
  </label>
  <label class="cms-check">
    <input type="checkbox" name="published" <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
    منتشر شده
  </label>

  <fieldset class="cms-field" style="border:0;padding:0;margin:1rem 0 0">
    <legend class="cms-label" style="padding:0;margin-bottom:.5rem">قطعات این سری</legend>
    <p class="cms-muted" style="margin:0 0 .5rem">محصولاتی که در صفحه جزئیات سری به‌عنوان قطعات کیت نمایش داده می‌شوند.</p>
    <?php if ($allProducts === []): ?>
      <p class="cms-empty" style="margin:0">هنوز محصولی ثبت نشده. ابتدا از <a href="products.php">محصولات</a> اضافه کنید.</p>
    <?php else: ?>
      <div class="cms-check-list-filter" data-cms-check-list-filter>
        <input
          type="search"
          class="cms-input cms-check-list-filter__input"
          placeholder="جستجو با نام یا شناسه نمایشی (مثلاً ST-2041)…"
          autocomplete="off"
          aria-label="جستجو محصول"
          dir="ltr"
        >
        <p class="cms-check-list-filter__empty cms-muted" hidden>موردی یافت نشد</p>
        <div class="cms-check-list">
          <?php foreach ($allProducts as $product): ?>
            <?php
            $pid = (int) $product['id'];
            $visualId = trim((string) ($product['visual_id'] ?? ''));
            $searchHaystack = trim(
                $visualId
                . ' '
                . (string) ($product['name'] ?? '')
                . ' '
                . (string) ($product['category_name'] ?? '')
                . ' '
                . (string) ($product['model_name'] ?? '')
            );
            ?>
            <label
              class="cms-check cms-check-list__item"
              data-cms-check-search="<?= cms_h($searchHaystack) ?>"
            >
              <input type="checkbox" name="product_ids[]" value="<?= $pid ?>" <?= in_array($pid, $selectedProductIds, true) ? 'checked' : '' ?>>
              <span>
                <?php if ($visualId !== ''): ?>
                  <span dir="ltr"><?= cms_h($visualId) ?></span>
                  <span class="cms-muted"> — </span>
                <?php endif; ?>
                <?= cms_h($product['name']) ?>
                <span class="cms-muted"> — <?= cms_h($product['category_name'] . ' / ' . $product['model_name']) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </fieldset>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره</button>
    <a class="cms-btn cms-btn--secondary" href="product-series.php">بازگشت به لیست</a>
  </div>
</form>
<?php else: ?>
<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">هنوز سری ثبت نشده. <a href="product-series.php?new=1">اولین سری را اضافه کنید</a>.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead><tr><th>سری</th><th>شناسه</th><th>اسلاگ</th><th>قطعات</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><div class="cms-list-name"><?php cms_list_thumb($item['image'] ?? null); ?><span><?= cms_h($item['name']) ?></span></div></td>
        <td dir="ltr"><?= cms_h($item['visual_id'] ?? '') ?></td>
        <td dir="ltr"><?= cms_h($item['slug']) ?></td>
        <td><?= (int) $item['product_count'] ?></td>
        <td><?= (int) $item['published'] ? 'فعال' : 'پیش‌نویس' ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0">
            <a class="cms-btn cms-btn--secondary" href="product-series.php?edit=<?= (int) $item['id'] ?>">ویرایش</a>
            <a class="cms-btn cms-btn--ghost" href="product-series.php?delete=<?= (int) $item['id'] ?>" onclick="return confirm('حذف؟')">حذف</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php cms_layout_end(); ?>
