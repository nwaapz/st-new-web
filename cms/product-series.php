<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();
$pdo = cms_pdo();
$edit = null;
$showForm = isset($_GET['new']) || isset($_GET['edit']);
$selectedProductIds = [];

$allProducts = $pdo->query(
    'SELECT p.id, p.name, c.name AS category_name, m.name AS model_name
     FROM products p
     JOIN categories c ON c.id = p.category_id
     JOIN car_models m ON m.id = p.car_model_id
     ORDER BY p.sort_order ASC, p.name ASC'
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
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM product_series WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('سری حذف شد');
    cms_redirect('product-series.php');
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
        $image = cms_handle_optional_upload('image', (string) ($_POST['image'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;
        $productIds = array_values(array_unique(array_map(
            'intval',
            (array) ($_POST['product_ids'] ?? [])
        )));
        $productIds = array_values(array_filter($productIds, static fn (int $pid): bool => $pid > 0));

        if ($name === '') {
            throw new RuntimeException('نام سری الزامی است');
        }

        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE product_series SET name=?, slug=?, description=?, image=?, sort_order=?, published=? WHERE id=?'
            );
            $stmt->execute([
                $name,
                $slug,
                $description !== '' ? $description : null,
                $image !== '' ? $image : null,
                $sortOrder,
                $published,
                $id,
            ]);
            $seriesId = $id;
            cms_flash('سری به‌روز شد');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO product_series (name, slug, description, image, sort_order, published) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([
                $name,
                $slug,
                $description !== '' ? $description : null,
                $image !== '' ? $image : null,
                $sortOrder,
                $published,
            ]);
            $seriesId = (int) $pdo->lastInsertId();
            cms_flash('سری اضافه شد');
        }

        $pdo->prepare('DELETE FROM product_series_items WHERE series_id = ?')->execute([$seriesId]);
        if ($productIds !== []) {
            $insert = $pdo->prepare(
                'INSERT INTO product_series_items (series_id, product_id, sort_order) VALUES (?,?,?)'
            );
            foreach ($productIds as $index => $productId) {
                $insert->execute([$seriesId, $productId, $index]);
            }
        }

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

$items = $pdo->query(
    'SELECT s.*,
            (SELECT COUNT(*) FROM product_series_items i WHERE i.series_id = s.id) AS product_count
     FROM product_series s
     ORDER BY s.sort_order ASC, s.name ASC'
)->fetchAll();

cms_layout_start('سری محصولات', cms_current_username(), 'shop');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">سری محصولات</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">گروه‌های صفحه اصلی — نام و تصویر در سایت؛ محصولات انتخاب‌شده برای استفاده‌های بعدی</p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="product-series.php?new=1">افزودن سری</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<form class="cms-panel" method="post" enctype="multipart/form-data">
  <h2><?= $edit ? 'ویرایش سری' : 'سری جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">نام سری</span>
      <input class="cms-input" name="name" required value="<?= cms_h($edit['name'] ?? '') ?>">
    </label>
    <label class="cms-field"><span class="cms-label">اسلاگ</span>
      <input class="cms-input" name="slug" dir="ltr" value="<?= cms_h($edit['slug'] ?? '') ?>">
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">توضیحات</span>
    <textarea class="cms-textarea" name="description"><?= cms_h($edit['description'] ?? '') ?></textarea>
  </label>
  <?php cms_image_field('image', 'تصویر گروه (نمایش در صفحه اصلی)', (string) ($edit['image'] ?? '')); ?>
  <label class="cms-field"><span class="cms-label">ترتیب</span>
    <input class="cms-input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
  </label>
  <label class="cms-check">
    <input type="checkbox" name="published" <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
    منتشر شده
  </label>

  <fieldset class="cms-field" style="border:0;padding:0;margin:1rem 0 0">
    <legend class="cms-label" style="padding:0;margin-bottom:.5rem">محصولات این سری</legend>
    <p class="cms-muted" style="margin:0 0 .5rem">در صفحه اصلی فقط نام و تصویر سری دیده می‌شود؛ محصولات برای بعد ذخیره می‌شوند.</p>
    <?php if ($allProducts === []): ?>
      <p class="cms-empty" style="margin:0">هنوز محصولی ثبت نشده. ابتدا از <a href="products.php">محصولات</a> اضافه کنید.</p>
    <?php else: ?>
      <div class="cms-check-list">
        <?php foreach ($allProducts as $product): ?>
          <?php $pid = (int) $product['id']; ?>
          <label class="cms-check cms-check-list__item">
            <input type="checkbox" name="product_ids[]" value="<?= $pid ?>" <?= in_array($pid, $selectedProductIds, true) ? 'checked' : '' ?>>
            <span>
              <?= cms_h($product['name']) ?>
              <span class="cms-muted"> — <?= cms_h($product['category_name'] . ' / ' . $product['model_name']) ?></span>
            </span>
          </label>
        <?php endforeach; ?>
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
    <thead><tr><th>سری</th><th>اسلاگ</th><th>محصولات</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><div class="cms-list-name"><?php cms_list_thumb($item['image'] ?? null); ?><span><?= cms_h($item['name']) ?></span></div></td>
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
