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
    $stmt = $pdo->prepare('SELECT * FROM sales_users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('کاربر فروش یافت نشد', 'error');
        cms_redirect('sales-users.php');
    }
    $showForm = true;
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM sales_users WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('کاربر فروش حذف شد');
    cms_redirect('sales-users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $username = sales_users_normalize_username((string) ($_POST['username'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;

        if ($username === '' || !sales_users_is_valid_username($username)) {
            throw new RuntimeException('نام کاربری معتبر نیست (۳ تا ۶۴ کاراکتر، حروف انگلیسی و عدد)');
        }
        if ($displayName === '') {
            throw new RuntimeException('نام نمایشی الزامی است');
        }

        $dup = $pdo->prepare('SELECT id FROM sales_users WHERE username = ? AND id <> ? LIMIT 1');
        $dup->execute([$username, $id]);
        if ($dup->fetch()) {
            throw new RuntimeException('این نام کاربری قبلاً ثبت شده است');
        }

        if ($branchId > 0) {
            $branchCheck = $pdo->prepare('SELECT id FROM branches WHERE id = ? LIMIT 1');
            $branchCheck->execute([$branchId]);
            if (!$branchCheck->fetch()) {
                throw new RuntimeException('نماینده انتخاب‌شده نامعتبر است');
            }
        } else {
            $branchId = 0;
        }

        if ($id > 0) {
            if ($password !== '') {
                if (strlen($password) < 6) {
                    throw new RuntimeException('رمز عبور باید حداقل ۶ کاراکتر باشد');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'UPDATE sales_users
                     SET username = ?, display_name = ?, password_hash = ?, branch_id = ?, published = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $username,
                    $displayName,
                    $hash,
                    $branchId > 0 ? $branchId : null,
                    $published,
                    $id,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE sales_users
                     SET username = ?, display_name = ?, branch_id = ?, published = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $username,
                    $displayName,
                    $branchId > 0 ? $branchId : null,
                    $published,
                    $id,
                ]);
            }
            cms_flash('کاربر فروش به‌روز شد');
        } else {
            if ($password === '' || strlen($password) < 6) {
                throw new RuntimeException('رمز عبور باید حداقل ۶ کاراکتر باشد');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                'INSERT INTO sales_users (username, password_hash, display_name, branch_id, published)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $username,
                $hash,
                $displayName,
                $branchId > 0 ? $branchId : null,
                $published,
            ]);
            cms_flash('کاربر فروش اضافه شد');
        }
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('sales-users.php');
}

$users = $pdo->query(
    'SELECT s.id, s.username, s.display_name, s.published, s.created_at, b.name AS branch_name
     FROM sales_users s
     LEFT JOIN branches b ON b.id = s.branch_id
     ORDER BY s.username ASC'
)->fetchAll() ?: [];

$branches = $pdo->query(
    'SELECT id, name, city FROM branches WHERE published = 1 ORDER BY sort_order ASC, name ASC'
)->fetchAll() ?: [];

cms_layout_start('کاربران اپ فروش', cms_current_username(), 'shop');
?>
<h1 style="margin-top:0">کاربران اپ فروش</h1>
<p class="cms-muted">
  حساب‌های ورود اپ اندروید با <strong>نام کاربری و رمز عبور</strong> (بدون OTP).
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
      <span class="cms-label">نام نمایشی</span>
      <input class="cms-input" name="display_name" required
             value="<?= cms_h((string) ($edit['display_name'] ?? '')) ?>">
    </label>
    <label class="cms-field">
      <span class="cms-label"><?= $edit ? 'رمز عبور جدید (خالی = بدون تغییر)' : 'رمز عبور' ?></span>
      <input class="cms-input" type="password" name="password" dir="ltr"
             <?= $edit ? '' : 'required' ?> autocomplete="new-password">
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
        <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
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
          <th>نام کاربری</th>
          <th>نام نمایشی</th>
          <th>نماینده</th>
          <th>وضعیت</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td dir="ltr"><?= cms_h((string) $user['username']) ?></td>
            <td><?= cms_h((string) $user['display_name']) ?></td>
            <td><?= cms_h((string) ($user['branch_name'] ?? '—')) ?></td>
            <td><?= (int) $user['published'] === 1 ? 'فعال' : 'غیرفعال' ?></td>
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
