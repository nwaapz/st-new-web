<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/admin-users.php';
require_once __DIR__ . '/lib/admin-audit.php';

cms_require_login();
$pdo = cms_pdo();
admin_users_ensure_schema($pdo);

$edit = null;
$showForm = isset($_GET['new']) || isset($_GET['edit']);

if (isset($_GET['edit'])) {
    $edit = admin_users_get($pdo, (int) $_GET['edit']);
    if ($edit === null) {
        cms_flash('مدیر یافت نشد', 'error');
        cms_redirect('admin-users.php');
    }
    $showForm = true;
}

if (isset($_GET['delete'])) {
    try {
        $deleted = admin_users_get($pdo, (int) $_GET['delete']);
        admin_users_delete($pdo, (int) $_GET['delete'], cms_current_admin_id());
        if ($deleted !== null) {
            cms_admin_audit($pdo, 'admin_user.delete', [
                'entity_type' => 'admin_user',
                'entity_id' => (int) $deleted['id'],
                'entity_label' => (string) $deleted['username'],
                'summary' => cms_current_username() . ' مدیر ' . $deleted['username'] . ' را حذف کرد',
            ]);
        }
        cms_flash('مدیر حذف شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('admin-users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $savedId = admin_users_save($pdo, [
            'id' => $id,
            'username' => (string) ($_POST['username'] ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
            'is_active' => isset($_POST['is_active']),
        ]);
        $saved = admin_users_get($pdo, $savedId);
        if ($saved !== null) {
            $verb = $id > 0 ? ' را به‌روز کرد' : ' را اضافه کرد';
            cms_admin_audit($pdo, 'admin_user.save', [
                'entity_type' => 'admin_user',
                'entity_id' => $savedId,
                'entity_label' => (string) $saved['username'],
                'summary' => cms_current_username() . ' مدیر ' . $saved['username'] . $verb,
                'detail' => ['is_active' => $saved['is_active']],
            ]);
        }
        cms_flash($id > 0 ? 'مدیر به‌روز شد' : 'مدیر اضافه شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('admin-users.php');
}

$users = admin_users_list($pdo);

cms_layout_start('مدیران CMS', cms_current_username(), 'advanced');
?>
<h1 style="margin-top:0">مدیران CMS</h1>
<p class="cms-muted">
  حساب‌های ورود به پنل مدیریت وب. همه مدیران دسترسی یکسان دارند؛ فعالیت‌ها در
  <a href="admin-activity.php">فعالیت مدیران</a> ثبت می‌شود.
</p>

<div class="cms-btn-row">
  <a class="cms-btn" href="admin-users.php?new=1">افزودن مدیر</a>
  <a class="cms-btn cms-btn--secondary" href="admin-activity.php">فعالیت مدیران</a>
</div>

<?php if ($showForm): ?>
<div class="cms-panel" style="margin-top:1rem">
  <h2><?= $edit ? 'ویرایش مدیر' : 'مدیر جدید' ?></h2>
  <form method="post" class="cms-form">
    <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <label class="cms-field">
      <span class="cms-label">نام کاربری (انگلیسی)</span>
      <input class="cms-input" name="username" dir="ltr" required
             value="<?= cms_h((string) ($edit['username'] ?? '')) ?>"
             autocomplete="off">
    </label>
    <label class="cms-field">
      <span class="cms-label"><?= $edit ? 'رمز عبور (خالی = بدون تغییر)' : 'رمز عبور' ?></span>
      <input class="cms-input" type="password" name="password" dir="ltr"
             <?= $edit ? '' : 'required' ?> autocomplete="new-password">
    </label>
    <label class="cms-field cms-field--inline">
      <input type="checkbox" name="is_active" value="1"
        <?= !isset($edit['is_active']) || ($edit['is_active'] ?? true) ? 'checked' : '' ?>>
      <span>فعال (اجازه ورود)</span>
    </label>
    <div class="cms-btn-row">
      <button class="cms-btn" type="submit">ذخیره</button>
      <a class="cms-btn cms-btn--secondary" href="admin-users.php">انصراف</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="cms-panel" style="margin-top:1rem">
  <h2>فهرست مدیران</h2>
  <?php if ($users === []): ?>
    <p class="cms-empty">مدیری ثبت نشده.</p>
  <?php else: ?>
    <table class="cms-table">
      <thead>
        <tr>
          <th>نام کاربری</th>
          <th>وضعیت</th>
          <th>تاریخ ایجاد</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $user): ?>
        <tr>
          <td dir="ltr"><?= cms_h((string) $user['username']) ?></td>
          <td><?= ($user['is_active'] ?? true) ? 'فعال' : 'غیرفعال' ?></td>
          <td dir="ltr"><?= cms_h((string) $user['created_at']) ?></td>
          <td>
            <a class="cms-btn cms-btn--ghost" href="admin-users.php?edit=<?= (int) $user['id'] ?>">ویرایش</a>
            <?php if ((int) $user['id'] !== cms_current_admin_id()): ?>
              <a class="cms-btn cms-btn--ghost"
                 href="admin-users.php?delete=<?= (int) $user['id'] ?>"
                 onclick="return confirm('حذف این مدیر؟');">حذف</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php cms_layout_end(); ?>
