<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();
$pdo = cms_pdo();
$edit = null;
$showForm = isset($_GET['new']) || isset($_GET['edit']);

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM rewards WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('مورد یافت نشد', 'error');
        cms_redirect('rewards.php');
    }
    $showForm = true;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM rewards WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('جایزه حذف شد');
    cms_redirect('rewards.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $image = cms_handle_optional_upload('image', (string) ($_POST['image'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;

        if ($title === '') {
            throw new RuntimeException('عنوان الزامی است');
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE rewards SET title=?, description=?, image=?, sort_order=?, published=? WHERE id=?'
            );
            $stmt->execute([
                $title,
                $description !== '' ? $description : null,
                $image !== '' ? $image : null,
                $sortOrder,
                $published,
                $id,
            ]);
            cms_flash('جایزه به‌روز شد');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO rewards (title, description, image, sort_order, published) VALUES (?,?,?,?,?)'
            );
            $stmt->execute([
                $title,
                $description !== '' ? $description : null,
                $image !== '' ? $image : null,
                $sortOrder,
                $published,
            ]);
            cms_flash('جایزه اضافه شد');
        }
        cms_redirect('rewards.php');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'rewards.php?edit=' . $id : 'rewards.php?new=1');
    }
}

$items = $pdo->query(
    'SELECT * FROM rewards ORDER BY sort_order ASC, id ASC'
)->fetchAll();

cms_layout_start('جوایز و گواهینامه‌ها', cms_current_username(), 'website');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">جوایز و گواهینامه‌ها</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">اسلایدهای بخش جوایز صفحه اصلی — عنوان، توضیح و تصویر</p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="rewards.php?new=1">افزودن جایزه</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2><?= $edit ? 'ویرایش جایزه' : 'جایزه جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <label class="cms-field"><span class="cms-label">عنوان / برچسب</span>
    <input class="cms-input" name="title" required value="<?= cms_h($edit['title'] ?? '') ?>" placeholder="مثلاً گواهینامه ISO 9001">
  </label>
  <label class="cms-field"><span class="cms-label">توضیحات</span>
    <textarea class="cms-textarea" name="description" rows="4" placeholder="متن توضیحی پنل چپ"><?= cms_h($edit['description'] ?? '') ?></textarea>
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
    <a class="cms-btn cms-btn--secondary" href="rewards.php">بازگشت به لیست</a>
  </div>
</form>
<?php else: ?>
<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">هنوز جایزه‌ای ثبت نشده. <a href="rewards.php?new=1">اولین مورد را اضافه کنید</a>.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead><tr><th>جایزه</th><th>توضیح</th><th>ترتیب</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><div class="cms-list-name"><?php cms_list_thumb($item['image'] ?? null); ?><span><?= cms_h($item['title']) ?></span></div></td>
        <td class="cms-muted"><?= cms_h(mb_substr((string) ($item['description'] ?? ''), 0, 80, 'UTF-8')) ?></td>
        <td><?= (int) $item['sort_order'] ?></td>
        <td><?= (int) $item['published'] ? 'فعال' : 'پیش‌نویس' ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0">
            <a class="cms-btn cms-btn--secondary" href="rewards.php?edit=<?= (int) $item['id'] ?>">ویرایش</a>
            <a class="cms-btn cms-btn--ghost" href="rewards.php?delete=<?= (int) $item['id'] ?>" onclick="return confirm('حذف؟')">حذف</a>
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
