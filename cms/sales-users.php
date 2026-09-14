<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/sales-users.php';
require_once __DIR__ . '/lib/branches.php';

cms_require_login();
$pdo = cms_pdo();
sales_users_ensure_schema($pdo);
branches_ensure_schema($pdo);

$edit = null;
$showForm = isset($_GET['new']) || isset($_GET['edit']);

if (isset($_GET['edit'])) {
    $edit = sales_users_get($pdo, (int) $_GET['edit']);
    if ($edit === null) {
        cms_flash('کاربر فروش یافت نشد', 'error');
        cms_redirect('sales-users.php');
    }
    $showForm = true;
}

if (isset($_GET['delete'])) {
    try {
        sales_users_delete($pdo, (int) $_GET['delete']);
        cms_flash('کاربر فروش حذف شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('sales-users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        sales_users_save($pdo, [
            'id' => $id,
            'username' => (string) ($_POST['username'] ?? ''),
            'display_name' => (string) ($_POST['display_name'] ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
            'branch_id' => $branchId > 0 ? $branchId : null,
            'published' => isset($_POST['published']),
        ]);
        cms_flash($id > 0 ? 'کاربر فروش به‌روز شد' : 'کاربر فروش اضافه شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('sales-users.php');
}

$users = sales_users_list($pdo);
$branches = sales_users_branch_options($pdo);

cms_layout_start('کاربران اپ فروش', cms_current_username(), 'shop');
?>
<h1 style="margin-top:0">کاربران اپ فروش</h1>
<p class="cms-muted">
  حساب‌های ورود اپ اندroid. نام نمایشی در سفارش‌ها برای ادمین دیده می‌شود.
</p>

<div class="cms-btn-row">
  <a class="cms-btn" href="sales-users.php?new=1">افزودن کاربر</a>
</div>

<?php if ($showForm): ?>
<div class="cms-panel" style="margin-top:1rem">
  <h2><?= $edit ? 'ویرایش کاربر' : 'کاربر جدید' ?></h2>
  <form method="post" class="cms-form">
    <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <label class="cms-field">
      <span class="cms-label">نام کاربری (انگلیسی)</span>
      <input class="cms-input" name="username" dir="ltr" required
             value="<?= cms_h((string) ($edit['username'] ?? '')) ?>"
             autocomplete="off">
    </label>
    <label class="cms-field">
      <span class="cms-label">نام نمایشی (در سفارش‌ها)</span>
      <input class="cms-input" name="display_name" required
             value="<?= cms_h((string) ($edit['display_name'] ?? '')) ?>">
    </label>
    <label class="cms-field">
      <span class="cms-label"><?= $edit ? 'رمز عبور (خالی = بدون تغییر)' : 'رمز عبور' ?></span>
      <input class="cms-input" type="text" name="password" dir="ltr"
             value="<?= cms_h((string) ($edit['password'] ?? '')) ?>"
             <?= $edit ? '' : 'required' ?> autocomplete="off">
    </label>
    <label class="cms-field">
      <span class="cms-label">نماینده مرتبط (اختیاری)</span>
      <select class="cms-input" name="branch_id">
        <option value="0">—</option>
        <?php foreach ($branches as $branch): ?>
          <option value="<?= (int) $branch['id'] ?>"
            <?= (int) ($edit['branch_id'] ?? 0) === (int) $branch['id'] ? 'selected' : '' ?>>
            <?= cms_h((string) $branch['name']) ?> — <?= cms_h((string) $branch['city']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="cms-field cms-field--inline">
      <input type="checkbox" name="published" value="1"
        <?= !isset($edit['published']) || ($edit['published'] ?? true) ? 'checked' : '' ?>>
      <span>فعال</span>
    </label>
    <div class="cms-btn-row">
      <button class="cms-btn" type="submit">ذخیره</button>
      <a class="cms-btn cms-btn--secondary" href="sales-users.php">انصراف</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="cms-panel" style="margin-top:1rem">
  <h2>فهرست کاربران</h2>
  <?php if ($users === []): ?>
    <p class="cms-empty">هنوز کاربری ثبت نشده. <a href="sales-users.php?new=1">اولین مورد را اضافه کنید</a>.</p>
  <?php else: ?>
    <table class="cms-table">
      <thead>
        <tr>
          <th>نام نمایشی</th>
          <th>نام کاربری</th>
          <th>رمز عبور</th>
          <th>نماینده</th>
          <th>وضعیت</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td><?= cms_h((string) $user['display_name']) ?></td>
            <td dir="ltr"><?= cms_h((string) $user['username']) ?></td>
            <td dir="ltr"><?= cms_h((string) ($user['password'] ?: '—')) ?></td>
            <td><?= cms_h((string) ($user['branch_name'] ?? '—')) ?></td>
            <td><?= ($user['published'] ?? true) ? 'فعال' : 'غیرفعال' ?></td>
            <td class="cms-table__actions">
              <a href="sales-users.php?edit=<?= (int) $user['id'] ?>">ویرایش</a>
              <a href="sales-users.php?delete=<?= (int) $user['id'] ?>"
                 onclick="return confirm('حذف شود؟')">حذف</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php cms_layout_end(); ?>
