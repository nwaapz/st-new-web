<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/lib/hero-mobile.php';

cms_require_login();
$pdo = cms_pdo();
hero_mobile_ensure_schema($pdo);

const HERO_MOBILE_SLIDE_MIN = 1;
const HERO_MOBILE_SLIDE_MAX = 12;

function hero_mobile_blank_slide(): array
{
    return [
        'background' => '/images/main-page-image-top.png',
        'part1' => '',
        'part2' => '',
        'part3' => '',
    ];
}

function hero_mobile_from_desktop(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT background, part1, part2, part3 FROM hero_slides ORDER BY slide_index ASC'
        )->fetchAll();
        if (count($rows) > 0) {
            $slides = [];
            foreach ($rows as $row) {
                $slides[] = [
                    'background' => (string) $row['background'],
                    'part1' => (string) $row['part1'],
                    'part2' => (string) $row['part2'],
                    'part3' => (string) $row['part3'],
                ];
            }
            return $slides;
        }
    } catch (Throwable $e) {
        /* desktop table may be missing on a fresh install */
    }

    return [
        [
            'background' => '/images/main-page-image-top.png',
            'part1' => 'با قدرت برانید',
            'part2' => 'استارتک انتخابی است که هزینه‌های پنهان خرابی را کاهش می‌دهد.',
            'part3' => 'قدرت بیشتر. توقف کمتر. استارتک.',
        ],
        [
            'background' => '/images/header-wide.png',
            'part1' => 'تسمه تام صنعتی',
            'part2' => 'انتقال قدرت پایدار برای ماشین‌آلات سنگین و خطوط تولید.',
            'part3' => 'دوام بیشتر. نگهداری کمتر. استارتک.',
        ],
        [
            'background' => '/images/bg/startechShop.png',
            'part1' => 'مهندسی دقیق',
            'part2' => 'قطعاتی که برای شرایط سخت خودرویی و صنعتی طراحی شده‌اند.',
            'part3' => 'کیفیت ثابت. عملکرد مطمئن. استارتک.',
        ],
    ];
}

function hero_mobile_load_slides(PDO $pdo): array
{
    $saved = hero_mobile_load_saved($pdo);
    if (count($saved) === 0) {
        return hero_mobile_from_desktop($pdo);
    }
    return $saved;
}

function hero_mobile_replace_all(PDO $pdo, array $slides): void
{
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM hero_slides_mobile');
        $stmt = $pdo->prepare(
            'INSERT INTO hero_slides_mobile (slide_index, background, part1, part2, part3)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($slides as $index => $slide) {
            $stmt->execute([
                $index,
                $slide['background'],
                $slide['part1'],
                $slide['part2'],
                $slide['part3'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function hero_mobile_collect_from_post(int $count): array
{
    $slides = [];
    for ($i = 0; $i < $count; $i++) {
        $background = cms_handle_optional_upload(
            'background_' . $i,
            (string) ($_POST['background_' . $i] ?? '')
        );
        $part1 = trim((string) ($_POST['part1_' . $i] ?? ''));
        $part2 = trim((string) ($_POST['part2_' . $i] ?? ''));
        $part3 = trim((string) ($_POST['part3_' . $i] ?? ''));

        if ($background === '') {
            throw new RuntimeException('اسلاید ' . ($i + 1) . ': تصویر پس‌زمینه الزامی است');
        }

        $slides[] = [
            'background' => $background,
            'part1' => $part1,
            'part2' => $part2,
            'part3' => $part3,
        ];
    }
    return $slides;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? 'save');
        $count = max(0, (int) ($_POST['slide_count'] ?? 0));
        if ($count > HERO_MOBILE_SLIDE_MAX) {
            $count = HERO_MOBILE_SLIDE_MAX;
        }

        if ($action === 'add') {
            $slides = $count > 0 ? hero_mobile_collect_from_post($count) : hero_mobile_load_slides($pdo);
            if (count($slides) >= HERO_MOBILE_SLIDE_MAX) {
                throw new RuntimeException('حداکثر ' . HERO_MOBILE_SLIDE_MAX . ' اسلاید مجاز است');
            }
            $slides[] = hero_mobile_blank_slide();
            hero_mobile_replace_all($pdo, $slides);
            cms_flash('اسلاید جدید اضافه شد');
            cms_redirect('hero-mobile.php');
        }

        if ($action === 'delete') {
            $deleteIndex = (int) ($_POST['delete_index'] ?? -1);
            $slides = $count > 0 ? hero_mobile_collect_from_post($count) : hero_mobile_load_slides($pdo);
            if (count($slides) <= HERO_MOBILE_SLIDE_MIN) {
                throw new RuntimeException('حداقل یک اسلاید باید باقی بماند');
            }
            if ($deleteIndex < 0 || $deleteIndex >= count($slides)) {
                throw new RuntimeException('اسلاید نامعتبر است');
            }
            array_splice($slides, $deleteIndex, 1);
            hero_mobile_replace_all($pdo, $slides);
            cms_flash('اسلاید حذف شد');
            cms_redirect('hero-mobile.php');
        }

        if ($count < HERO_MOBILE_SLIDE_MIN) {
            throw new RuntimeException('حداقل یک اسلاید لازم است');
        }
        $slides = hero_mobile_collect_from_post($count);
        hero_mobile_replace_all($pdo, $slides);
        cms_flash('هیرو موبایل ذخیره شد');
        cms_redirect('hero-mobile.php');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect('hero-mobile.php');
    }
}

$slides = hero_mobile_load_slides($pdo);
$slideCount = count($slides);
$usingDesktopFallback = count(hero_mobile_load_saved($pdo)) === 0;

cms_layout_start('هیرو موبایل', cms_current_username(), 'website');
?>
<h1 style="margin-top:0">هیرو موبایل</h1>
<p class="cms-muted">
  اسلایدهای صفحه اصلی روی گوشی — فقط یک عکس تمام‌صفحه و متن (بدون تصویر جلو).
  حداقل <?= HERO_MOBILE_SLIDE_MIN ?> و حداکثر <?= HERO_MOBILE_SLIDE_MAX ?> اسلاید.
</p>
<?php if ($usingDesktopFallback): ?>
  <p class="cms-muted">هنوز ذخیره نشده — فعلاً متن و عکس هیرو دسکتاپ نمایش داده شده. ذخیره کنید تا نسخه موبایل جدا شود.</p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="hero-mobile-form">
  <input type="hidden" name="slide_count" value="<?= (int) $slideCount ?>">
  <input type="hidden" name="action" id="hero-action" value="save">
  <input type="hidden" name="delete_index" id="hero-delete-index" value="-1">

  <div class="cms-btn-row" style="margin-top:0;margin-bottom:1rem">
    <button class="cms-btn" type="submit" onclick="document.getElementById('hero-action').value='save'">
      ذخیره همه اسلایدها
    </button>
    <?php if ($slideCount < HERO_MOBILE_SLIDE_MAX): ?>
      <button
        class="cms-btn cms-btn--secondary"
        type="submit"
        onclick="document.getElementById('hero-action').value='add'"
      >
        + افزودن اسلاید
      </button>
    <?php endif; ?>
  </div>

  <?php foreach ($slides as $i => $s): ?>
    <div class="cms-panel">
      <div class="cms-btn-row" style="margin-top:0;justify-content:space-between;align-items:center">
        <h2 style="margin:0">اسلاید <?= (int) $i + 1 ?></h2>
        <?php if ($slideCount > HERO_MOBILE_SLIDE_MIN): ?>
          <button
            class="cms-btn cms-btn--ghost"
            type="submit"
            onclick="document.getElementById('hero-action').value='delete';document.getElementById('hero-delete-index').value='<?= (int) $i ?>';return confirm('حذف این اسلاید؟');"
          >
            حذف اسلاید
          </button>
        <?php endif; ?>
      </div>
      <label class="cms-field"><span class="cms-label">عنوان (part1)</span>
        <input class="cms-input" name="part1_<?= (int) $i ?>" value="<?= cms_h($s['part1']) ?>">
      </label>
      <label class="cms-field"><span class="cms-label">متن میانی (part2)</span>
        <textarea class="cms-textarea" name="part2_<?= (int) $i ?>"><?= cms_h($s['part2']) ?></textarea>
      </label>
      <label class="cms-field"><span class="cms-label">متن پایانی (part3)</span>
        <input class="cms-input" name="part3_<?= (int) $i ?>" value="<?= cms_h($s['part3']) ?>">
      </label>
      <?php cms_image_field('background_' . $i, 'عکس اسلاید (پس‌زمینه تمام‌صفحه)', (string) $s['background']); ?>
    </div>
  <?php endforeach; ?>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit" onclick="document.getElementById('hero-action').value='save'">
      ذخیره همه اسلایدها
    </button>
  </div>
</form>
<?php cms_layout_end(); ?>
