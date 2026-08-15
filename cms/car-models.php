<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();
$pdo = cms_pdo();
$edit = null;
$showForm = isset($_GET['new']) || isset($_GET['edit']);

$factories = $pdo->query('SELECT id, name FROM factories ORDER BY sort_order ASC, name ASC')->fetchAll();

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM car_models WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('مورد یافت نشد', 'error');
        cms_redirect('car-models.php');
    }
    $showForm = true;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM car_models WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('مدل حذف شد');
    cms_redirect('car-models.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $factoryId = (int) ($_POST['factory_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if ($slug === '') {
            $slug = cms_slugify($name);
        }
        $description = trim((string) ($_POST['description'] ?? ''));
        $image = cms_handle_optional_upload('image', (string) ($_POST['image'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;

        if ($factoryId <= 0 || $name === '') {
            throw new RuntimeException('کارخانه و نام الزامی است');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE car_models SET factory_id=?, name=?, slug=?, description=?, image=?, sort_order=?, published=? WHERE id=?'
            );
            $stmt->execute([$factoryId, $name, $slug, $description !== '' ? $description : null, $image !== '' ? $image : null, $sortOrder, $published, $id]);
            cms_flash('مدل به‌روز شد');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO car_models (factory_id, name, slug, description, image, sort_order, published) VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([$factoryId, $name, $slug, $description !== '' ? $description : null, $image !== '' ? $image : null, $sortOrder, $published]);
            cms_flash('مدل اضافه شد');
        }
        cms_redirect('car-models.php');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'car-models.php?edit=' . $id : 'car-models.php?new=1');
    }
}

$items = $pdo->query(
    'SELECT m.*, f.name AS factory_name,
            (SELECT COUNT(*) FROM products p WHERE p.car_model_id = m.id) AS product_count
     FROM car_models m
     JOIN factories f ON f.id = m.factory_id
     ORDER BY f.sort_order ASC, m.sort_order ASC, m.name ASC'
)->fetchAll();

cms_layout_start('مدل‌ها', cms_current_username(), 'shop');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">مدل‌های خودرو</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">لیست مدل‌های ثبت‌شده</p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="car-models.php?new=1">افزودن مدل</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2><?= $edit ? 'ویرایش مدل' : 'مدل جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <label class="cms-field"><span class="cms-label">کارخانه</span>
    <select class="cms-select" name="factory_id" required>
      <option value="">انتخاب…</option>
      <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['id'] ?>" <?= (int) ($edit['factory_id'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>><?= cms_h($f['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">نام مدل</span>
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
  <label class="cms-field"><span class="cms-label">ترتیب</span>
    <input class="cms-input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
  </label>
  <label class="cms-check">
    <input type="checkbox" name="published" <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
    منتشر شده
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره</button>
    <a class="cms-btn cms-btn--secondary" href="car-models.php">بازگشت به لیست</a>
  </div>
</form>
<?php else: ?>
<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">هنوز مدلی ثبت نشده. <a href="car-models.php?new=1">اولین مورد را اضافه کنید</a>.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead><tr><th>مدل</th><th>کارخانه</th><th>محصولات</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><div class="cms-list-name"><?php cms_list_thumb($item['image'] ?? null); ?><span><?= cms_h($item['name']) ?></span></div></td>
        <td><?= cms_h($item['factory_name']) ?></td>
        <td><?= (int) $item['product_count'] ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0">
            <a class="cms-btn cms-btn--secondary" href="car-models.php?edit=<?= (int) $item['id'] ?>">ویرایش</a>
            <a class="cms-btn cms-btn--ghost" href="car-models.php?delete=<?= (int) $item['id'] ?>" onclick="return confirm('حذف؟')">حذف</a>
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
