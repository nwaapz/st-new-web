<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();
$pdo = cms_pdo();
$edit = null;
$showForm = isset($_GET['new']) || isset($_GET['edit']);

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM factories WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('مورد یافت نشد', 'error');
        cms_redirect('factories.php');
    }
    $showForm = true;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM factories WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('کارخانه حذف شد');
    cms_redirect('factories.php');
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
        $existingImage = (string) ($_POST['image'] ?? '');
        $image = cms_handle_optional_upload('image', $existingImage);
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;

        if ($name === '') {
            throw new RuntimeException('نام الزامی است');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE factories SET name=?, slug=?, description=?, image=?, sort_order=?, published=? WHERE id=?'
            );
            $stmt->execute([$name, $slug, $description !== '' ? $description : null, $image !== '' ? $image : null, $sortOrder, $published, $id]);
            cms_flash('کارخانه به‌روز شد');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO factories (name, slug, description, image, sort_order, published) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$name, $slug, $description !== '' ? $description : null, $image !== '' ? $image : null, $sortOrder, $published]);
            cms_flash('کارخانه اضافه شد');
        }
        cms_redirect('factories.php');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'factories.php?edit=' . $id : 'factories.php?new=1');
    }
}

$items = $pdo->query(
    'SELECT f.*, (SELECT COUNT(DISTINCT cmf.car_model_id) FROM car_model_factories cmf WHERE cmf.factory_id = f.id) AS model_count
     FROM factories f ORDER BY f.sort_order ASC, f.name ASC'
)->fetchAll();

cms_layout_start('کارخانه‌ها', cms_current_username(), 'shop');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">کارخانه‌ها / برندها</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">لیست کارخانه‌های ثبت‌شده</p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="factories.php?new=1">افزودن کارخانه</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2><?= $edit ? 'ویرایش کارخانه' : 'کارخانه جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">نام</span>
      <input class="cms-input" name="name" required value="<?= cms_h($edit['name'] ?? '') ?>">
    </label>
    <label class="cms-field"><span class="cms-label">اسلاگ</span>
      <input class="cms-input" name="slug" dir="ltr" value="<?= cms_h($edit['slug'] ?? '') ?>">
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">توضیحات</span>
    <textarea class="cms-textarea" name="description"><?= cms_h($edit['description'] ?? '') ?></textarea>
  </label>
  <?php cms_image_field('image', 'تصویر / لوگو', (string) ($edit['image'] ?? '')); ?>
  <label class="cms-field"><span class="cms-label">ترتیب</span>
    <input class="cms-input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
  </label>
  <label class="cms-check">
    <input type="checkbox" name="published" <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
    منتشر شده
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره</button>
    <a class="cms-btn cms-btn--secondary" href="factories.php">بازگشت به لیست</a>
  </div>
</form>
<?php else: ?>
<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">هنوز کارخانه‌ای ثبت نشده. <a href="factories.php?new=1">اولین مورد را اضافه کنید</a>.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead><tr><th>نام</th><th>اسلاگ</th><th>مدل‌ها</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><div class="cms-list-name"><?php cms_list_thumb($item['image'] ?? null); ?><span><?= cms_h($item['name']) ?></span></div></td>
        <td dir="ltr"><?= cms_h($item['slug']) ?></td>
        <td><?= (int) $item['model_count'] ?></td>
        <td><?= (int) $item['published'] ? 'فعال' : 'پیش‌نویس' ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0">
            <a class="cms-btn cms-btn--secondary" href="factories.php?edit=<?= (int) $item['id'] ?>">ویرایش</a>
            <a class="cms-btn cms-btn--ghost" href="factories.php?delete=<?= (int) $item['id'] ?>" onclick="return confirm('حذف؟')">حذف</a>
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
