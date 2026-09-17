<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/admin-attention.php';

cms_require_login();
$pdo = cms_pdo();
$counts = cms_admin_attention_counts($pdo);

$salesUsers = 0;
try {
    $salesUsers = (int) $pdo->query('SELECT COUNT(*) FROM sales_users')->fetchColumn();
} catch (Throwable $e) {
    /* table may not exist yet */
}

cms_layout_start('امور مشتریان', cms_current_username(), 'customers');
?>
<h1 style="margin-top:0">امور مشتریان</h1>
<p class="cms-muted">
  سفارش‌ها، پیام مشتریان و نمایندگان، تیکت شعب و کاربران اپ فروش در این بخش است.
</p>
<div class="cms-grid-comm cms-grid-2">
  <a class="cms-panel cms-hub-card" href="orders.php">
    <h2>سفارش‌ها</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= (int) $counts['new_orders'] ?></p>
    <p class="cms-muted">سفارش‌های جدید در انتظار بررسی انبار</p>
  </a>
  <a class="cms-panel cms-hub-card" href="messages.php">
    <h2>پیام مشتریان</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= (int) $counts['support_unread'] ?></p>
    <p class="cms-muted">جستجو با شماره موبایل — گفتگوی پروفایل و صفحه تماس</p>
  </a>
  <a class="cms-panel cms-hub-card" href="branch-messages.php">
    <h2>پیام نمایندگان</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= (int) $counts['branch_msg_unread'] ?></p>
    <p class="cms-muted">جستجو با نام شعبه — پیام‌های پورتال نمایندگان</p>
  </a>
  <a class="cms-panel cms-hub-card" href="branch-tickets.php">
    <h2>تیکت نمایندگان</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= (int) $counts['ticket_unread'] ?></p>
    <p class="cms-muted">جستجو با نام شعبه — تیکت پشتیبانی با تصویر</p>
  </a>
  <a class="cms-panel cms-hub-card" href="sales-users.php">
    <h2>کاربران اپ فروش</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $salesUsers ?></p>
    <p class="cms-muted">حساب‌های ورود اپ فروش نمایندگان</p>
  </a>
</div>
<?php cms_layout_end(); ?>
