<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/car-model-factories.php';
require_once __DIR__ . '/lib/product-car-models.php';

cms_require_login();
$pdo = cms_pdo();
cms_ensure_car_model_factories_schema($pdo);
cms_ensure_product_car_models_schema($pdo);

const PRODUCT_GALLERY_MAX = 12;

function product_ensure_detail_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    foreach (['dim_length', 'dim_width', 'dim_height', 'dim_weight'] as $col) {
        $exists = $pdo->query('SHOW COLUMNS FROM products LIKE ' . $pdo->quote($col))->fetchAll();
        if (count($exists) === 0) {
            $pdo->exec("ALTER TABLE products ADD COLUMN {$col} VARCHAR(64) NULL");
        }
    }
    $packExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'pack_size'")->fetchAll();
    if (count($packExists) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN pack_size INT UNSIGNED NULL AFTER price_text');
    }
    foreach (
        [
            'video_path' => 'VARCHAR(512) NULL AFTER image',
            'video_poster' => 'VARCHAR(512) NULL AFTER video_path',
            'detail_lead_image' => 'VARCHAR(512) NULL AFTER video_poster',
            'shop_display_image' => 'VARCHAR(512) NULL AFTER detail_lead_image',
            'video_path_low' => 'VARCHAR(512) NULL AFTER video_poster',
        ] as $col => $definition
    ) {
        $exists = $pdo->query('SHOW COLUMNS FROM products LIKE ' . $pdo->quote($col))->fetchAll();
        if (count($exists) === 0) {
            $pdo->exec("ALTER TABLE products ADD COLUMN {$col} {$definition}");
        }
    }
    $visualExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'visual_id'")->fetchAll();
    if (count($visualExists) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN visual_id VARCHAR(64) NULL AFTER slug');
    }
    $visualIdx = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'uq_prod_visual_id'")->fetchAll();
    if (count($visualIdx) === 0) {
        $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_prod_visual_id (visual_id)');
    }
    $skipFrameExists = $pdo->query("SHOW COLUMNS FROM products LIKE 'skip_image_auto_frame'")->fetchAll();
    if (count($skipFrameExists) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN skip_image_auto_frame TINYINT(1) NOT NULL DEFAULT 0 AFTER shop_display_image');
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_images (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          product_id INT UNSIGNED NOT NULL,
          image VARCHAR(512) NOT NULL,
          alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          KEY idx_product_images_product (product_id),
          CONSTRAINT fk_product_image_product
            FOREIGN KEY (product_id) REFERENCES products (id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

function product_blank_gallery_slide(): array
{
    return ['image' => '', 'alt_text' => ''];
}

function product_load_gallery(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare(
        'SELECT image, alt_text, sort_order
         FROM product_images
         WHERE product_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$productId]);
    $slides = [];
    foreach ($stmt->fetchAll() as $row) {
        $slides[] = [
            'image' => (string) $row['image'],
            'alt_text' => (string) ($row['alt_text'] ?? ''),
        ];
    }
    return $slides;
}

function product_collect_gallery_from_post(int $count, array $uploadOptions = []): array
{
    $slides = [];
    for ($i = 0; $i < $count; $i++) {
        $image = cms_handle_optional_upload(
            'gallery_image_' . $i,
            (string) ($_POST['gallery_image_' . $i] ?? ''),
            $uploadOptions
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

function product_replace_gallery(PDO $pdo, int $productId, array $slides): void
{
    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    if ($slides === []) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO product_images (product_id, image, alt_text, sort_order) VALUES (?, ?, ?, ?)'
    );
    foreach ($slides as $index => $slide) {
        $stmt->execute([
            $productId,
            $slide['image'],
            $slide['alt_text'],
            $index,
        ]);
    }
}

function product_normalize_image_picker(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || $value === '__cover__') {
        return null;
    }
    return $value;
}

/** @return list<array{value: string, label: string}> */
function product_image_picker_options(string $coverImage, array $gallery): array
{
    $options = [
        ['value' => '', 'label' => 'تصویر اصلی (پیش‌فرض)'],
    ];
    $seen = [''];
    if ($coverImage !== '') {
        $options[] = [
            'value' => $coverImage,
            'label' => 'تصویر اصلی: ' . basename($coverImage),
        ];
        $seen[] = $coverImage;
    }
    foreach ($gallery as $index => $slide) {
        $path = trim((string) ($slide['image'] ?? ''));
        if ($path === '' || in_array($path, $seen, true)) {
            continue;
        }
        $seen[] = $path;
        $options[] = [
            'value' => $path,
            'label' => 'گالری ' . ($index + 1) . ': ' . basename($path),
        ];
    }
    return $options;
}

function product_list_thumb_image(array $item): ?string
{
    $shop = trim((string) ($item['shop_display_image'] ?? ''));
    if ($shop !== '') {
        return $shop;
    }
    $cover = trim((string) ($item['image'] ?? ''));
    return $cover !== '' ? $cover : null;
}

product_ensure_detail_schema($pdo);

$edit = null;
$gallery = [];
$selectedCarModelIds = [];
$showForm = isset($_GET['new']) || isset($_GET['edit']);

$categories = $pdo->query(
    'SELECT id, name FROM categories ORDER BY sort_order ASC, name ASC'
)->fetchAll();

$factoryNamesSql = cms_car_model_factory_names_sql('m');
$models = $pdo->query(
    "SELECT m.id, m.name, {$factoryNamesSql} AS factory_name
     FROM car_models m
     ORDER BY m.sort_order ASC, m.name ASC"
)->fetchAll();

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('مورد یافت نشد', 'error');
        cms_redirect('products.php');
    }
    $showForm = true;
    $gallery = product_load_gallery($pdo, (int) $edit['id']);
    $selectedCarModelIds = cms_product_load_car_model_ids($pdo, (int) $edit['id']);
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('محصول حذف شد');
    cms_redirect('products.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $carModelIds = isset($_POST['car_model_ids']) && is_array($_POST['car_model_ids'])
            ? $_POST['car_model_ids']
            : [];
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if ($slug === '') {
            $slug = cms_slugify($name);
        }
        $visualId = trim((string) ($_POST['visual_id'] ?? ''));
        $visualId = $visualId !== '' ? $visualId : null;
        $description = trim((string) ($_POST['description'] ?? ''));
        $priceText = trim((string) ($_POST['price_text'] ?? ''));
        $packSizeRaw = trim((string) ($_POST['pack_size'] ?? ''));
        $packSize = $packSizeRaw === '' ? null : max(0, (int) $packSizeRaw);
        if ($packSize !== null && $packSize === 0) {
            $packSize = null;
        }
        $banner = (string) ($_POST['banner'] ?? 'none');
        if (!in_array($banner, ['none', 'new', 'off'], true)) {
            $banner = 'none';
        }
        $skipImageAutoFrame = isset($_POST['skip_image_auto_frame']) ? 1 : 0;
        $imageUploadOptions = ['auto_frame' => $skipImageAutoFrame === 0];
        $image = cms_handle_optional_upload(
            'image',
            (string) ($_POST['image'] ?? ''),
            $imageUploadOptions
        );
        $videoPath = cms_handle_optional_video_upload(
            'video_path',
            (string) ($_POST['video_path'] ?? ''),
            'products/videos'
        );
        $videoPathLow = cms_handle_optional_video_upload(
            'video_path_low',
            (string) ($_POST['video_path_low'] ?? ''),
            'products/videos'
        );
        $detailLeadImage = product_normalize_image_picker((string) ($_POST['detail_lead_image'] ?? ''));
        $shopDisplayImage = product_normalize_image_picker((string) ($_POST['shop_display_image'] ?? ''));
        $videoPoster = product_normalize_image_picker((string) ($_POST['video_poster'] ?? ''));
        $dimLength = trim((string) ($_POST['dim_length'] ?? ''));
        $dimWidth = trim((string) ($_POST['dim_width'] ?? ''));
        $dimHeight = trim((string) ($_POST['dim_height'] ?? ''));
        $dimWeight = trim((string) ($_POST['dim_weight'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;
        $galleryCount = max(0, (int) ($_POST['gallery_count'] ?? 0));
        if ($galleryCount > PRODUCT_GALLERY_MAX) {
            $galleryCount = PRODUCT_GALLERY_MAX;
        }
        $collectedGallery = product_collect_gallery_from_post($galleryCount, $imageUploadOptions);

        if ($categoryId <= 0 || $name === '') {
            throw new RuntimeException('دسته محصول و نام الزامی است');
        }
        $validatedCarModelIds = [];
        foreach ($carModelIds as $carModelId) {
            $carModelId = (int) $carModelId;
            if ($carModelId > 0 && !in_array($carModelId, $validatedCarModelIds, true)) {
                $validatedCarModelIds[] = $carModelId;
            }
        }
        if ($validatedCarModelIds === []) {
            throw new RuntimeException('حداقل یک مدل خودرو الزامی است');
        }

        // Ensure slug uniqueness globally
        $slugCheck = $pdo->prepare('SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1');
        $slugCheck->execute([$slug, $id]);
        if ($slugCheck->fetch()) {
            throw new RuntimeException('این اسلاگ قبلاً استفاده شده است');
        }
        if ($visualId !== null) {
            $visualCheck = $pdo->prepare('SELECT id FROM products WHERE visual_id = ? AND id <> ? LIMIT 1');
            $visualCheck->execute([$visualId, $id]);
            if ($visualCheck->fetch()) {
                throw new RuntimeException('این شناسه نمایشی قبلاً استفاده شده است');
            }
        }

        if ($action === 'add_gallery') {
            if (count($collectedGallery) >= PRODUCT_GALLERY_MAX) {
                throw new RuntimeException('حداکثر ' . PRODUCT_GALLERY_MAX . ' تصویر گالری مجاز است');
            }
            $collectedGallery[] = product_blank_gallery_slide();
        }

        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE products SET category_id=?, name=?, slug=?, visual_id=?, description=?, price_text=?, pack_size=?, banner=?, image=?,
                 video_path=?, video_path_low=?, video_poster=?, detail_lead_image=?, shop_display_image=?, skip_image_auto_frame=?,
                 dim_length=?, dim_width=?, dim_height=?, dim_weight=?, sort_order=?, published=? WHERE id=?'
            );
            $stmt->execute([
                $categoryId, $name, $slug, $visualId,
                $description !== '' ? $description : null,
                $priceText !== '' ? $priceText : null,
                $packSize,
                $banner,
                $image !== '' ? $image : null,
                $videoPath !== '' ? $videoPath : null,
                $videoPathLow !== '' ? $videoPathLow : null,
                $videoPoster,
                $detailLeadImage,
                $shopDisplayImage,
                $skipImageAutoFrame,
                $dimLength !== '' ? $dimLength : null,
                $dimWidth !== '' ? $dimWidth : null,
                $dimHeight !== '' ? $dimHeight : null,
                $dimWeight !== '' ? $dimWeight : null,
                $sortOrder, $published, $id,
            ]);
            $productId = $id;
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO products (category_id, name, slug, visual_id, description, price_text, pack_size, banner, image,
                 video_path, video_path_low, video_poster, detail_lead_image, shop_display_image, skip_image_auto_frame,
                 dim_length, dim_width, dim_height, dim_weight, sort_order, published)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $categoryId, $name, $slug, $visualId,
                $description !== '' ? $description : null,
                $priceText !== '' ? $priceText : null,
                $packSize,
                $banner,
                $image !== '' ? $image : null,
                $videoPath !== '' ? $videoPath : null,
                $videoPathLow !== '' ? $videoPathLow : null,
                $videoPoster,
                $detailLeadImage,
                $shopDisplayImage,
                $skipImageAutoFrame,
                $dimLength !== '' ? $dimLength : null,
                $dimWidth !== '' ? $dimWidth : null,
                $dimHeight !== '' ? $dimHeight : null,
                $dimWeight !== '' ? $dimWeight : null,
                $sortOrder, $published,
            ]);
            $productId = (int) $pdo->lastInsertId();
        }

        cms_product_save_car_model_ids($pdo, $productId, $validatedCarModelIds);

        $persistGallery = array_values(array_filter(
            $collectedGallery,
            static fn(array $s): bool => ($s['image'] ?? '') !== ''
        ));
        if ($action === 'add_gallery') {
            // Keep empty slot for the new blank slide in redirect UI by re-saving filled only,
            // then redirect with edit so blank is appended in GET... Actually we need blanks in POST redisplay.
            // Save only filled images; blank added after load on redirect via session flash of count.
            product_replace_gallery($pdo, $productId, $persistGallery);
            $pdo->commit();
            cms_flash('تصویر گالری اضافه شد — پس از انتخاب تصویر ذخیره کنید');
            cms_redirect('products.php?edit=' . $productId . '&gallery_extra=1');
        }

        product_replace_gallery($pdo, $productId, $persistGallery);
        $pdo->commit();

        cms_flash($id > 0 ? 'محصول به‌روز شد' : 'محصول اضافه شد');
        cms_redirect('products.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'products.php?edit=' . $id : 'products.php?new=1');
    }
}

if ($showForm && isset($_GET['gallery_extra']) && $edit) {
    $gallery = product_load_gallery($pdo, (int) $edit['id']);
    $gallery[] = product_blank_gallery_slide();
}

$productModelNamesSql = cms_product_model_names_sql('p');
$productFactoryNamesSql = cms_product_factory_names_sql('p');
$items = $pdo->query(
    "SELECT p.*, c.name AS category_name, {$productModelNamesSql} AS model_name, {$productFactoryNamesSql} AS factory_name
     FROM products p
     JOIN categories c ON c.id = p.category_id
     ORDER BY p.sort_order ASC, p.name ASC"
)->fetchAll();

$bannerLabels = ['none' => 'بدون بنر', 'new' => 'NEW', 'off' => 'OFF'];

$imagePickerOptions = product_image_picker_options(
    (string) ($edit['image'] ?? ''),
    $gallery
);
$selectedDetailLead = (string) ($edit['detail_lead_image'] ?? '');
$selectedShopImage = (string) ($edit['shop_display_image'] ?? '');
$selectedVideoPoster = (string) ($edit['video_poster'] ?? '');

cms_layout_start('محصولات', cms_current_username(), 'shop');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">محصولات</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">هر محصول: یک یا چند مدل خودرو + دسته محصول</p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="products.php?new=1">افزودن محصول</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2><?= $edit ? 'ویرایش محصول' : 'محصول جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <input type="hidden" name="action" id="product-form-action" value="save">

  <fieldset class="cms-field" style="border:0;padding:0;margin:0 0 1rem">
    <legend class="cms-label" style="padding:0;margin-bottom:.5rem">مدل‌های خودرو (کارخانه / مدل)</legend>
    <?php if ($models === []): ?>
      <p class="cms-muted" style="margin:0">هنوز مدلی ثبت نشده. ابتدا از <a href="car-models.php">مدل‌ها</a> اضافه کنید.</p>
    <?php else: ?>
      <div class="cms-check-list-filter" data-cms-check-list-filter>
        <input
          type="search"
          class="cms-input cms-check-list-filter__input"
          placeholder="جستجو کارخانه یا مدل…"
          autocomplete="off"
          aria-label="جستجو مدل خودرو"
        >
        <p class="cms-check-list-filter__empty cms-muted" hidden>موردی یافت نشد</p>
        <div class="cms-check-list">
          <?php foreach ($models as $m): ?>
            <?php
            $mid = (int) $m['id'];
            $searchHaystack = trim((string) (($m['factory_name'] ?? '') . ' ' . ($m['name'] ?? '')));
            ?>
            <label
              class="cms-check cms-check-list__item"
              data-cms-check-search="<?= cms_h($searchHaystack) ?>"
            >
              <input type="checkbox" name="car_model_ids[]" value="<?= $mid ?>" <?= in_array($mid, $selectedCarModelIds, true) ? 'checked' : '' ?>>
              <span><?= cms_h(($m['factory_name'] ?? '') . ' / ' . $m['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </fieldset>

  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">دسته محصول</span>
      <select class="cms-select" name="category_id" required>
        <option value="">انتخاب…</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) ($edit['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
            <?= cms_h($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">نام محصول</span>
      <input class="cms-input" name="name" required value="<?= cms_h($edit['name'] ?? '') ?>">
    </label>
    <label class="cms-field"><span class="cms-label">اسلاگ</span>
      <input class="cms-input" name="slug" dir="ltr" value="<?= cms_h($edit['slug'] ?? '') ?>">
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">شناسه نمایشی</span>
    <input class="cms-input" name="visual_id" dir="ltr" value="<?= cms_h($edit['visual_id'] ?? '') ?>" placeholder="ST-2041">
    <span class="cms-muted" style="display:block;margin-top:.35rem;font-size:.85rem">کد یکتا برای نمایش در سایت؛ در گوشه تصویر کارت‌ها دیده می‌شود</span>
  </label>
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">قیمت (متن نمایشی)</span>
      <input class="cms-input" name="price_text" value="<?= cms_h($edit['price_text'] ?? '') ?>" placeholder="۱٬۲۵۰٬۰۰۰ تومان">
    </label>
    <label class="cms-field"><span class="cms-label">تعداد در هر بسته</span>
      <input class="cms-input" type="number" min="1" name="pack_size" dir="ltr"
        value="<?= isset($edit['pack_size']) && $edit['pack_size'] !== null && (int) $edit['pack_size'] > 0 ? (int) $edit['pack_size'] : '' ?>"
        placeholder="مثلاً ۱۲ — خالی = فقط فروش تکی">
      <span class="cms-muted" style="display:block;margin-top:.35rem;font-size:.85rem">اگر پر باشد، مشتری می‌تواند عدد یا بسته بخرد.</span>
    </label>
  </div>
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">بنر بالای محصول</span>
      <select class="cms-select" name="banner">
        <?php foreach ($bannerLabels as $value => $label): ?>
          <option value="<?= cms_h($value) ?>" <?= ($edit['banner'] ?? 'none') === $value ? 'selected' : '' ?>><?= cms_h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">توضیحات</span>
    <textarea class="cms-textarea" name="description"><?= cms_h($edit['description'] ?? '') ?></textarea>
  </label>

  <h3 style="margin:1.25rem 0 .5rem;font-size:1rem">ابعاد</h3>
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">طول</span>
      <input class="cms-input" name="dim_length" value="<?= cms_h($edit['dim_length'] ?? '') ?>" placeholder="مثلاً ۱۵ سانتی‌متر">
    </label>
    <label class="cms-field"><span class="cms-label">عرض</span>
      <input class="cms-input" name="dim_width" value="<?= cms_h($edit['dim_width'] ?? '') ?>">
    </label>
    <label class="cms-field"><span class="cms-label">ارتفاع</span>
      <input class="cms-input" name="dim_height" value="<?= cms_h($edit['dim_height'] ?? '') ?>">
    </label>
    <label class="cms-field"><span class="cms-label">وزن</span>
      <input class="cms-input" name="dim_weight" value="<?= cms_h($edit['dim_weight'] ?? '') ?>" placeholder="مثلاً ۲۵۰ گرم">
    </label>
  </div>

  <?php cms_image_field('image', 'تصویر اصلی', (string) ($edit['image'] ?? '')); ?>
  <label class="cms-check" style="margin:.35rem 0 1rem">
    <input type="checkbox" name="skip_image_auto_frame" value="1" <?= !empty($edit['skip_image_auto_frame']) ? 'checked' : '' ?>>
    <span>حفظ قاب‌بندی اصلی تصویر (بدون برش و مرکز کردن خودکار PNG)</span>
  </label>
  <p class="cms-muted" style="margin:-0.5rem 0 1rem;font-size:.85rem">به‌طور پیش‌فرض، حاشیه شفاف PNG حذف و محصول در مرکز یک کادر مربع قرار می‌گیرد.</p>

  <h3 style="margin:1.25rem 0 .5rem;font-size:1rem">گالری تصاویر</h3>
  <p class="cms-muted" style="margin:0 0 .75rem">تصاویر اضافی صفحه محصول. اسلاید اول از طریق «اسلاید اول» قابل تنظیم است؛ تصویر اصلی همیشه در گالری نمایش داده می‌شود.</p>
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
  <?php if (count($gallery) < PRODUCT_GALLERY_MAX): ?>
    <button class="cms-btn cms-btn--secondary" type="submit"
      onclick="document.getElementById('product-form-action').value='add_gallery'">
      افزودن تصویر گالری
    </button>
  <?php endif; ?>

  <h3 style="margin:1.25rem 0 .5rem;font-size:1rem">ویدیو و نمایش تصاویر</h3>
  <?php cms_video_field('video_path', 'ویدیو محصول (اسلاید آخر)', (string) ($edit['video_path'] ?? ''), 'products/videos'); ?>
  <?php cms_video_field(
      'video_path_low',
      'ویدیو کیفیت پایین (اختیاری)',
      (string) ($edit['video_path_low'] ?? ''),
      'products/videos',
      'نسخه فشرده (مثلاً ۴۸۰p MP4) برای اینترنت کند. اگر خالی باشد فقط ویدیوی اصلی پخش می‌شود.'
  ); ?>
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">اسلاید اول صفحه محصول</span>
      <select class="cms-select" name="detail_lead_image">
        <?php foreach ($imagePickerOptions as $opt): ?>
          <option value="<?= cms_h($opt['value']) ?>" <?= $selectedDetailLead === $opt['value'] ? 'selected' : '' ?>><?= cms_h($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="cms-field"><span class="cms-label">تصویر فروشگاه و کارت‌ها</span>
      <select class="cms-select" name="shop_display_image">
        <?php foreach ($imagePickerOptions as $opt): ?>
          <option value="<?= cms_h($opt['value']) ?>" <?= $selectedShopImage === $opt['value'] ? 'selected' : '' ?>><?= cms_h($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">پوستر ویدیو (قبل از پخش)</span>
    <select class="cms-select" name="video_poster">
      <option value="" <?= $selectedVideoPoster === '' ? 'selected' : '' ?>>همان اسلاید اول (پیش‌فرض)</option>
      <?php foreach ($imagePickerOptions as $opt): ?>
        <?php if ($opt['value'] === '') continue; ?>
        <option value="<?= cms_h($opt['value']) ?>" <?= $selectedVideoPoster === $opt['value'] ? 'selected' : '' ?>><?= cms_h($opt['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="cms-field" style="margin-top:1rem"><span class="cms-label">ترتیب</span>
    <input class="cms-input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
  </label>
  <label class="cms-check">
    <input type="checkbox" name="published" <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
    منتشر شده
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit" onclick="document.getElementById('product-form-action').value='save'">ذخیره</button>
    <a class="cms-btn cms-btn--secondary" href="products.php">بازگشت به لیست</a>
  </div>
</form>
<?php else: ?>
<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">هنوز محصولی ثبت نشده. <a href="products.php?new=1">اولین مورد را اضافه کنید</a>.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead><tr><th>محصول</th><th>شناسه</th><th>خودرو</th><th>دسته</th><th>بنر</th><th>قیمت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><div class="cms-list-name"><?php cms_list_thumb(product_list_thumb_image($item)); ?><span><?= cms_h($item['name']) ?></span></div></td>
        <td dir="ltr"><?= cms_h($item['visual_id'] ?? '') ?></td>
        <td><?= cms_h($item['factory_name'] . ' / ' . $item['model_name']) ?></td>
        <td><?= cms_h($item['category_name']) ?></td>
        <td><?= cms_h($bannerLabels[$item['banner']] ?? $item['banner']) ?></td>
        <td><?= cms_h($item['price_text'] ?? '') ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0">
            <a class="cms-btn cms-btn--secondary" href="products.php?edit=<?= (int) $item['id'] ?>">ویرایش</a>
            <a class="cms-btn cms-btn--ghost" href="products.php?delete=<?= (int) $item['id'] ?>" onclick="return confirm('حذف؟')">حذف</a>
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
