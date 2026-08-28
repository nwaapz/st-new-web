<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/uploads.php';

cms_require_login();

$images = cms_scan_upload_images();
$totalBytes = array_sum(array_column($images, 'size'));

cms_layout_start('کتابخانه تصاویر', cms_current_username(), 'shop');
?>
<h1 style="margin-top:0">کتابخانه تصاویر سرور</h1>
<p class="cms-muted">
  همه تصاویر ذخیره‌شده در <code dir="ltr">/uploads/</code> (شامل زیرپوشه‌ها).
  در صورت استفاده در محصول یا دسته، پس از حذف آن بخش تصویر شکسته می‌شود — فقط فایل‌های اضافی را پاک کنید.
</p>

<div class="cms-panel cms-media-library">
  <div class="cms-media-library__toolbar">
    <label class="cms-field cms-media-library__search">
      <span class="cms-label">جستجو در نام فایل</span>
      <input class="cms-input" type="search" id="cms-media-library-search" placeholder="مثال: RAVI یا st-web" autocomplete="off">
    </label>
    <p class="cms-muted cms-media-library__stats" id="cms-media-library-stats">
      <?= count($images) ?> تصویر — <?= cms_h(cms_format_upload_bytes($totalBytes)) ?>
    </p>
    <a class="cms-btn cms-btn--secondary" href="media-upload.php">آپلود تصاویر</a>
  </div>

  <?php if ($images === []): ?>
    <p class="cms-muted" id="cms-media-library-empty">هنوز تصویری روی سرور نیست.</p>
  <?php else: ?>
    <div class="cms-media-grid cms-media-library__grid" id="cms-media-library-grid">
      <?php foreach ($images as $item): ?>
        <?php
        $mtime = date('Y-m-d H:i', $item['mtime']);
        $sizeText = cms_format_upload_bytes($item['size']);
        ?>
        <article
          class="cms-media-item cms-media-item--static cms-media-library__item"
          data-path="<?= cms_h($item['path']) ?>"
          data-name="<?= cms_h(strtolower($item['name'])) ?>"
          title="<?= cms_h($item['path']) ?>"
        >
          <a class="cms-media-library__thumb" href="<?= cms_h($item['url']) ?>" target="_blank" rel="noopener">
            <img src="<?= cms_h($item['url']) ?>" alt="">
          </a>
          <span class="cms-media-library__name" dir="ltr"><?= cms_h($item['name']) ?></span>
          <span class="cms-media-library__meta"><?= cms_h($sizeText) ?> · <?= cms_h($mtime) ?></span>
          <?php if ($item['relative'] !== $item['name']): ?>
            <span class="cms-media-library__folder" dir="ltr"><?= cms_h(dirname($item['relative']) === '.' ? '' : dirname($item['relative'])) ?></span>
          <?php endif; ?>
          <button type="button" class="cms-btn cms-btn--ghost cms-btn--danger cms-media-library__delete" data-path="<?= cms_h($item['path']) ?>">
            حذف
          </button>
        </article>
      <?php endforeach; ?>
    </div>
    <p class="cms-muted cms-media-library__filtered" id="cms-media-library-filtered" hidden></p>
  <?php endif; ?>
</div>

<script src="assets/cms-media-library.js?v=1"></script>
<?php
cms_layout_end();
