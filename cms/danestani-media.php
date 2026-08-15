<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/page-intros.php';

cms_require_login();
$pdo = cms_pdo();

const DANESTANI_SLIDE_MAX = 12;

function tech_header_clamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tech_header'])) {
    try {
        $existing = cms_setting_get('tech_header_image', '');
        $image = cms_handle_optional_upload('tech_header_image', $existing);
        $zoom = tech_header_clamp((float) ($_POST['tech_header_zoom'] ?? 100), 100, 400);
        $posX = tech_header_clamp((float) ($_POST['tech_header_pos_x'] ?? 50), 0, 100);
        $posY = tech_header_clamp((float) ($_POST['tech_header_pos_y'] ?? 50), 0, 100);

        cms_setting_set('tech_header_image', $image);
        cms_setting_set('tech_header_zoom', (string) $zoom);
        cms_setting_set('tech_header_pos_x', (string) $posX);
        cms_setting_set('tech_header_pos_y', (string) $posY);
        cms_flash($image !== '' ? 'تصویر هدر تکنولوژی ذخیره شد' : 'تصویر هدر تکنولوژی حذف شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('danestani-media.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_page_intro'])) {
    try {
        cms_page_intro_save(
            'tech_header',
            (string) ($_POST['intro_title'] ?? ''),
            (string) ($_POST['intro_explanation'] ?? '')
        );
        cms_flash('متن هدر تکنولوژی ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('danestani-media.php');
}

function danestani_ensure_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS danestani_media_frames (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          title VARCHAR(191) NOT NULL,
          subtitle VARCHAR(255) NULL,
          badge VARCHAR(128) NULL,
          explanation TEXT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          published TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS danestani_media_slides (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          frame_id INT UNSIGNED NOT NULL,
          image VARCHAR(512) NOT NULL,
          alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
          caption VARCHAR(512) NULL,
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          KEY idx_danestani_slides_frame (frame_id),
          CONSTRAINT fk_danestani_slide_frame
            FOREIGN KEY (frame_id) REFERENCES danestani_media_frames(id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

function danestani_blank_slide(): array
{
    return [
        'image' => '/images/thermal-image.jfif',
        'alt_text' => '',
        'caption' => '',
    ];
}

function danestani_load_slides(PDO $pdo, int $frameId): array
{
    $stmt = $pdo->prepare(
        'SELECT image, alt_text, caption, sort_order
         FROM danestani_media_slides
         WHERE frame_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$frameId]);
    $rows = $stmt->fetchAll();
    $slides = [];
    foreach ($rows as $row) {
        $slides[] = [
            'image' => (string) $row['image'],
            'alt_text' => (string) ($row['alt_text'] ?? ''),
            'caption' => (string) ($row['caption'] ?? ''),
        ];
    }
    return $slides;
}

function danestani_collect_slides_from_post(int $count): array
{
    $slides = [];
    for ($i = 0; $i < $count; $i++) {
        $image = cms_handle_optional_upload(
            'slide_image_' . $i,
            (string) ($_POST['slide_image_' . $i] ?? '')
        );
        $alt = trim((string) ($_POST['slide_alt_' . $i] ?? ''));
        $caption = trim((string) ($_POST['slide_caption_' . $i] ?? ''));
        if ($image === '') {
            throw new RuntimeException('اسلاید ' . ($i + 1) . ': تصویر الزامی است');
        }
        $slides[] = [
            'image' => $image,
            'alt_text' => $alt,
            'caption' => $caption,
        ];
    }
    return $slides;
}

function danestani_replace_slides(PDO $pdo, int $frameId, array $slides): void
{
    $pdo->prepare('DELETE FROM danestani_media_slides WHERE frame_id = ?')->execute([$frameId]);
    if ($slides === []) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO danestani_media_slides (frame_id, image, alt_text, caption, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($slides as $index => $slide) {
        $stmt->execute([
            $frameId,
            $slide['image'],
            $slide['alt_text'],
            $slide['caption'] !== '' ? $slide['caption'] : null,
            $index,
        ]);
    }
}

danestani_ensure_tables($pdo);

$edit = null;
$slides = [];
$showForm = isset($_GET['new']) || isset($_GET['edit']);

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM danestani_media_frames WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('قاب یافت نشد', 'error');
        cms_redirect('danestani-media.php');
    }
    $showForm = true;
    $slides = danestani_load_slides($pdo, (int) $edit['id']);
    if ($slides === []) {
        $slides = [danestani_blank_slide()];
    }
}

if (isset($_GET['new'])) {
    $slides = [danestani_blank_slide()];
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM danestani_media_frames WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('قاب حذف شد');
    cms_redirect('danestani-media.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        $title = trim((string) ($_POST['title'] ?? ''));
        $subtitle = trim((string) ($_POST['subtitle'] ?? ''));
        $badge = trim((string) ($_POST['badge'] ?? ''));
        $explanation = trim((string) ($_POST['explanation'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;
        $slideCount = max(0, (int) ($_POST['slide_count'] ?? 0));
        if ($slideCount > DANESTANI_SLIDE_MAX) {
            $slideCount = DANESTANI_SLIDE_MAX;
        }

        if ($title === '') {
            throw new RuntimeException('عنوان قاب الزامی است');
        }

        $collected = $slideCount > 0 ? danestani_collect_slides_from_post($slideCount) : [];

        if ($action === 'add_slide') {
            if (count($collected) >= DANESTANI_SLIDE_MAX) {
                throw new RuntimeException('حداکثر ' . DANESTANI_SLIDE_MAX . ' اسلاید مجاز است');
            }
            $collected[] = danestani_blank_slide();
            // Persist frame first so edit redirect works for new items
            $pdo->beginTransaction();
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE danestani_media_frames
                     SET title=?, subtitle=?, badge=?, explanation=?, sort_order=?, published=?
                     WHERE id=?'
                );
                $stmt->execute([
                    $title,
                    $subtitle !== '' ? $subtitle : null,
                    $badge !== '' ? $badge : null,
                    $explanation !== '' ? $explanation : null,
                    $sortOrder,
                    $published,
                    $id,
                ]);
                $frameId = $id;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO danestani_media_frames
                     (title, subtitle, badge, explanation, sort_order, published)
                     VALUES (?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $title,
                    $subtitle !== '' ? $subtitle : null,
                    $badge !== '' ? $badge : null,
                    $explanation !== '' ? $explanation : null,
                    $sortOrder,
                    $published,
                ]);
                $frameId = (int) $pdo->lastInsertId();
            }
            danestani_replace_slides($pdo, $frameId, $collected);
            $pdo->commit();
            cms_flash('اسلاید جدید اضافه شد');
            cms_redirect('danestani-media.php?edit=' . $frameId);
        }

        if ($action === 'delete_slide') {
            $deleteIndex = (int) ($_POST['delete_index'] ?? -1);
            if ($deleteIndex < 0 || $deleteIndex >= count($collected)) {
                throw new RuntimeException('اسلاید نامعتبر است');
            }
            if (count($collected) <= 1) {
                throw new RuntimeException('حداقل یک اسلاید باید باقی بماند');
            }
            array_splice($collected, $deleteIndex, 1);

            $pdo->beginTransaction();
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE danestani_media_frames
                     SET title=?, subtitle=?, badge=?, explanation=?, sort_order=?, published=?
                     WHERE id=?'
                );
                $stmt->execute([
                    $title,
                    $subtitle !== '' ? $subtitle : null,
                    $badge !== '' ? $badge : null,
                    $explanation !== '' ? $explanation : null,
                    $sortOrder,
                    $published,
                    $id,
                ]);
                $frameId = $id;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO danestani_media_frames
                     (title, subtitle, badge, explanation, sort_order, published)
                     VALUES (?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $title,
                    $subtitle !== '' ? $subtitle : null,
                    $badge !== '' ? $badge : null,
                    $explanation !== '' ? $explanation : null,
                    $sortOrder,
                    $published,
                ]);
                $frameId = (int) $pdo->lastInsertId();
            }
            danestani_replace_slides($pdo, $frameId, $collected);
            $pdo->commit();
            cms_flash('اسلاید حذف شد');
            cms_redirect('danestani-media.php?edit=' . $frameId);
        }

        // save
        if ($collected === []) {
            throw new RuntimeException('حداقل یک اسلاید تصویری لازم است');
        }

        $pdo->beginTransaction();
        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE danestani_media_frames
                 SET title=?, subtitle=?, badge=?, explanation=?, sort_order=?, published=?
                 WHERE id=?'
            );
            $stmt->execute([
                $title,
                $subtitle !== '' ? $subtitle : null,
                $badge !== '' ? $badge : null,
                $explanation !== '' ? $explanation : null,
                $sortOrder,
                $published,
                $id,
            ]);
            $frameId = $id;
            cms_flash('قاب به‌روز شد');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO danestani_media_frames
                 (title, subtitle, badge, explanation, sort_order, published)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([
                $title,
                $subtitle !== '' ? $subtitle : null,
                $badge !== '' ? $badge : null,
                $explanation !== '' ? $explanation : null,
                $sortOrder,
                $published,
            ]);
            $frameId = (int) $pdo->lastInsertId();
            cms_flash('قاب اضافه شد');
        }
        danestani_replace_slides($pdo, $frameId, $collected);
        $pdo->commit();
        cms_redirect('danestani-media.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'danestani-media.php?edit=' . $id : 'danestani-media.php?new=1');
    }
}

$items = $pdo->query(
    'SELECT f.*,
            (SELECT COUNT(*) FROM danestani_media_slides s WHERE s.frame_id = f.id) AS slide_count
     FROM danestani_media_frames f
     ORDER BY f.sort_order ASC, f.id ASC'
)->fetchAll();

$techHeaderImage = cms_setting_get('tech_header_image', '');
$techHeaderZoom = tech_header_clamp((float) cms_setting_get('tech_header_zoom', '100'), 100, 400);
$techHeaderPosX = tech_header_clamp((float) cms_setting_get('tech_header_pos_x', '50'), 0, 100);
$techHeaderPosY = tech_header_clamp((float) cms_setting_get('tech_header_pos_y', '50'), 0, 100);
$intro = cms_page_intro_stored('tech_header');
$introDefaults = cms_page_intro_defaults()['tech_header'];

cms_layout_start('تکنولوژی', cms_current_username(), 'website');
?>
<?php if (!$showForm): ?>
<form class="cms-panel" method="post" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">متن هدر صفحه تکنولوژی</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    عنوان و توضیح بالای صفحه. خالی بگذارید تا متن پیش‌فرض سایت استفاده شود.
  </p>
  <input type="hidden" name="save_page_intro" value="1">
  <label class="cms-field">
    <span class="cms-label">عنوان هدر</span>
    <input class="cms-input" name="intro_title" value="<?= cms_h($intro['title']) ?>" placeholder="<?= cms_h($introDefaults['title']) ?>">
  </label>
  <label class="cms-field">
    <span class="cms-label">متن توضیحی</span>
    <textarea class="cms-textarea" name="intro_explanation" rows="3" placeholder="<?= cms_h($introDefaults['explanation']) ?>"><?= cms_h($intro['explanation']) ?></textarea>
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره متن</button>
  </div>
</form>

<form class="cms-panel" method="post" enctype="multipart/form-data" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">تصویر هدر صفحه تکنولوژی</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    کنار عنوان هدر در بالای صفحه نمایش داده می‌شود. تصویر را بکشید و با لغزنده بزرگ‌نمایی کنید
    تا بخش دلخواه داخل کادر بیفتد — مثل تنظیم عکس پروفایل در تلگرام.
  </p>
  <input type="hidden" name="save_tech_header" value="1">
  <?php cms_image_field('tech_header_image', 'تصویر هدر', $techHeaderImage); ?>

  <div class="cms-field">
    <span class="cms-label">تنظیم کادر</span>
    <div id="tech-header-crop-frame" class="cms-crop-frame">
      <img
        id="tech-header-crop-img"
        src="<?= $techHeaderImage !== '' ? cms_h(cms_asset_url($techHeaderImage)) : '' ?>"
        alt=""
        draggable="false"
        style="display:<?= $techHeaderImage !== '' ? 'block' : 'none' ?>"
      >
      <div class="cms-crop-frame__empty" id="tech-header-crop-empty" style="display:<?= $techHeaderImage !== '' ? 'none' : 'grid' ?>">
        ابتدا تصویری انتخاب کنید
      </div>
    </div>
    <p class="cms-crop-hint">با موس یا انگشت بکشید تا جای‌گیری تصویر داخل کادر تغییر کند.</p>
    <div class="cms-crop-zoom-row">
      <span>بزرگ‌نمایی</span>
      <input type="range" id="tech-header-zoom" min="100" max="400" step="1" value="<?= (int) round($techHeaderZoom) ?>">
      <span id="tech-header-zoom-value"><?= (int) round($techHeaderZoom) ?>%</span>
    </div>
    <input type="hidden" name="tech_header_zoom" id="tech-header-zoom-input" value="<?= cms_h((string) $techHeaderZoom) ?>">
    <input type="hidden" name="tech_header_pos_x" id="tech-header-pos-x-input" value="<?= cms_h((string) $techHeaderPosX) ?>">
    <input type="hidden" name="tech_header_pos_y" id="tech-header-pos-y-input" value="<?= cms_h((string) $techHeaderPosY) ?>">
    <div class="cms-btn-row" style="margin-top:.5rem">
      <button type="button" class="cms-btn cms-btn--ghost" id="tech-header-reset-btn">بازنشانی کادر</button>
    </div>
  </div>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره تصویر هدر</button>
  </div>
</form>
<script>
(function () {
  var frame = document.getElementById('tech-header-crop-frame');
  var img = document.getElementById('tech-header-crop-img');
  var empty = document.getElementById('tech-header-crop-empty');
  var mainPreview = document.getElementById('preview-tech_header_image');
  var zoomRange = document.getElementById('tech-header-zoom');
  var zoomValue = document.getElementById('tech-header-zoom-value');
  var zoomInput = document.getElementById('tech-header-zoom-input');
  var posXInput = document.getElementById('tech-header-pos-x-input');
  var posYInput = document.getElementById('tech-header-pos-y-input');
  var resetBtn = document.getElementById('tech-header-reset-btn');
  if (!frame || !img) return;

  var state = {
    zoom: parseFloat(zoomInput.value) || 100,
    x: parseFloat(posXInput.value) || 50,
    y: parseFloat(posYInput.value) || 50,
  };

  function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
  }

  function render() {
    img.style.objectPosition = state.x + '% ' + state.y + '%';
    img.style.transform = 'scale(' + (state.zoom / 100) + ')';
    img.style.transformOrigin = state.x + '% ' + state.y + '%';
    zoomRange.value = String(state.zoom);
    zoomValue.textContent = Math.round(state.zoom) + '%';
    zoomInput.value = String(state.zoom);
    posXInput.value = String(state.x);
    posYInput.value = String(state.y);
  }

  zoomRange.addEventListener('input', function () {
    state.zoom = clamp(parseFloat(zoomRange.value) || 100, 100, 400);
    render();
  });

  resetBtn.addEventListener('click', function () {
    state = { zoom: 100, x: 50, y: 50 };
    render();
  });

  var dragging = false;
  var startPointer = { x: 0, y: 0 };
  var startPos = { x: 50, y: 50 };

  function pointerPos(e) {
    if (e.touches && e.touches[0]) {
      return { x: e.touches[0].clientX, y: e.touches[0].clientY };
    }
    return { x: e.clientX, y: e.clientY };
  }

  function onDown(e) {
    if (!img.src) return;
    dragging = true;
    startPointer = pointerPos(e);
    startPos = { x: state.x, y: state.y };
    frame.classList.add('is-dragging');
    e.preventDefault();
  }

  function onMove(e) {
    if (!dragging) return;
    var p = pointerPos(e);
    var rect = frame.getBoundingClientRect();
    var dxPct = ((p.x - startPointer.x) / rect.width) * 100 / (state.zoom / 100);
    var dyPct = ((p.y - startPointer.y) / rect.height) * 100 / (state.zoom / 100);
    state.x = clamp(startPos.x - dxPct, 0, 100);
    state.y = clamp(startPos.y - dyPct, 0, 100);
    render();
    e.preventDefault();
  }

  function onUp() {
    dragging = false;
    frame.classList.remove('is-dragging');
  }

  frame.addEventListener('mousedown', onDown);
  frame.addEventListener('touchstart', onDown, { passive: false });
  window.addEventListener('mousemove', onMove);
  window.addEventListener('touchmove', onMove, { passive: false });
  window.addEventListener('mouseup', onUp);
  window.addEventListener('touchend', onUp);

  if (mainPreview) {
    var observer = new MutationObserver(function () {
      if (mainPreview.src && mainPreview.style.display !== 'none') {
        img.src = mainPreview.src;
        img.style.display = 'block';
        if (empty) empty.style.display = 'none';
      }
    });
    observer.observe(mainPreview, { attributes: true, attributeFilter: ['src', 'style'] });
  }

  render();
})();
</script>
<?php endif; ?>

<div class="cms-page-head">
  <div>
    <h1 style="margin:0">تکنولوژی</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">
      قاب‌های محتوایی بعد از دو ماژول آزمایشگاهی ثابت — عنوان، توضیح و اسلایدر تصویر
    </p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="danestani-media.php?new=1">افزودن قاب</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<?php $slideCount = count($slides); ?>
<form class="cms-panel" method="post" enctype="multipart/form-data" id="danestani-form">
  <h2><?= $edit ? 'ویرایش قاب' : 'قاب جدید' ?></h2>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <input type="hidden" name="slide_count" value="<?= (int) $slideCount ?>">
  <input type="hidden" name="action" id="danestani-action" value="save">
  <input type="hidden" name="delete_index" id="danestani-delete-index" value="-1">

  <label class="cms-field"><span class="cms-label">عنوان</span>
    <input class="cms-input" name="title" required value="<?= cms_h($edit['title'] ?? '') ?>" placeholder="عنوان قاب">
  </label>
  <label class="cms-field"><span class="cms-label">زیرعنوان</span>
    <input class="cms-input" name="subtitle" value="<?= cms_h($edit['subtitle'] ?? '') ?>">
  </label>
  <label class="cms-field"><span class="cms-label">برچسب</span>
    <input class="cms-input" name="badge" value="<?= cms_h($edit['badge'] ?? 'گالری تصویری') ?>">
  </label>
  <label class="cms-field"><span class="cms-label">توضیحات</span>
    <textarea class="cms-textarea" name="explanation" rows="5" placeholder="متن پنل توضیح"><?= cms_h($edit['explanation'] ?? '') ?></textarea>
  </label>
  <label class="cms-field"><span class="cms-label">ترتیب نمایش</span>
    <input class="cms-input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
  </label>
  <label class="cms-check">
    <input type="checkbox" name="published" <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
    منتشر شده
  </label>

  <div class="cms-btn-row" style="margin-top:1.25rem;margin-bottom:.5rem;justify-content:space-between;align-items:center">
    <h3 style="margin:0;font-size:1rem">اسلایدهای تصویر</h3>
    <?php if ($slideCount < DANESTANI_SLIDE_MAX): ?>
      <button
        class="cms-btn cms-btn--secondary"
        type="submit"
        onclick="document.getElementById('danestani-action').value='add_slide'"
      >
        + افزودن اسلاید
      </button>
    <?php endif; ?>
  </div>

  <?php foreach ($slides as $i => $s): ?>
    <div class="cms-panel" style="margin-top:.75rem;background:rgba(0,0,0,.18)">
      <div class="cms-btn-row" style="margin-top:0;justify-content:space-between;align-items:center">
        <strong>اسلاید <?= (int) $i + 1 ?></strong>
        <?php if ($slideCount > 1): ?>
          <button
            class="cms-btn cms-btn--ghost"
            type="submit"
            onclick="document.getElementById('danestani-action').value='delete_slide';document.getElementById('danestani-delete-index').value='<?= (int) $i ?>';return confirm('حذف این اسلاید؟');"
          >
            حذف اسلاید
          </button>
        <?php endif; ?>
      </div>
      <?php cms_image_field('slide_image_' . $i, 'تصویر', (string) $s['image']); ?>
      <label class="cms-field"><span class="cms-label">متن جایگزین (alt)</span>
        <input class="cms-input" name="slide_alt_<?= (int) $i ?>" value="<?= cms_h($s['alt_text']) ?>">
      </label>
      <label class="cms-field"><span class="cms-label">زیرنویس</span>
        <input class="cms-input" name="slide_caption_<?= (int) $i ?>" value="<?= cms_h($s['caption']) ?>">
      </label>
    </div>
  <?php endforeach; ?>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit" onclick="document.getElementById('danestani-action').value='save'">ذخیره قاب</button>
    <a class="cms-btn cms-btn--secondary" href="danestani-media.php">بازگشت به لیست</a>
  </div>
</form>
<?php else: ?>
<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">هنوز قابی ثبت نشده. <a href="danestani-media.php?new=1">اولین قاب را اضافه کنید</a>.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead><tr><th>عنوان</th><th>اسلاید</th><th>ترتیب</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td>
          <div class="cms-list-name">
            <span><?= cms_h($item['title']) ?></span>
          </div>
          <?php if (!empty($item['subtitle'])): ?>
            <div class="cms-muted" style="margin-top:.25rem"><?= cms_h((string) $item['subtitle']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= (int) $item['slide_count'] ?></td>
        <td><?= (int) $item['sort_order'] ?></td>
        <td><?= (int) $item['published'] ? 'فعال' : 'پیش‌نویس' ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0">
            <a class="cms-btn cms-btn--secondary" href="danestani-media.php?edit=<?= (int) $item['id'] ?>">ویرایش</a>
            <a class="cms-btn cms-btn--ghost" href="danestani-media.php?delete=<?= (int) $item['id'] ?>" onclick="return confirm('حذف این قاب؟')">حذف</a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php cms_layout_end(); ?>
