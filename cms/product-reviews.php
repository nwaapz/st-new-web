<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();
$pdo = cms_pdo();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS product_reviews (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      product_id INT UNSIGNED NOT NULL,
      author_name VARCHAR(128) NOT NULL,
      rating TINYINT UNSIGNED NOT NULL,
      body TEXT NOT NULL,
      status ENUM(\'pending\',\'approved\',\'rejected\') NOT NULL DEFAULT \'pending\',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_product_reviews_product_status (product_id, status),
      CONSTRAINT fk_product_review_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : 'pending';
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewId = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($reviewId <= 0) {
            throw new RuntimeException('نظر نامعتبر');
        }
        if ($action === 'approve') {
            $stmt = $pdo->prepare('UPDATE product_reviews SET status = \'approved\' WHERE id = ?');
            $stmt->execute([$reviewId]);
            cms_flash('نظر تأیید شد');
        } elseif ($action === 'reject') {
            $stmt = $pdo->prepare('UPDATE product_reviews SET status = \'rejected\' WHERE id = ?');
            $stmt->execute([$reviewId]);
            cms_flash('نظر رد شد');
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM product_reviews WHERE id = ?');
            $stmt->execute([$reviewId]);
            cms_flash('نظر حذف شد');
        } else {
            throw new RuntimeException('عملیات نامعتبر');
        }
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    $redir = 'product-reviews.php';
    if ($statusFilter !== 'pending') {
        $redir .= '?status=' . rawurlencode($statusFilter);
    }
    cms_redirect($redir);
}

$counts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];
foreach (array_keys($counts) as $st) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_reviews WHERE status = ?');
    $stmt->execute([$st]);
    $counts[$st] = (int) $stmt->fetchColumn();
}

$sql = 'SELECT r.*, p.name AS product_name, p.slug AS product_slug
        FROM product_reviews r
        JOIN products p ON p.id = r.product_id';
$params = [];
if ($statusFilter !== 'all') {
    $sql .= ' WHERE r.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$statusLabels = [
    'pending' => 'در انتظار',
    'approved' => 'تأیید شده',
    'rejected' => 'رد شده',
];

cms_layout_start('نظرات محصولات', cms_current_username(), 'shop');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">نظرات و امتیازها</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">نظرات پس از تأیید در صفحه محصول نمایش داده می‌شوند</p>
  </div>
</div>

<div class="cms-btn-row" style="margin-bottom:1rem">
  <a class="cms-btn <?= $statusFilter === 'pending' ? '' : 'cms-btn--secondary' ?>" href="product-reviews.php?status=pending">
    در انتظار (<?= (int) $counts['pending'] ?>)
  </a>
  <a class="cms-btn <?= $statusFilter === 'approved' ? '' : 'cms-btn--secondary' ?>" href="product-reviews.php?status=approved">
    تأیید شده (<?= (int) $counts['approved'] ?>)
  </a>
  <a class="cms-btn <?= $statusFilter === 'rejected' ? '' : 'cms-btn--secondary' ?>" href="product-reviews.php?status=rejected">
    رد شده (<?= (int) $counts['rejected'] ?>)
  </a>
  <a class="cms-btn <?= $statusFilter === 'all' ? '' : 'cms-btn--secondary' ?>" href="product-reviews.php?status=all">همه</a>
</div>

<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">نظری در این وضعیت نیست.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead>
      <tr>
        <th>محصول</th>
        <th>نویسنده</th>
        <th>امتیاز</th>
        <th>متن</th>
        <th>وضعیت</th>
        <th>تاریخ</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?= cms_h($item['product_name']) ?></td>
        <td><?= cms_h($item['author_name']) ?></td>
        <td><?= (int) $item['rating'] ?> / ۵</td>
        <td style="max-width:280px;white-space:pre-wrap"><?= cms_h($item['body']) ?></td>
        <td><?= cms_h($statusLabels[$item['status']] ?? $item['status']) ?></td>
        <td dir="ltr"><?= cms_h((string) $item['created_at']) ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0;flex-wrap:wrap">
            <?php if ($item['status'] !== 'approved'): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <input type="hidden" name="action" value="approve">
                <button class="cms-btn cms-btn--secondary" type="submit">تأیید</button>
              </form>
            <?php endif; ?>
            <?php if ($item['status'] !== 'rejected'): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button class="cms-btn cms-btn--ghost" type="submit">رد</button>
              </form>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('حذف نظر؟')">
              <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button class="cms-btn cms-btn--ghost" type="submit">حذف</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php cms_layout_end(); ?>
