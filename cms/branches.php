<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/iran-provinces.php';
require_once __DIR__ . '/lib/messages.php';
require_once __DIR__ . '/lib/branches.php';
require_once __DIR__ . '/lib/page-intros.php';

cms_require_login();
$pdo = cms_pdo();
messages_ensure_schema($pdo);
branches_ensure_schema($pdo);

$provinces = iran_provinces();
$provinceMap = iran_provinces_map();
$edit = null;
$showForm = isset($_GET['new']) || isset($_GET['edit']);

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('نماینده یافت نشد', 'error');
        cms_redirect('branches.php');
    }
    $showForm = true;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM branches WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('نماینده حذف شد');
    cms_redirect('branches.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_header_image'])) {
    try {
        $existing = cms_setting_get('branch_portal_header_image', '');
        $image = cms_handle_optional_upload('header_image', $existing);
        cms_setting_set('branch_portal_header_image', $image);
        cms_flash($image !== '' ? 'تصویر هدر پورتال ذخیره شد' : 'تصویر هدر پورتال حذف شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('branches.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_page_intro'])) {
    try {
        cms_page_intro_save(
            'branch_portal',
            (string) ($_POST['intro_title'] ?? ''),
            (string) ($_POST['intro_explanation'] ?? '')
        );
        cms_flash('متن هدر پورتال نمایندگان ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('branches.php');
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['save_header_image'])
    && !isset($_POST['save_page_intro'])
) {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $name = trim((string) ($_POST['name'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $provinceCode = trim((string) ($_POST['province_code'] ?? ''));
        $phone = branches_normalize_phone((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;

        if ($name === '') {
            throw new RuntimeException('نام نماینده الزامی است');
        }
        if ($city === '') {
            throw new RuntimeException('شهر الزامی است');
        }
        if (!isset($provinceMap[$provinceCode])) {
            throw new RuntimeException('استان نامعتبر است');
        }
        if ($phone === '' || !preg_match('/^09\d{9}$/', $phone)) {
            throw new RuntimeException('موبایل ورود نماینده الزامی است (۰۹xxxxxxxxx)');
        }

        $dup = $pdo->prepare('SELECT id FROM branches WHERE phone = ? AND id <> ? LIMIT 1');
        $dup->execute([$phone, $id]);
        if ($dup->fetch()) {
            throw new RuntimeException('این شماره قبلاً برای نماینده دیگری ثبت شده');
        }

        $provinceName = $provinceMap[$provinceCode];

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE branches
                 SET name=?, province_code=?, province_name=?, city=?, phone=?, address=?, sort_order=?, published=?
                 WHERE id=?'
            );
            $stmt->execute([
                $name,
                $provinceCode,
                $provinceName,
                $city,
                $phone,
                $address !== '' ? $address : null,
                $sortOrder,
                $published,
                $id,
            ]);
            cms_flash('نماینده به‌روز شد');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO branches
                 (name, province_code, province_name, city, phone, address, sort_order, published)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $name,
                $provinceCode,
                $provinceName,
                $city,
                $phone,
                $address !== '' ? $address : null,
                $sortOrder,
                $published,
            ]);
            cms_flash('نماینده اضافه شد');
        }
        cms_redirect('branches.php');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'branches.php?edit=' . $id : 'branches.php?new=1');
    }
}

$headerImage = cms_setting_get('branch_portal_header_image', '');
$intro = cms_page_intro_stored('branch_portal');
$introDefaults = cms_page_intro_defaults()['branch_portal'];

$items = $pdo->query(
    'SELECT * FROM branches ORDER BY province_name ASC, city ASC, sort_order ASC, name ASC'
)->fetchAll();

cms_layout_start('نمایندگان', cms_current_username(), 'website');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">نمایندگان / شعب</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">شعب قابل نمایش روی نقشه پورتال نمایندگان</p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="branches.php?new=1">افزودن نماینده</a>
  <?php endif; ?>
</div>

<?php if (!$showForm): ?>
<form class="cms-panel" method="post" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">متن هدر پورتال نمایندگان</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    عنوان و توضیح بالای صفحه پورتال. خالی بگذارید تا متن پیش‌فرض سایت استفاده شود.
  </p>
  <input type="hidden" name="save_page_intro" value="1">
  <label class="cms-field">
    <span class="cms-label">عنوان هدر</span>
    <input class="cms-input" name="intro_title" value="<?= cms_h($intro['title']) ?>" placeholder="<?= cms_h($introDefaults['title']) ?>">
  </label>
  <label class="cms-field">
    <span class="cms-label">متن توضیحی</span>
    <textarea class="cms-textarea" name="intro_explanation" rows="3" placeholder="<?= cms_h($introDefaults['explanation']) ?>"><?= cms_h($intro['explanation']) ?></textarea>
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره متن</button>
  </div>
</form>

<form class="cms-panel" method="post" enctype="multipart/form-data" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">تصویر هدر پورتال نمایندگان</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    کنار عنوان هدر در صفحه پورتال نمایش داده می‌شود. برای حذف، مسیر را خالی کنید و ذخیره کنید.
  </p>
  <input type="hidden" name="save_header_image" value="1">
  <?php cms_image_field('header_image', 'تصویر فریم هدر', $headerImage); ?>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره تصویر</button>
  </div>
</form>
<?php endif; ?>

<?php if ($showForm): ?>
<form class="cms-panel" method="post">
  <h2><?= $edit ? 'ویرایش نماینده' : 'نماینده جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">نام نماینده / شعبه</span>
      <input class="cms-input" name="name" required value="<?= cms_h($edit['name'] ?? '') ?>">
    </label>
    <label class="cms-field"><span class="cms-label">شهر</span>
      <input class="cms-input" name="city" required value="<?= cms_h($edit['city'] ?? '') ?>">
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">استان</span>
    <select class="cms-input" name="province_code" required>
      <option value="">انتخاب استان…</option>
      <?php foreach ($provinces as $p): ?>
        <option value="<?= cms_h($p['code']) ?>" <?= (($edit['province_code'] ?? '') === $p['code']) ? 'selected' : '' ?>>
          <?= cms_h($p['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="cms-grid-2">
    <label class="cms-field"><span class="cms-label">موبایل ورود نماینده (الزامی — مخفی از بازدیدکنندگان)</span>
      <input class="cms-input" name="phone" dir="ltr" required value="<?= cms_h($edit['phone'] ?? '') ?>" placeholder="09xxxxxxxxx">
    </label>
    <label class="cms-field"><span class="cms-label">ترتیب</span>
      <input class="cms-input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
    </label>
  </div>
  <label class="cms-field"><span class="cms-label">آدرس (اختیاری)</span>
    <textarea class="cms-textarea" name="address"><?= cms_h($edit['address'] ?? '') ?></textarea>
  </label>
  <label class="cms-check">
    <input type="checkbox" name="published" <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
    منتشر شده
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره</button>
    <a class="cms-btn cms-btn--secondary" href="branches.php">بازگشت به لیست</a>
  </div>
</form>
<?php else: ?>
<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">هنوز نماینده‌ای ثبت نشده. <a href="branches.php?new=1">اولین مورد را اضافه کنید</a>.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead>
      <tr>
        <th>نام</th>
        <th>استان</th>
        <th>شهر</th>
        <th>تلفن</th>
        <th>وضعیت</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?= cms_h((string) $item['name']) ?></td>
        <td><?= cms_h((string) $item['province_name']) ?></td>
        <td><?= cms_h((string) $item['city']) ?></td>
        <td dir="ltr"><?= cms_h((string) ($item['phone'] ?? '—')) ?></td>
        <td><?= (int) $item['published'] ? 'فعال' : 'پیش‌نویس' ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0">
            <a class="cms-btn cms-btn--secondary" href="branches.php?edit=<?= (int) $item['id'] ?>">ویرایش</a>
            <a class="cms-btn cms-btn--ghost" href="branches.php?delete=<?= (int) $item['id'] ?>" onclick="return confirm('حذف؟')">حذف</a>
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
