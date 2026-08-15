<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/messages.php';

cms_require_login();
$pdo = cms_pdo();
messages_ensure_schema($pdo);

$supportUnread = 0;
$branchMsgUnread = 0;
$ticketUnread = 0;

try {
    $supportUnread = (int) $pdo->query(
        "SELECT COUNT(*) FROM site_messages
         WHERE channel = 'support' AND actor = 'client' AND admin_read_at IS NULL"
    )->fetchColumn();
} catch (Throwable $e) {
    /* ignore */
}

try {
    $branchMsgUnread = (int) $pdo->query(
        "SELECT COUNT(*) FROM site_messages
         WHERE channel = 'branch' AND actor = 'client' AND admin_read_at IS NULL"
    )->fetchColumn();
} catch (Throwable $e) {
    /* ignore */
}

try {
    $ticketUnread = (int) $pdo->query(
        "SELECT COUNT(*) FROM branch_ticket_messages
         WHERE actor = 'branch' AND admin_read_at IS NULL"
    )->fetchColumn();
} catch (Throwable $e) {
    /* table may not exist yet */
}

cms_layout_start('ارتباطات', cms_current_username(), 'communication');
?>
<h1 style="margin-top:0">ارتباطات</h1>
<p class="cms-muted">
  پیام مشتریان سایت، پیام پورتال نمایندگان و تیکت پشتیبانی شعب در این بخش است.
</p>
<div class="cms-grid-comm cms-grid-2">
  <a class="cms-panel cms-hub-card" href="messages.php">
    <h2>پیام مشتریان</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $supportUnread ?></p>
    <p class="cms-muted">جستجو با شماره موبایل — گفتگوی پروفایل و صفحه تماس</p>
  </a>
  <a class="cms-panel cms-hub-card" href="branch-messages.php">
    <h2>پیام نمایندگان</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $branchMsgUnread ?></p>
    <p class="cms-muted">جستجو با نام شعبه — پیام‌های پورتال نمایندگان</p>
  </a>
  <a class="cms-panel cms-hub-card" href="branch-tickets.php">
    <h2>تیکت نمایندگان</h2>
    <p style="font-size:1.6rem;margin:0;font-weight:700"><?= $ticketUnread ?></p>
    <p class="cms-muted">جستجو با نام شعبه — تیکت پشتیبانی با تصویر</p>
  </a>
</div>
<?php cms_layout_end(); ?>
