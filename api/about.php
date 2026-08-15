<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/page-intros.php';

function about_api_ensure_tables(PDO $pdo): void
{
    $exists = $pdo->query("SHOW TABLES LIKE 'about_exhibitions'")->fetchAll();
    if (count($exists) === 0) {
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
    }
}

try {
    $pdo = cms_pdo();
    about_api_ensure_tables($pdo);

    $intro = cms_page_intro_public('about');
    $stats = [];
    for ($i = 1; $i <= 4; $i++) {
        $stats[] = [
            'value' => cms_setting_get('about_stat_' . $i . '_value', ''),
            'label' => cms_setting_get('about_stat_' . $i . '_label', ''),
        ];
    }

    $chapters = [];
    for ($i = 1; $i <= 3; $i++) {
        $chapters[] = [
            'title' => cms_setting_get('about_chapter_' . $i . '_title', ''),
            'body' => cms_setting_get('about_chapter_' . $i . '_body', ''),
            'image' => cms_setting_get('about_chapter_' . $i . '_image', ''),
            'href' => cms_setting_get('about_chapter_' . $i . '_href', ''),
        ];
    }

    $exhibitions = [];
    $exists = $pdo->query("SHOW TABLES LIKE 'about_exhibitions'")->fetchAll();
    if (count($exists) > 0) {
        $rows = $pdo->query(
            'SELECT id, title, year, location, cover_image, video_path, explanation, sort_order
             FROM about_exhibitions
             WHERE published = 1
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
        $slidesStmt = $pdo->prepare(
            'SELECT image, alt_text, caption
             FROM about_exhibition_slides
             WHERE exhibition_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $slidesStmt->execute([$id]);
            $slides = [];
            foreach ($slidesStmt->fetchAll() as $slide) {
                $image = trim((string) ($slide['image'] ?? ''));
                if ($image === '') {
                    continue;
                }
                $slides[] = [
                    'src' => $image,
                    'alt' => (string) ($slide['alt_text'] ?? ''),
                    'caption' => (string) ($slide['caption'] ?? ''),
                ];
            }
            $exhibitions[] = [
                'id' => $id,
                'title' => (string) $row['title'],
                'year' => (string) ($row['year'] ?? ''),
                'location' => (string) ($row['location'] ?? ''),
                'cover' => (string) ($row['cover_image'] ?? ''),
                'video' => (string) ($row['video_path'] ?? ''),
                'explanation' => (string) ($row['explanation'] ?? ''),
                'sort_order' => (int) $row['sort_order'],
                'slides' => $slides,
            ];
        }
    }

    api_json([
        'title' => $intro['title'],
        'explanation' => $intro['explanation'],
        'subtitle' => cms_setting_get('about_subtitle', ''),
        'heroImage' => cms_setting_get('about_hero_image', ''),
        'cinemaTitle' => cms_setting_get('about_cinema_title', ''),
        'cinemaSubtitle' => cms_setting_get('about_cinema_subtitle', ''),
        'stats' => $stats,
        'chapters' => $chapters,
        'exhibitions' => $exhibitions,
    ]);
} catch (Throwable $e) {
    api_error('About content unavailable', 503);
}
