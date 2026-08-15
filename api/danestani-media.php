<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    $pdo = cms_pdo();

    // Tolerate missing tables before migrate runs.
    $exists = $pdo->query("SHOW TABLES LIKE 'danestani_media_frames'")->fetchAll();
    if (count($exists) === 0) {
        api_json(['frames' => []]);
    }

    $framesStmt = $pdo->query(
        'SELECT id, title, subtitle, badge, explanation, sort_order
         FROM danestani_media_frames
         WHERE published = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $frameRows = $framesStmt->fetchAll();

    $slidesStmt = $pdo->prepare(
        'SELECT image, alt_text, caption, sort_order
         FROM danestani_media_slides
         WHERE frame_id = ?
         ORDER BY sort_order ASC, id ASC'
    );

    $frames = [];
    foreach ($frameRows as $row) {
        $frameId = (int) $row['id'];
        $slidesStmt->execute([$frameId]);
        $slideRows = $slidesStmt->fetchAll();
        $slides = [];
        foreach ($slideRows as $slide) {
            $image = (string) ($slide['image'] ?? '');
            if ($image === '') {
                continue;
            }
            $slides[] = [
                'src' => $image,
                'alt' => (string) ($slide['alt_text'] ?? ''),
                'caption' => (string) ($slide['caption'] ?? ''),
            ];
        }

        $frames[] = [
            'id' => $frameId,
            'title' => (string) $row['title'],
            'subtitle' => (string) ($row['subtitle'] ?? ''),
            'badge' => (string) ($row['badge'] ?? ''),
            'explanation' => (string) ($row['explanation'] ?? ''),
            'sort_order' => (int) $row['sort_order'],
            'slides' => $slides,
        ];
    }

    api_json(['frames' => $frames]);
} catch (Throwable $e) {
    api_error('Danestani media unavailable', 503);
}
