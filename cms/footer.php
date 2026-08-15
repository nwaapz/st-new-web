<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/footer.php';

cms_require_login();

$networks = footer_networks();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? 'save');
        $config = footer_editor_state_from_post();

        if ($action === 'add_social') {
            if (count($config['socials']) >= FOOTER_SOCIAL_MAX) {
                throw new RuntimeException('حداکثر ' . FOOTER_SOCIAL_MAX . ' شبکه اجتماعی مجاز است');
            }
            $used = [];
            foreach ($config['socials'] as $row) {
                $used[(string) $row['network']] = true;
            }
            $next = 'custom';
            foreach ($networks as $id => $_meta) {
                if (!isset($used[$id])) {
                    $next = $id;
                    break;
                }
            }
            $config['socials'][] = footer_blank_social($next);
            footer_save_config($config);
            cms_flash('شبکه اجتماعی اضافه شد — لینک را وارد و ذخیره کنید');
            cms_redirect('footer.php');
        }

        if ($action === 'delete_social') {
            $index = (int) ($_POST['delete_index'] ?? -1);
            if ($index < 0 || $index >= count($config['socials'])) {
                throw new RuntimeException('مورد نامعتبر است');
            }
            array_splice($config['socials'], $index, 1);
            footer_save_config($config);
            cms_flash('شبکه اجتماعی حذف شد');
            cms_redirect('footer.php');
        }

        if ($action === 'add_link') {
            if (count($config['links']) >= FOOTER_LINK_MAX) {
                throw new RuntimeException('حداکثر ' . FOOTER_LINK_MAX . ' پیوند مجاز است');
            }
            $config['links'][] = footer_blank_link();
            footer_save_config($config);
            cms_flash('پیوند اضافه شد');
            cms_redirect('footer.php');
        }

        if ($action === 'delete_link') {
            $index = (int) ($_POST['delete_index'] ?? -1);
            if ($index < 0 || $index >= count($config['links'])) {
                throw new RuntimeException('مورد نامعتبر است');
            }
            array_splice($config['links'], $index, 1);
            footer_save_config($config);
            cms_flash('پیوند حذف شد');
            cms_redirect('footer.php');
        }

        footer_save_config($config);
        cms_flash('پاورقی ذخیره شد');
        cms_redirect('footer.php');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect('footer.php');
    }
}

$config = footer_load_config();
$linkCount = count($config['links']);
$socialCount = count($config['socials']);

cms_layout_start('پاورقی', cms_current_username(), 'website');
?>
<h1 style="margin-top:0">پاورقی سایت</h1>
<p class="cms-muted">
  تمام متن‌ها، پیوندها و شبکه‌های اجتماعی فوتر از اینجا تنظیم می‌شود.
  شبکه‌ها را اضافه یا حذف کنید؛ لینک خالی در سایت نشان داده نمی‌شود.
  لوگوهای کارخانه از
  <a href="factories.php" style="color:#e8d4b0;text-decoration:underline">کارخانه‌ها</a>
  می‌آید.
</p>

<form method="post" id="footer-form">
  <input type="hidden" name="link_count" value="<?= (int) $linkCount ?>">
  <input type="hidden" name="social_count" value="<?= (int) $socialCount ?>">
  <input type="hidden" name="action" id="footer-action" value="save">
  <input type="hidden" name="delete_index" id="footer-delete-index" value="-1">

  <div class="cms-btn-row" style="margin-top:0;margin-bottom:1rem">
    <button class="cms-btn" type="submit" onclick="document.getElementById('footer-action').value='save'">
      ذخیره پاورقی
    </button>
  </div>

  <div class="cms-panel">
    <h2 style="margin-top:0">متن‌ها</h2>
    <label class="cms-field">
      <span class="cms-label">شعار کنار لوگو</span>
      <input class="cms-input" name="tagline" value="<?= cms_h((string) $config['tagline']) ?>">
    </label>
    <label class="cms-field">
      <span class="cms-label">کپی‌رایت</span>
      <input class="cms-input" name="copyright" value="<?= cms_h((string) $config['copyright']) ?>">
      <span class="cms-muted" style="font-size:.82rem">از <code>{year}</code> برای سال جاری استفاده کنید.</span>
    </label>
    <label class="cms-field">
      <span class="cms-label">عنوان فهرست پیوندها</span>
      <input class="cms-input" name="navLabel" value="<?= cms_h((string) $config['navLabel']) ?>">
    </label>
    <label class="cms-field">
      <span class="cms-label">عنوان شبکه‌های اجتماعی</span>
      <input class="cms-input" name="socialLabel" value="<?= cms_h((string) $config['socialLabel']) ?>">
    </label>
    <label class="cms-field">
      <span class="cms-label">عنوان کارخانه‌ها در فوتر</span>
      <input class="cms-input" name="factoriesLabel" value="<?= cms_h((string) $config['factoriesLabel']) ?>">
    </label>
    <label class="cms-check">
      <input type="checkbox" name="showFactories" <?= !empty($config['showFactories']) ? 'checked' : '' ?>>
      نمایش لوگوهای کارخانه در فوتر
    </label>
  </div>

  <div class="cms-panel">
    <h2 style="margin-top:0">راه‌های تماس در فوتر</h2>
    <p class="cms-muted" style="margin:.25rem 0 1rem">خالی بگذارید تا آن خط دیده نشود.</p>
    <div class="cms-grid-2">
      <label class="cms-field">
        <span class="cms-label">تلفن</span>
        <input class="cms-input" name="phone" dir="ltr" value="<?= cms_h((string) $config['phone']) ?>" placeholder="021xxxxxxx">
      </label>
      <label class="cms-field">
        <span class="cms-label">واتساپ</span>
        <input class="cms-input" name="whatsapp" dir="ltr" value="<?= cms_h((string) $config['whatsapp']) ?>" placeholder="0912xxxxxxx">
      </label>
      <label class="cms-field">
        <span class="cms-label">ایمیل</span>
        <input class="cms-input" name="email" dir="ltr" value="<?= cms_h((string) $config['email']) ?>" placeholder="info@example.com">
      </label>
      <label class="cms-field">
        <span class="cms-label">آدرس</span>
        <input class="cms-input" name="address" value="<?= cms_h((string) $config['address']) ?>">
      </label>
    </div>
  </div>

  <div class="cms-panel">
    <h2 style="margin-top:0">اعتبار طراحی</h2>
    <label class="cms-check">
      <input type="checkbox" name="showCredit" <?= !empty($config['showCredit']) ? 'checked' : '' ?>>
      نمایش لینک اعتبار
    </label>
    <label class="cms-field">
      <span class="cms-label">متن اعتبار</span>
      <input class="cms-input" name="creditText" value="<?= cms_h((string) $config['creditText']) ?>">
    </label>
    <label class="cms-field">
      <span class="cms-label">آدرس اعتبار</span>
      <input class="cms-input" name="creditHref" dir="ltr" value="<?= cms_h((string) $config['creditHref']) ?>">
    </label>
  </div>

  <div class="cms-panel">
    <div class="cms-btn-row" style="margin-top:0;justify-content:space-between;align-items:center">
      <h2 style="margin:0">پیوندهای فوتر</h2>
      <?php if ($linkCount < FOOTER_LINK_MAX): ?>
        <button
          class="cms-btn cms-btn--secondary"
          type="submit"
          onclick="document.getElementById('footer-action').value='add_link'"
        >
          + افزودن پیوند
        </button>
      <?php endif; ?>
    </div>
    <?php if ($linkCount === 0): ?>
      <p class="cms-muted">هنوز پیوندی نیست.</p>
    <?php endif; ?>
    <?php foreach ($config['links'] as $i => $link): ?>
      <div class="cms-grid-2" style="align-items:end;margin-bottom:.65rem">
        <label class="cms-field" style="margin-bottom:0">
          <span class="cms-label">متن</span>
          <input class="cms-input" name="link_label_<?= (int) $i ?>" value="<?= cms_h((string) $link['label']) ?>">
        </label>
        <label class="cms-field" style="margin-bottom:0">
          <span class="cms-label">آدرس</span>
          <input class="cms-input" name="link_href_<?= (int) $i ?>" dir="ltr" value="<?= cms_h((string) $link['href']) ?>" placeholder="/about یا https://...">
        </label>
      </div>
      <div class="cms-btn-row" style="margin-top:.35rem;margin-bottom:1rem">
        <button
          class="cms-btn cms-btn--ghost"
          type="submit"
          onclick="document.getElementById('footer-action').value='delete_link';document.getElementById('footer-delete-index').value='<?= (int) $i ?>';return confirm('حذف این پیوند؟');"
        >
          حذف پیوند
        </button>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cms-panel">
    <div class="cms-btn-row" style="margin-top:0;justify-content:space-between;align-items:center">
      <h2 style="margin:0">شبکه‌های اجتماعی</h2>
      <?php if ($socialCount < FOOTER_SOCIAL_MAX): ?>
        <button
          class="cms-btn cms-btn--secondary"
          type="submit"
          onclick="document.getElementById('footer-action').value='add_social'"
        >
          + افزودن شبکه
        </button>
      <?php endif; ?>
    </div>
    <p class="cms-muted">
      اینستاگرام، تلگرام، واتساپ، آپارات، روبیکا، بلد، بله، ایتا، یوتیوب، لینکدین و بقیه.
    </p>
    <?php if ($socialCount === 0): ?>
      <p class="cms-muted">شبکه‌ای ثبت نشده. با دکمه بالا اضافه کنید.</p>
    <?php endif; ?>
    <?php foreach ($config['socials'] as $i => $social): ?>
      <?php
        $network = (string) $social['network'];
        $placeholder = $networks[$network]['placeholder'] ?? 'https://...';
      ?>
      <div class="cms-grid-2" style="align-items:end">
        <label class="cms-field">
          <span class="cms-label">شبکه</span>
          <select class="cms-select" name="social_network_<?= (int) $i ?>">
            <?php foreach ($networks as $id => $meta): ?>
              <option value="<?= cms_h($id) ?>" <?= $network === $id ? 'selected' : '' ?>>
                <?= cms_h($meta['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="cms-field">
          <span class="cms-label">برچسب (برای دسترس‌پذیری)</span>
          <input class="cms-input" name="social_label_<?= (int) $i ?>" value="<?= cms_h((string) $social['label']) ?>">
        </label>
      </div>
      <label class="cms-field">
        <span class="cms-label">لینک</span>
        <input
          class="cms-input"
          name="social_href_<?= (int) $i ?>"
          dir="ltr"
          value="<?= cms_h((string) $social['href']) ?>"
          placeholder="<?= cms_h($placeholder) ?>"
        >
      </label>
      <div class="cms-btn-row" style="margin-top:0;margin-bottom:1.1rem">
        <button
          class="cms-btn cms-btn--ghost"
          type="submit"
          onclick="document.getElementById('footer-action').value='delete_social';document.getElementById('footer-delete-index').value='<?= (int) $i ?>';return confirm('حذف این شبکه؟');"
        >
          حذف شبکه
        </button>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit" onclick="document.getElementById('footer-action').value='save'">
      ذخیره پاورقی
    </button>
  </div>
</form>
<?php cms_layout_end(); ?>
