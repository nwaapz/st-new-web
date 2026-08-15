<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

cms_require_login();
$pdo = cms_pdo();

const HERO_SLIDE_MIN = 1;
const HERO_SLIDE_MAX = 12;

function hero_blank_slide(): array
{
    return [
        'background' => '/images/main-page-image-top.png',
        'front_image' => '/images/engine-image.png',
        'part1' => '',
        'part2' => '',
        'part3' => '',
    ];
}

function hero_load_slides(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM hero_slides ORDER BY slide_index ASC')->fetchAll();
    if (count($rows) === 0) {
        return [
            [
                'background' => '/images/main-page-image-top.png',
                'front_image' => '/images/engine-image.png',
                'part1' => 'با قدرت برانید',
                'part2' => 'استارتک انتخابی است که هزینه‌های پنهان خرابی را کاهش می‌دهد.',
                'part3' => 'قدرت بیشتر. توقف کمتر. استارتک.',
            ],
            [
                'background' => '/images/header-wide.png',
                'front_image' => '/images/Category/cat5.png',
                'part1' => 'تسمه تام صنعتی',
                'part2' => 'انتقال قدرت پایدار برای ماشین‌آلات سنگین و خطوط تولید.',
                'part3' => 'دوام بیشتر. نگهداری کمتر. استارتک.',
            ],
            [
                'background' => '/images/bg/startechShop.png',
                'front_image' => '/images/Category/cat1.png',
                'part1' => 'مهندسی دقیق',
                'part2' => 'قطعاتی که برای شرایط سخت خودرویی و صنعتی طراحی شده‌اند.',
                'part3' => 'کیفیت ثابت. عملکرد مطمئن. استارتک.',
            ],
        ];
    }

    $slides = [];
    foreach ($rows as $row) {
        $slides[] = [
            'background' => (string) $row['background'],
            'front_image' => (string) $row['front_image'],
            'part1' => (string) $row['part1'],
            'part2' => (string) $row['part2'],
            'part3' => (string) $row['part3'],
        ];
    }
    return $slides;
}

function hero_replace_all(PDO $pdo, array $slides): void
{
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM hero_slides');
        $stmt = $pdo->prepare(
            'INSERT INTO hero_slides (slide_index, background, front_image, part1, part2, part3)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($slides as $index => $slide) {
            $stmt->execute([
                $index,
                $slide['background'],
                $slide['front_image'],
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

function hero_collect_from_post(int $count): array
{
    $slides = [];
    for ($i = 0; $i < $count; $i++) {
        $background = cms_handle_optional_upload(
            'background_' . $i,
            (string) ($_POST['background_' . $i] ?? '')
        );
        $front = cms_handle_optional_upload(
            'front_image_' . $i,
            (string) ($_POST['front_image_' . $i] ?? '')
        );
        $part1 = trim((string) ($_POST['part1_' . $i] ?? ''));
        $part2 = trim((string) ($_POST['part2_' . $i] ?? ''));
        $part3 = trim((string) ($_POST['part3_' . $i] ?? ''));

        if ($background === '' || $front === '') {
            throw new RuntimeException('اسلاید ' . ($i + 1) . ': تصویر پس‌زمینه و جلو الزامی است');
        }

        $slides[] = [
            'background' => $background,
            'front_image' => $front,
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
        if ($count > HERO_SLIDE_MAX) {
            $count = HERO_SLIDE_MAX;
        }

        if ($action === 'add') {
            $slides = $count > 0 ? hero_collect_from_post($count) : hero_load_slides($pdo);
            if (count($slides) >= HERO_SLIDE_MAX) {
                throw new RuntimeException('حداکثر ' . HERO_SLIDE_MAX . ' اسلاید مجاز است');
            }
            $slides[] = hero_blank_slide();
            hero_replace_all($pdo, $slides);
            cms_flash('اسلاید جدید اضافه شد');
            cms_redirect('hero.php');
        }

        if ($action === 'delete') {
            $deleteIndex = (int) ($_POST['delete_index'] ?? -1);
            $slides = $count > 0 ? hero_collect_from_post($count) : hero_load_slides($pdo);
            if (count($slides) <= HERO_SLIDE_MIN) {
                throw new RuntimeException('حداقل یک اسلاید باید باقی بماند');
            }
            if ($deleteIndex < 0 || $deleteIndex >= count($slides)) {
                throw new RuntimeException('اسلاید نامعتبر است');
            }
            array_splice($slides, $deleteIndex, 1);
            hero_replace_all($pdo, $slides);
            cms_flash('اسلاید حذف شد');
            cms_redirect('hero.php');
        }

        // save
        if ($count < HERO_SLIDE_MIN) {
            throw new RuntimeException('حداقل یک اسلاید لازم است');
        }
        $slides = hero_collect_from_post($count);
        hero_replace_all($pdo, $slides);
        cms_flash('هیرو ذخیره شد');
        cms_redirect('hero.php');
    } catch (Throwable $e) {
        cms_flash($e->getMessage(), 'error');
        cms_redirect('hero.php');
    }
}

$slides = hero_load_slides($pdo);
$slideCount = count($slides);

cms_layout_start('هیرو', cms_current_username(), 'website');
?>
<h1 style="margin-top:0">هیرو صفحه اصلی</h1>
<p class="cms-muted">
  اسلایدها را اضافه یا حذف کنید (حداقل <?= HERO_SLIDE_MIN ?>، حداکثر <?= HERO_SLIDE_MAX ?>) —
  متن‌ها، پس‌زمینه و تصویر جلو / موتور.
</p>

<form method="post" enctype="multipart/form-data" id="hero-form">
  <input type="hidden" name="slide_count" value="<?= (int) $slideCount ?>">
  <input type="hidden" name="action" id="hero-action" value="save">
  <input type="hidden" name="delete_index" id="hero-delete-index" value="-1">

  <div class="cms-btn-row" style="margin-top:0;margin-bottom:1rem">
    <button class="cms-btn" type="submit" onclick="document.getElementById('hero-action').value='save'">
      ذخیره همه اسلایدها
    </button>
    <?php if ($slideCount < HERO_SLIDE_MAX): ?>
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
        <?php if ($slideCount > HERO_SLIDE_MIN): ?>
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
      <?php cms_image_field('background_' . $i, 'پس‌زمینه (background)', (string) $s['background']); ?>
      <?php cms_image_field('front_image_' . $i, 'تصویر جلو / موتور (front)', (string) $s['front_image']); ?>
    </div>
  <?php endforeach; ?>

  <div class="cms-btn-row">
    <button class="cms-btn" type="submit" onclick="document.getElementById('hero-action').value='save'">
      ذخیره همه اسلایدها
    </button>
  </div>
</form>
<?php cms_layout_end(); ?>
