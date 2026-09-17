<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/admin-audit.php';
require_once __DIR__ . '/lib/admin-users.php';

cms_require_login();
$pdo = cms_pdo();
admin_audit_ensure_schema($pdo);
admin_users_ensure_schema($pdo);

$pageSize = 40;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$adminFilter = isset($_GET['admin']) ? trim((string) $_GET['admin']) : '';
$categoryFilter = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$list = admin_audit_list($pdo, [
    'admin' => $adminFilter,
    'category' => $categoryFilter,
    'q' => $q,
], $page, $pageSize);

$listQs = static function (array $extra = []) use ($adminFilter, $categoryFilter, $q, $page): string {
    $params = array_filter([
        'admin' => $adminFilter,
        'category' => $categoryFilter,
        'q' => $q,
        'page' => $page > 1 ? (string) $page : null,
    ] + $extra, static fn ($v) => $v !== null && $v !== '');
    $qs = http_build_query($params);

    return 'admin-activity.php' . ($qs !== '' ? '?' . $qs : '');
};

$admins = admin_users_list($pdo);
$categories = [
    '' => 'همه',
    'order' => 'سفارش‌ها',
    'catalog' => 'فروشگاه',
    'media' => 'رسانه',
    'comms' => 'پیام و تیکت',
    'settings' => 'تنظیمات و محتوا',
    'admin' => 'مدیران',
];

cms_layout_start('فعالیت مدیران', cms_current_username(), 'advanced');
?>
<h1 style="margin-top:0">فعالیت مدیران</h1>
<p class="cms-muted">ثبت اقدامات انجام‌شده در پنل CMS — چه کسی چه کاری انجام داده است.</p>

<form method="get" class="cms-form cms-form--inline" style="margin-bottom:1rem">
  <label class="cms-field">
    <span>مدیر</span>
    <input class="cms-input" name="admin" dir="ltr" value="<?= cms_h($adminFilter) ?>" placeholder="admin001">
  </label>
  <label class="cms-field">
    <span>دسته</span>
    <select class="cms-input" name="category">
      <?php foreach ($categories as $key => $label): ?>
        <option value="<?= cms_h($key) ?>" <?= $categoryFilter === $key ? 'selected' : '' ?>><?= cms_h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="cms-field">
    <span>جستجو</span>
    <input class="cms-input" name="q" value="<?= cms_h($q) ?>" placeholder="کد سفارش، نام محصول…">
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">فیلتر</button>
    <a class="cms-btn cms-btn--secondary" href="admin-activity.php">پاک کردن</a>
  </div>
</form>

<div class="cms-panel">
  <?php if ($list['items'] === []): ?>
    <p class="cms-empty">فعالیتی ثبت نشده.</p>
  <?php else: ?>
    <p class="cms-muted" style="margin-top:0">
      <?= number_format($list['total']) ?> مورد — صفحه <?= (int) $list['page'] ?> از <?= (int) $list['total_pages'] ?>
    </p>
    <table class="cms-table">
      <thead>
        <tr>
          <th>زمان</th>
          <th>مدیر</th>
          <th>عملیات</th>
          <th>خلاصه</th>
          <th>موضوع</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($list['items'] as $row): ?>
        <?php $href = admin_audit_entity_href($row['entity_type'], $row['entity_id']); ?>
        <tr>
          <td dir="ltr"><?= cms_h((string) $row['created_at']) ?></td>
          <td dir="ltr"><?= cms_h((string) $row['admin_username']) ?></td>
          <td><?= cms_h((string) $row['action_label']) ?></td>
          <td><?= cms_h((string) $row['summary']) ?></td>
          <td>
            <?php if ($href !== null): ?>
              <a href="<?= cms_h($href) ?>"><?= cms_h((string) ($row['entity_label'] ?: 'مشاهده')) ?></a>
            <?php elseif (!empty($row['entity_label'])): ?>
              <?= cms_h((string) $row['entity_label']) ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($list['total_pages'] > 1): ?>
      <div class="cms-btn-row" style="margin-top:1rem">
        <?php if ($list['page'] > 1): ?>
          <a class="cms-btn cms-btn--secondary" href="<?= cms_h($listQs(['page' => (string) ($list['page'] - 1)])) ?>">قبلی</a>
        <?php endif; ?>
        <?php if ($list['page'] < $list['total_pages']): ?>
          <a class="cms-btn cms-btn--secondary" href="<?= cms_h($listQs(['page' => (string) ($list['page'] + 1)])) ?>">بعدی</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php cms_layout_end(); ?>
