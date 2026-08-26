<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();
$pdo = cms_pdo();
$edit = null;
$showForm = isset($_GET['new']) || isset($_GET['edit']);

function category_ensure_schema(PDO $pdo): void
{
    $skipFrameExists = $pdo->query("SHOW COLUMNS FROM categories LIKE 'skip_image_auto_frame'")->fetchAll();
    if (count($skipFrameExists) === 0) {
        $pdo->exec('ALTER TABLE categories ADD COLUMN skip_image_auto_frame TINYINT(1) NOT NULL DEFAULT 0 AFTER image');
    }
    foreach (
        [
            'video_path' => 'VARCHAR(512) NULL AFTER image',
            'video_path_low' => 'VARCHAR(512) NULL AFTER video_path',
        ] as $col => $definition
    ) {
        $exists = $pdo->query('SHOW COLUMNS FROM categories LIKE ' . $pdo->quote($col))->fetchAll();
        if (count($exists) === 0) {
            $pdo->exec("ALTER TABLE categories ADD COLUMN {$col} {$definition}");
        }
    }
}

category_ensure_schema($pdo);

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('مورد یافت نشد', 'error');
        cms_redirect('categories.php');
    }
    $showForm = true;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('دسته حذف شد');
    cms_redirect('categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if ($slug === '') {
            $slug = cms_slugify($name);
        }
        $description = trim((string) ($_POST['description'] ?? ''));
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
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;

        if ($name === '') {
            throw new RuntimeException('نام الزامی است');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE categories SET name=?, slug=?, description=?, image=?, video_path=?, video_path_low=?, skip_image_auto_frame=?, sort_order=?, published=? WHERE id=?'
            );
            $stmt->execute([
                $name,
                $slug,
                $description !== '' ? $description : null,
                $image !== '' ? $image : null,
                $videoPath !== '' ? $videoPath : null,
                $videoPathLow !== '' ? $videoPathLow : null,
                $skipImageAutoFrame,
                $sortOrder,
                $published,
                $id,
            ]);
            cms_flash('دسته به‌روز شد');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO categories (name, slug, description, image, video_path, video_path_low, skip_image_auto_frame, sort_order, published) VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $name,
                $slug,
                $description !== '' ? $description : null,
                $image !== '' ? $image : null,
                $videoPath !== '' ? $videoPath : null,
                $videoPathLow !== '' ? $videoPathLow : null,
                $skipImageAutoFrame,
                $sortOrder,
                $published,
            ]);
            cms_flash('دسته اضافه شد');
        }
        cms_redirect('categories.php');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'categories.php?edit=' . $id : 'categories.php?new=1');
    }
}

$items = $pdo->query(
    'SELECT c.*,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
     FROM categories c
     ORDER BY c.sort_order ASC, c.name ASC'
)->fetchAll();

cms_layout_start('دسته‌بندی‌ها', cms_current_username(), 'shop');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">دسته‌بندی محصولات</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">ریشه مستقل — وابسته به کارخانه/مدل نیست</p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="categories.php?new=1">افزودن دسته</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2><?= $edit ? 'ویرایش دسته' : 'دسته جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">نام دسته</span>
      <input class="cms-input" name="name" required value="<?= cms_h($edit['name'] ?? '') ?>">
    </label>
    <label class="cms-field"><span class="cms-label">اسلاگ</span>
      <input class="cms-input" name="slug" dir="ltr" value="<?= cms_h($edit['slug'] ?? '') ?>">
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">توضیحات</span>
    <textarea class="cms-textarea" name="description"><?= cms_h($edit['description'] ?? '') ?></textarea>
  </label>
  <?php cms_image_field('image', 'تصویر', (string) ($edit['image'] ?? '')); ?>
  <label class="cms-check" style="margin:.35rem 0 1rem">
    <input type="checkbox" name="skip_image_auto_frame" value="1" <?= !empty($edit['skip_image_auto_frame']) ? 'checked' : '' ?>>
    <span>حفظ قاب‌بندی اصلی تصویر (بدون برش و مرکز کردن خودکار PNG)</span>
  </label>
  <p class="cms-muted" style="margin:-0.5rem 0 1rem;font-size:.85rem">به‌طور پیش‌فرض، حاشیه شفاف PNG حذف و محصول در مرکز یک کادر مربع قرار می‌گیرد. تصویر پس از آپلود برای بارگذاری سریع‌تر در سایت فشرده می‌شود.</p>
  <h3 style="margin:1.25rem 0 .5rem;font-size:1rem">ویدیو دسته</h3>
  <p class="cms-muted" style="margin:-0.25rem 0 .75rem;font-size:.85rem">اگر محصول ویدیوی اختصاصی نداشته باشد، این ویدیو در اسلایدر صفحه محصول نمایش داده می‌شود.</p>
  <?php cms_video_field('video_path', 'ویدیو دسته (پیش‌فرض محصولات)', (string) ($edit['video_path'] ?? ''), 'products/videos'); ?>
  <?php cms_video_field(
      'video_path_low',
      'ویدیو کیفیت پایین (اختیاری)',
      (string) ($edit['video_path_low'] ?? ''),
      'products/videos',
      'نسخه فشرده (مثلاً ۴۸۰p MP4) برای اینترنت کند. اگر خالی باشد فقط ویدیوی اصلی پخش می‌شود.'
  ); ?>
  <label class="cms-field"><span class="cms-label">ترتیب</span>
    <input class="cms-input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
  </label>
  <label class="cms-check">
    <input type="checkbox" name="published" <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
    منتشر شده
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره</button>
    <a class="cms-btn cms-btn--secondary" href="categories.php">بازگشت به لیست</a>
  </div>
</form>
<?php else: ?>
<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">هنوز دسته‌ای ثبت نشده. <a href="categories.php?new=1">اولین مورد را اضافه کنید</a>.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead><tr><th>دسته</th><th>اسلاگ</th><th>محصولات</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><div class="cms-list-name"><?php cms_list_thumb($item['image'] ?? null); ?><span><?= cms_h($item['name']) ?></span></div></td>
        <td dir="ltr"><?= cms_h($item['slug']) ?></td>
        <td><?= (int) $item['product_count'] ?></td>
        <td><?= (int) $item['published'] ? 'فعال' : 'پیش‌نویس' ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0">
            <a class="cms-btn cms-btn--secondary" href="categories.php?edit=<?= (int) $item['id'] ?>">ویرایش</a>
            <a class="cms-btn cms-btn--ghost" href="categories.php?delete=<?= (int) $item['id'] ?>" onclick="return confirm('حذف؟')">حذف</a>
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
