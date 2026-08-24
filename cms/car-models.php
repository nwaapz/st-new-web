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

$edit = null;
$editFactoryIds = [];
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
    $editFactoryIds = cms_car_model_load_factory_ids($pdo, (int) $edit['id']);
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
        $factoryId1 = (int) ($_POST['factory_id_1'] ?? 0);
        $factoryId2 = (int) ($_POST['factory_id_2'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if ($slug === '') {
            $slug = cms_slugify($name);
        }
        $description = trim((string) ($_POST['description'] ?? ''));
        $image = cms_handle_optional_upload('image', (string) ($_POST['image'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;

        $factoryIds = array_values(array_filter([$factoryId1, $factoryId2], static fn (int $v): bool => $v > 0));
        if ($factoryIds === [] || $name === '') {
            throw new RuntimeException('حداقل یک کارخانه و نام مدل الزامی است');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE car_models SET name=?, slug=?, description=?, image=?, sort_order=?, published=? WHERE id=?'
            );
            $stmt->execute([$name, $slug, $description !== '' ? $description : null, $image !== '' ? $image : null, $sortOrder, $published, $id]);
            cms_car_model_save_factory_ids($pdo, $id, $factoryIds);
            cms_flash('مدل به‌روز شد');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO car_models (name, slug, description, image, sort_order, published) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$name, $slug, $description !== '' ? $description : null, $image !== '' ? $image : null, $sortOrder, $published]);
            $newId = (int) $pdo->lastInsertId();
            cms_car_model_save_factory_ids($pdo, $newId, $factoryIds);
            cms_flash('مدل اضافه شد');
        }
        cms_redirect('car-models.php');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'car-models.php?edit=' . $id : 'car-models.php?new=1');
    }
}

$factoryNamesSql = cms_car_model_factory_names_sql('m');
$items = $pdo->query(
    "SELECT m.*, {$factoryNamesSql} AS factory_name,
            (SELECT COUNT(*) FROM product_car_models pcm WHERE pcm.car_model_id = m.id) AS product_count
     FROM car_models m
     ORDER BY m.sort_order ASC, m.name ASC"
)->fetchAll();

cms_layout_start('مدل‌ها', cms_current_username(), 'shop');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">مدل‌های خودرو</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">هر مدل می‌تواند به یک یا دو کارخانه متصل باشد</p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="car-models.php?new=1">افزودن مدل</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2><?= $edit ? 'ویرایش مدل' : 'مدل جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">کارخانه اول (الزامی)</span>
      <select class="cms-select" name="factory_id_1" required>
        <option value="">انتخاب…</option>
        <?php foreach ($factories as $f): ?>
          <option value="<?= (int) $f['id'] ?>" <?= (int) ($editFactoryIds[0] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>><?= cms_h($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="cms-field"><span class="cms-label">کارخانه دوم (اختیاری)</span>
      <select class="cms-select" name="factory_id_2">
        <option value="">—</option>
        <?php foreach ($factories as $f): ?>
          <option value="<?= (int) $f['id'] ?>" <?= (int) ($editFactoryIds[1] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>><?= cms_h($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
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
    <thead><tr><th>مدل</th><th>کارخانه‌ها</th><th>محصولات</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><div class="cms-list-name"><?php cms_list_thumb($item['image'] ?? null); ?><span><?= cms_h($item['name']) ?></span></div></td>
        <td><?= cms_h($item['factory_name'] ?? '') ?></td>
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
