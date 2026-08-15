<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/messages.php';
require_once dirname(__DIR__) . '/api/_auth.php';

cms_require_login();
$pdo = cms_pdo();
site_auth_ensure_schema($pdo);
messages_ensure_schema($pdo);

$viewUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$nameQuery = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $body = trim((string) ($_POST['body'] ?? ''));
    try {
        if ($userId <= 0) {
            throw new RuntimeException('کاربر نامعتبر');
        }
        $check = $pdo->prepare('SELECT id, phone FROM site_users WHERE id = ? LIMIT 1');
        $check->execute([$userId]);
        if (!$check->fetch()) {
            throw new RuntimeException('کاربر یافت نشد');
        }
        $ctx = messages_latest_branch_context($pdo, $userId);
        if ($ctx === null) {
            throw new RuntimeException('این کاربر پیام نمایندگی ندارد');
        }
        messages_add($pdo, $userId, $body, 'admin', $ctx);
        messages_mark_client_messages_read($pdo, $userId);
        cms_flash('پاسخ نمایندگی ارسال شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('branch-messages.php?user_id=' . $userId);
}

$conversations = messages_list_conversations($pdo, 200, 'branch');
if ($nameQuery !== '') {
    $needle = mb_strtolower($nameQuery);
    $phoneNeedle = messages_phone_digits($nameQuery);
    $conversations = array_values(array_filter(
        $conversations,
        static function (array $c) use ($needle, $phoneNeedle): bool {
            $hay = mb_strtolower(
                (string) ($c['last_branch_name'] ?? '') . ' '
                . (string) ($c['last_province_name'] ?? '') . ' '
                . (string) ($c['phone'] ?? '')
            );
            if ($needle !== '' && mb_strpos($hay, $needle) !== false) {
                return true;
            }
            if ($phoneNeedle !== '' && strpos((string) ($c['phone'] ?? ''), $phoneNeedle) !== false) {
                return true;
            }
            return false;
        }
    ));
}

$viewUser = null;
$viewThread = [];
$viewCtx = null;
if ($viewUserId > 0) {
    $stmt = $pdo->prepare('SELECT id, phone FROM site_users WHERE id = ? LIMIT 1');
    $stmt->execute([$viewUserId]);
    $viewUser = $stmt->fetch() ?: null;
    if ($viewUser) {
        $all = messages_fetch_thread($pdo, $viewUserId);
        foreach ($all as $m) {
            if (($m['channel'] ?? 'support') === 'branch') {
                $viewThread[] = $m;
            }
        }
        $viewCtx = messages_latest_branch_context($pdo, $viewUserId);
        messages_mark_client_messages_read($pdo, $viewUserId);
    }
}

$listHref = $nameQuery !== '' ? 'branch-messages.php?q=' . rawurlencode($nameQuery) : 'branch-messages.php';

cms_layout_start('پیام نمایندگان', cms_current_username(), 'communication');
?>
<div class="cms-page-head">
  <div>
    <h1 style="margin:0">پیام‌های پورتال نمایندگان</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">گفتگوهای مرتبط با شعب — جستجو با نام شعبه یا استان</p>
  </div>
</div>

<form class="cms-search" method="get" action="branch-messages.php">
  <input class="cms-input" type="search" name="q" value="<?= cms_h($nameQuery) ?>" placeholder="جستجو با نام شعبه یا استان…">
  <?php if ($viewUserId > 0): ?>
    <input type="hidden" name="user_id" value="<?= $viewUserId ?>">
  <?php endif; ?>
  <button class="cms-btn" type="submit">جستجو</button>
  <?php if ($nameQuery !== ''): ?>
    <a class="cms-btn cms-btn--secondary" href="branch-messages.php">پاک کردن</a>
  <?php endif; ?>
</form>

<div class="<?= $viewUser ? 'cms-comm-split' : '' ?>">
  <div class="cms-panel">
    <h2 style="margin:0 0 .75rem;font-size:1rem">گفتگوها</h2>
    <?php if ($conversations === []): ?>
      <p class="cms-empty"><?= $nameQuery !== '' ? 'شعبه یا گفتگویی با این نام پیدا نشد.' : 'هنوز پیامی از پورتال نمایندگان نیامده.' ?></p>
    <?php else: ?>
      <div class="cms-inbox-wrap">
        <table class="cms-table cms-table--inbox">
          <thead>
            <tr>
              <th class="cms-inbox-phone">موبایل</th>
              <th class="cms-inbox-meta">استان / شعبه</th>
              <th class="cms-inbox-preview">آخرین پیام</th>
              <th class="cms-inbox-unread">خوانده‌نشده</th>
              <th class="cms-inbox-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($conversations as $c): ?>
            <?php
              $uid = (int) $c['user_id'];
              $openHref = 'branch-messages.php?user_id=' . $uid . ($nameQuery !== '' ? '&q=' . rawurlencode($nameQuery) : '');
            ?>
            <tr class="cms-inbox-row<?= $uid === $viewUserId ? ' is-active' : '' ?><?= (int) $c['unread_admin'] > 0 ? ' cms-row--client' : '' ?>">
              <td class="cms-inbox-phone" dir="ltr"><a href="<?= cms_h($openHref) ?>"><?= cms_h((string) $c['phone']) ?></a></td>
              <td class="cms-inbox-meta">
                <?= cms_h((string) ($c['last_province_name'] ?? '—')) ?>
                <?php if (!empty($c['last_branch_name'])): ?>
                  <br><span class="cms-muted" style="font-size:.8rem"><?= cms_h((string) $c['last_branch_name']) ?></span>
                <?php endif; ?>
              </td>
              <td class="cms-inbox-preview" title="<?= cms_h((string) $c['last_body']) ?>">
                <?php if ((string) ($c['last_actor'] ?? '') === 'client'): ?>
                  <span class="cms-client-msg__badge">مشتری</span>
                <?php else: ?>
                  <span class="cms-muted">ادمین</span>
                <?php endif; ?>
                <?= cms_h(mb_substr((string) $c['last_body'], 0, 120)) ?>
              </td>
              <td class="cms-inbox-unread">
                <?php if ((int) $c['unread_admin'] > 0): ?>
                  <span class="cms-client-msg__badge"><?= (int) $c['unread_admin'] ?></span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="cms-inbox-actions">
                <a class="cms-inbox-open" href="<?= cms_h($openHref) ?>">باز کردن</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($viewUser): ?>
  <div class="cms-panel">
    <div class="cms-page-head" style="margin-bottom:.75rem">
      <div>
        <h2 style="margin:0;font-size:1rem">گفتگو با <span dir="ltr"><?= cms_h((string) $viewUser['phone']) ?></span></h2>
        <?php if ($viewCtx): ?>
          <p class="cms-muted" style="margin:.35rem 0 0;font-size:.85rem">
            <?= cms_h((string) ($viewCtx['province_name'] ?? '')) ?>
            <?php if (!empty($viewCtx['branch_name'])): ?>
              — <?= cms_h((string) $viewCtx['branch_name']) ?>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>
      <a class="cms-btn cms-btn--secondary" href="<?= cms_h($listHref) ?>">بستن</a>
    </div>

    <div class="cms-msg-thread">
      <?php if ($viewThread === []): ?>
        <p class="cms-muted">پیام نمایندگی نیست.</p>
      <?php else: ?>
        <?php foreach ($viewThread as $m): ?>
          <?php $fromClient = (string) $m['actor'] === 'client'; ?>
          <div class="<?= $fromClient ? 'cms-client-msg' : 'cms-payment-answered' ?>" style="margin:0">
            <strong class="<?= $fromClient ? 'cms-client-msg__badge' : 'cms-payment-answered__badge' ?>">
              <?= $fromClient ? 'مشتری' : 'ادمین' ?>
            </strong>
            <?php if (!empty($m['province_name']) || !empty($m['branch_name'])): ?>
              <p class="cms-muted" style="margin:.25rem 0 0;font-size:.75rem">
                <?= cms_h(trim((string) ($m['province_name'] ?? '') . (isset($m['branch_name']) && $m['branch_name'] ? ' / ' . $m['branch_name'] : ''))) ?>
              </p>
            <?php endif; ?>
            <p class="<?= $fromClient ? 'cms-client-msg__text' : 'cms-payment-answered__text' ?>"><?= cms_h((string) $m['body']) ?></p>
            <p class="cms-muted" style="margin:.35rem 0 0;font-size:.75rem" dir="ltr"><?= cms_h((string) $m['created_at']) ?></p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <form method="post" class="cms-form">
      <input type="hidden" name="user_id" value="<?= (int) $viewUser['id'] ?>">
      <label class="cms-field">
        <span>پاسخ به ارتباط نمایندگی</span>
        <textarea class="cms-input" name="body" rows="3" required placeholder="پاسخ برای مشتری…"></textarea>
      </label>
      <div class="cms-btn-row" style="margin-top:.75rem">
        <button class="cms-btn" type="submit" <?= $viewCtx ? '' : 'disabled' ?>>ارسال پاسخ</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php
cms_layout_end();
