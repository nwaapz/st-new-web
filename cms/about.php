<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/page-intros.php';

cms_require_login();
$pdo = cms_pdo();

const ABOUT_SLIDE_MAX = 16;
const ABOUT_STAT_COUNT = 4;
const ABOUT_CHAPTER_COUNT = 3;

function about_ensure_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS about_exhibitions (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          title VARCHAR(191) NOT NULL,
          year VARCHAR(32) NULL,
          location VARCHAR(191) NULL,
          cover_image VARCHAR(512) NULL,
          video_path VARCHAR(512) NULL,
          explanation TEXT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          published TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS about_exhibition_slides (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          exhibition_id INT UNSIGNED NOT NULL,
          image VARCHAR(512) NOT NULL,
          alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
          caption VARCHAR(512) NULL,
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          KEY idx_about_slides_exhibition (exhibition_id),
          CONSTRAINT fk_about_slide_exhibition
            FOREIGN KEY (exhibition_id) REFERENCES about_exhibitions(id)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

/** @return array<int, array{value:string, label:string}> */
function about_default_stats(): array
{
    return [
        ['value' => '15+', 'label' => 'سال مهندسی و تولید'],
        ['value' => '12', 'label' => 'حضور نمایشگاهی'],
        ['value' => '3', 'label' => 'خط تولید تخصصی'],
        ['value' => '40+', 'label' => 'شهر نمایندگی'],
    ];
}

/** @return array<int, array{title:string, body:string, image:string, href:string}> */
function about_default_chapters(): array
{
    return [
        [
            'title' => 'تولید می‌کنیم؛ واردکننده نیستیم',
            'body' => 'استارتک تسمه‌های درایو را برای بار واقعی خودرو و خطوط صنعتی طراحی و تولید می‌کند. مواد، پروفیل و پخت در کارخانه کنترل می‌شود تا کیفیت از یک محموله تا محموله بعد ثابت بماند.',
            'image' => '/images/engine-image.png',
            'href' => '',
        ],
        [
            'title' => 'آزمون قبل از ادعا',
            'body' => 'دوام یعنی عدد، نه شعار. رفتار حرارتی و انتقال توان تسمه در آزمایشگاه استارتک شبیه‌سازی می‌شود تا قطعه قبل از رسیدن به موتور، زیر بار دیده شده باشد.',
            'image' => '/images/thermal-image.jfif',
            'href' => '/danestaniha',
        ],
        [
            'title' => 'حضور در صنعت، نه فقط در کاتالوگ',
            'body' => 'غرفه نمایشگاه جایی است که قطعه در دست متخصص دیده می‌شود. آرشیو ویدیو و تصویر همین صفحه، سابقه حضور استارتک در رویدادهای خودرویی و صنعتی است.',
            'image' => '/images/main-page-image-top.png',
            'href' => '',
        ],
    ];
}

function about_blank_slide(): array
{
    return [
        'image' => '',
        'alt_text' => '',
        'caption' => '',
    ];
}

function about_load_slides(PDO $pdo, int $exhibitionId): array
{
    $stmt = $pdo->prepare(
        'SELECT image, alt_text, caption, sort_order
         FROM about_exhibition_slides
         WHERE exhibition_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$exhibitionId]);
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

function about_collect_slides_from_post(int $count): array
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
            continue;
        }
        $slides[] = [
            'image' => $image,
            'alt_text' => $alt,
            'caption' => $caption,
        ];
    }
    return $slides;
}

function about_replace_slides(PDO $pdo, int $exhibitionId, array $slides): void
{
    $pdo->prepare('DELETE FROM about_exhibition_slides WHERE exhibition_id = ?')->execute([$exhibitionId]);
    if ($slides === []) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO about_exhibition_slides (exhibition_id, image, alt_text, caption, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($slides as $index => $slide) {
        $stmt->execute([
            $exhibitionId,
            $slide['image'],
            $slide['alt_text'],
            $slide['caption'] !== '' ? $slide['caption'] : null,
            $index,
        ]);
    }
}

about_ensure_tables($pdo);

$edit = null;
$slides = [];
$showForm = isset($_GET['new']) || isset($_GET['edit']);

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM about_exhibitions WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        cms_flash('نمایشگاه یافت نشد', 'error');
        cms_redirect('about.php');
    }
    $showForm = true;
    $slides = about_load_slides($pdo, (int) $edit['id']);
    if ($slides === []) {
        $slides = [about_blank_slide()];
    }
}

if (isset($_GET['new'])) {
    $slides = [about_blank_slide()];
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM about_exhibitions WHERE id = ?');
    $stmt->execute([(int) $_GET['delete']]);
    cms_flash('نمایشگاه حذف شد');
    cms_redirect('about.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_page_intro'])) {
    try {
        cms_page_intro_save(
            'about',
            (string) ($_POST['intro_title'] ?? ''),
            (string) ($_POST['intro_explanation'] ?? '')
        );
        cms_setting_set('about_subtitle', trim((string) ($_POST['about_subtitle'] ?? '')));
        $existingHero = cms_setting_get('about_hero_image', '');
        $hero = cms_handle_optional_upload('about_hero_image', $existingHero);
        cms_setting_set('about_hero_image', $hero);
        cms_setting_set('about_cinema_title', trim((string) ($_POST['about_cinema_title'] ?? '')));
        cms_setting_set('about_cinema_subtitle', trim((string) ($_POST['about_cinema_subtitle'] ?? '')));
        cms_flash('متن و تصویر صفحه درباره ما ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('about.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_stats'])) {
    try {
        for ($i = 0; $i < ABOUT_STAT_COUNT; $i++) {
            cms_setting_set('about_stat_' . ($i + 1) . '_value', trim((string) ($_POST['stat_value_' . $i] ?? '')));
            cms_setting_set('about_stat_' . ($i + 1) . '_label', trim((string) ($_POST['stat_label_' . $i] ?? '')));
        }
        cms_flash('آمار اعتماد ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('about.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_chapters'])) {
    try {
        for ($i = 0; $i < ABOUT_CHAPTER_COUNT; $i++) {
            $n = $i + 1;
            $existing = cms_setting_get('about_chapter_' . $n . '_image', '');
            $image = cms_handle_optional_upload('chapter_image_' . $i, $existing);
            cms_setting_set('about_chapter_' . $n . '_title', trim((string) ($_POST['chapter_title_' . $i] ?? '')));
            cms_setting_set('about_chapter_' . $n . '_body', trim((string) ($_POST['chapter_body_' . $i] ?? '')));
            cms_setting_set('about_chapter_' . $n . '_image', $image);
            cms_setting_set('about_chapter_' . $n . '_href', trim((string) ($_POST['chapter_href_' . $i] ?? '')));
        }
        cms_flash('فصل‌های داستان برند ذخیره شد');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
    }
    cms_redirect('about.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exhibition_form'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        $title = trim((string) ($_POST['title'] ?? ''));
        $year = trim((string) ($_POST['year'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        $explanation = trim((string) ($_POST['explanation'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;
        $slideCount = max(0, (int) ($_POST['slide_count'] ?? 0));
        if ($slideCount > ABOUT_SLIDE_MAX) {
            $slideCount = ABOUT_SLIDE_MAX;
        }
        if ($title === '') {
            throw new RuntimeException('عنوان نمایشگاه الزامی است');
        }

        $coverExisting = (string) ($_POST['cover_image'] ?? '');
        $cover = cms_handle_optional_upload('cover_image', $coverExisting);
        $videoExisting = (string) ($_POST['video_path'] ?? '');
        $video = cms_handle_optional_video_upload('video_path', $videoExisting);
        $collected = $slideCount > 0 ? about_collect_slides_from_post($slideCount) : [];

        $persist = static function (PDO $pdo, int $id, array $fields, array $slides): int {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE about_exhibitions
                     SET title=?, year=?, location=?, cover_image=?, video_path=?, explanation=?, sort_order=?, published=?
                     WHERE id=?'
                );
                $stmt->execute([
                    $fields['title'],
                    $fields['year'],
                    $fields['location'],
                    $fields['cover'],
                    $fields['video'],
                    $fields['explanation'],
                    $fields['sort_order'],
                    $fields['published'],
                    $id,
                ]);
                $exhibitionId = $id;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO about_exhibitions
                     (title, year, location, cover_image, video_path, explanation, sort_order, published)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $fields['title'],
                    $fields['year'],
                    $fields['location'],
                    $fields['cover'],
                    $fields['video'],
                    $fields['explanation'],
                    $fields['sort_order'],
                    $fields['published'],
                ]);
                $exhibitionId = (int) $pdo->lastInsertId();
            }
            about_replace_slides($pdo, $exhibitionId, $slides);
            return $exhibitionId;
        };

        $fields = [
            'title' => $title,
            'year' => $year !== '' ? $year : null,
            'location' => $location !== '' ? $location : null,
            'cover' => $cover !== '' ? $cover : null,
            'video' => $video !== '' ? $video : null,
            'explanation' => $explanation !== '' ? $explanation : null,
            'sort_order' => $sortOrder,
            'published' => $published,
        ];

        if ($action === 'add_slide') {
            if (count($collected) >= ABOUT_SLIDE_MAX) {
                throw new RuntimeException('حداکثر ' . ABOUT_SLIDE_MAX . ' تصویر مجاز است');
            }
            $collected[] = about_blank_slide();
            $pdo->beginTransaction();
            $exhibitionId = $persist($pdo, $id, $fields, $collected);
            $pdo->commit();
            cms_flash('تصویر جدید اضافه شد');
            cms_redirect('about.php?edit=' . $exhibitionId);
        }

        if ($action === 'delete_slide') {
            $deleteIndex = (int) ($_POST['delete_index'] ?? -1);
            if ($deleteIndex < 0 || $deleteIndex >= count($collected)) {
                throw new RuntimeException('تصویر نامعتبر است');
            }
            array_splice($collected, $deleteIndex, 1);
            if ($collected === []) {
                $collected[] = about_blank_slide();
            }
            $pdo->beginTransaction();
            $exhibitionId = $persist($pdo, $id, $fields, $collected);
            $pdo->commit();
            cms_flash('تصویر حذف شد');
            cms_redirect('about.php?edit=' . $exhibitionId);
        }

        $pdo->beginTransaction();
        $persist($pdo, $id, $fields, $collected);
        $pdo->commit();
        cms_flash($id > 0 ? 'نمایشگاه به‌روز شد' : 'نمایشگاه اضافه شد');
        cms_redirect('about.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cms_flash($e->getMessage(), 'error');
        cms_redirect($id > 0 ? 'about.php?edit=' . $id : 'about.php?new=1');
    }
}

$items = $pdo->query(
    'SELECT e.*,
            (SELECT COUNT(*) FROM about_exhibition_slides s WHERE s.exhibition_id = e.id) AS slide_count
     FROM about_exhibitions e
     ORDER BY e.sort_order ASC, e.id ASC'
)->fetchAll();

$intro = cms_page_intro_stored('about');
$introDefaults = cms_page_intro_defaults()['about'];
$subtitle = cms_setting_get('about_subtitle', '');
$heroImage = cms_setting_get('about_hero_image', '');
$cinemaTitle = cms_setting_get('about_cinema_title', '');
$cinemaSubtitle = cms_setting_get('about_cinema_subtitle', '');
$statDefaults = about_default_stats();
$chapterDefaults = about_default_chapters();

cms_layout_start('درباره ما', cms_current_username(), 'website');
?>
<?php if (!$showForm): ?>
<form class="cms-panel" method="post" enctype="multipart/form-data" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">مانیفست صفحه درباره ما</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">
    عنوان، توضیح و تصویر قاب‌دار بالای صفحه. خالی بگذارید تا متن پیش‌فرض سایت استفاده شود.
  </p>
  <input type="hidden" name="save_page_intro" value="1">
  <label class="cms-field">
    <span class="cms-label">عنوان</span>
    <input class="cms-input" name="intro_title" value="<?= cms_h($intro['title']) ?>" placeholder="<?= cms_h($introDefaults['title']) ?>">
  </label>
  <label class="cms-field">
    <span class="cms-label">زیرعنوان</span>
    <input class="cms-input" name="about_subtitle" value="<?= cms_h($subtitle) ?>" placeholder="مهندسی دقیق. دوام بیشتر. توقف کمتر.">
  </label>
  <label class="cms-field">
    <span class="cms-label">متن مانیفست</span>
    <textarea class="cms-textarea" name="intro_explanation" rows="4" placeholder="<?= cms_h($introDefaults['explanation']) ?>"><?= cms_h($intro['explanation']) ?></textarea>
  </label>
  <?php cms_image_field('about_hero_image', 'تصویر قاب مانیفست', $heroImage); ?>
  <label class="cms-field">
    <span class="cms-label">عنوان بخش نمایشگاه‌ها</span>
    <input class="cms-input" name="about_cinema_title" value="<?= cms_h($cinemaTitle) ?>" placeholder="نمایشگاه‌ها">
  </label>
  <label class="cms-field">
    <span class="cms-label">توضیح بخش نمایشگاه‌ها</span>
    <input class="cms-input" name="about_cinema_subtitle" value="<?= cms_h($cinemaSubtitle) ?>" placeholder="حضور استارتک در رویدادهای صنعت خودرو">
  </label>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره مانیفست</button>
  </div>
</form>

<form class="cms-panel" method="post" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">آمار اعتماد</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">چهار عدد کوتاه قبل از داستان برند. خالی = پیش‌فرض سایت.</p>
  <input type="hidden" name="save_stats" value="1">
  <div class="cms-grid-2">
    <?php for ($i = 0; $i < ABOUT_STAT_COUNT; $i++): ?>
      <?php
        $n = $i + 1;
        $val = cms_setting_get('about_stat_' . $n . '_value', '');
        $lab = cms_setting_get('about_stat_' . $n . '_label', '');
      ?>
      <div>
        <label class="cms-field">
          <span class="cms-label">عدد <?= $n ?></span>
          <input class="cms-input" name="stat_value_<?= $i ?>" value="<?= cms_h($val) ?>" placeholder="<?= cms_h($statDefaults[$i]['value']) ?>">
        </label>
        <label class="cms-field">
          <span class="cms-label">برچسب <?= $n ?></span>
          <input class="cms-input" name="stat_label_<?= $i ?>" value="<?= cms_h($lab) ?>" placeholder="<?= cms_h($statDefaults[$i]['label']) ?>">
        </label>
      </div>
    <?php endfor; ?>
  </div>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره آمار</button>
  </div>
</form>

<form class="cms-panel" method="post" enctype="multipart/form-data" style="margin-bottom:1.25rem">
  <h2 style="margin-top:0">چرا استارتک — سه فصل</h2>
  <p class="cms-muted" style="margin:.25rem 0 1rem">داستان کوتاه برند. پیوند اختیاری است (مثلاً /danestaniha برای فصل آزمون).</p>
  <input type="hidden" name="save_chapters" value="1">
  <?php for ($i = 0; $i < ABOUT_CHAPTER_COUNT; $i++): ?>
    <?php
      $n = $i + 1;
      $chTitle = cms_setting_get('about_chapter_' . $n . '_title', '');
      $chBody = cms_setting_get('about_chapter_' . $n . '_body', '');
      $chImage = cms_setting_get('about_chapter_' . $n . '_image', '');
      $chHref = cms_setting_get('about_chapter_' . $n . '_href', '');
    ?>
    <div class="cms-panel" style="margin-top:.75rem;background:rgba(0,0,0,.18)">
      <h3 style="margin-top:0">فصل <?= $n ?></h3>
      <label class="cms-field">
        <span class="cms-label">عنوان</span>
        <input class="cms-input" name="chapter_title_<?= $i ?>" value="<?= cms_h($chTitle) ?>" placeholder="<?= cms_h($chapterDefaults[$i]['title']) ?>">
      </label>
      <label class="cms-field">
        <span class="cms-label">متن</span>
        <textarea class="cms-textarea" name="chapter_body_<?= $i ?>" rows="4" placeholder="<?= cms_h($chapterDefaults[$i]['body']) ?>"><?= cms_h($chBody) ?></textarea>
      </label>
      <?php cms_image_field('chapter_image_' . $i, 'تصویر قاب', $chImage !== '' ? $chImage : ''); ?>
      <label class="cms-field">
        <span class="cms-label">پیوند اختیاری</span>
        <input class="cms-input" name="chapter_href_<?= $i ?>" value="<?= cms_h($chHref) ?>" placeholder="<?= cms_h($chapterDefaults[$i]['href']) ?>" dir="ltr">
      </label>
    </div>
  <?php endfor; ?>
  <div class="cms-btn-row">
    <button class="cms-btn" type="submit">ذخیره فصل‌ها</button>
  </div>
</form>
<?php endif; ?>

<div class="cms-page-head">
  <div>
    <h1 style="margin:0">نمایشگاه‌ها</h1>
    <p class="cms-muted" style="margin:.35rem 0 0">
      هر رویداد یک ویدیوی MP4 و گالری تصویر دارد. روی سایت به‌صورت سینمای غرفه نمایش داده می‌شود.
    </p>
  </div>
  <?php if (!$showForm): ?>
    <a class="cms-btn" href="about.php?new=1">افزودن نمایشگاه</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<?php $slideCount = count($slides); ?>
<form class="cms-panel" method="post" enctype="multipart/form-data" id="about-exhibition-form">
  <h2><?= $edit ? 'ویرایش نمایشگاه' : 'نمایشگاه جدید' ?></h2>
  <input type="hidden" name="exhibition_form" value="1">
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <input type="hidden" name="slide_count" value="<?= (int) $slideCount ?>">
  <input type="hidden" name="action" id="about-action" value="save">
  <input type="hidden" name="delete_index" id="about-delete-index" value="-1">

  <label class="cms-field"><span class="cms-label">عنوان رویداد</span>
    <input class="cms-input" name="title" required value="<?= cms_h($edit['title'] ?? '') ?>" placeholder="اتومکانیکا تهران">
  </label>
  <label class="cms-field"><span class="cms-label">سال</span>
    <input class="cms-input" name="year" value="<?= cms_h($edit['year'] ?? '') ?>" placeholder="۱۴۰۳">
  </label>
  <label class="cms-field"><span class="cms-label">محل</span>
    <input class="cms-input" name="location" value="<?= cms_h($edit['location'] ?? '') ?>" placeholder="نمایشگاه بین‌المللی تهران">
  </label>
  <label class="cms-field"><span class="cms-label">توضیح</span>
    <textarea class="cms-textarea" name="explanation" rows="4"><?= cms_h($edit['explanation'] ?? '') ?></textarea>
  </label>
  <?php cms_image_field('cover_image', 'تصویر جلد', (string) ($edit['cover_image'] ?? '')); ?>
  <?php cms_video_field('video_path', 'ویدیوی غرفه (MP4 / WebM)', (string) ($edit['video_path'] ?? '')); ?>
  <label class="cms-field"><span class="cms-label">ترتیب نمایش</span>
    <input class="cms-input" type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
  </label>
  <label class="cms-check">
    <input type="checkbox" name="published" <?= !isset($edit['published']) || (int) $edit['published'] === 1 ? 'checked' : '' ?>>
    منتشر شده
  </label>

  <div class="cms-btn-row" style="margin-top:1.25rem;margin-bottom:.5rem;justify-content:space-between;align-items:center">
    <h3 style="margin:0;font-size:1rem">گالری تصاویر</h3>
    <?php if ($slideCount < ABOUT_SLIDE_MAX): ?>
      <button
        class="cms-btn cms-btn--secondary"
        type="submit"
        onclick="document.getElementById('about-action').value='add_slide'"
      >
        + افزودن تصویر
      </button>
    <?php endif; ?>
  </div>

  <?php foreach ($slides as $i => $s): ?>
    <div class="cms-panel" style="margin-top:.75rem;background:rgba(0,0,0,.18)">
      <div class="cms-btn-row" style="margin-top:0;justify-content:space-between;align-items:center">
        <strong>تصویر <?= (int) $i + 1 ?></strong>
        <?php if ($slideCount > 1): ?>
          <button
            class="cms-btn cms-btn--ghost"
            type="submit"
            onclick="document.getElementById('about-action').value='delete_slide';document.getElementById('about-delete-index').value='<?= (int) $i ?>';return confirm('حذف این تصویر؟');"
          >
            حذف تصویر
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
    <button class="cms-btn" type="submit" onclick="document.getElementById('about-action').value='save'">ذخیره نمایشگاه</button>
    <a class="cms-btn cms-btn--secondary" href="about.php">بازگشت به لیست</a>
  </div>
</form>
<?php else: ?>
<div class="cms-panel">
  <?php if ($items === []): ?>
    <p class="cms-empty">هنوز نمایشگاهی ثبت نشده. <a href="about.php?new=1">اولین رویداد را اضافه کنید</a>.</p>
  <?php else: ?>
  <table class="cms-table">
    <thead><tr><th>عنوان</th><th>سال</th><th>ویدیو</th><th>تصویر</th><th>ترتیب</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td>
          <div class="cms-list-name">
            <span><?= cms_h($item['title']) ?></span>
          </div>
          <?php if (!empty($item['location'])): ?>
            <div class="cms-muted" style="margin-top:.25rem"><?= cms_h((string) $item['location']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= cms_h((string) ($item['year'] ?? '')) ?></td>
        <td><?= trim((string) ($item['video_path'] ?? '')) !== '' ? 'دارد' : '—' ?></td>
        <td><?= (int) $item['slide_count'] ?></td>
        <td><?= (int) $item['sort_order'] ?></td>
        <td><?= (int) $item['published'] ? 'فعال' : 'پیش‌نویس' ?></td>
        <td>
          <div class="cms-btn-row" style="margin-top:0">
            <a class="cms-btn cms-btn--secondary" href="about.php?edit=<?= (int) $item['id'] ?>">ویرایش</a>
            <a class="cms-btn cms-btn--ghost" href="about.php?delete=<?= (int) $item['id'] ?>" onclick="return confirm('حذف این نمایشگاه؟')">حذف</a>
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
