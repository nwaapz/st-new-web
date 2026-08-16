<?php
declare(strict_types=1);

function hero_mobile_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hero_slides_mobile (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          slide_index TINYINT UNSIGNED NOT NULL,
          background VARCHAR(512) NOT NULL,
          part1 VARCHAR(255) NOT NULL DEFAULT \'\',
          part2 TEXT NOT NULL,
          part3 VARCHAR(255) NOT NULL DEFAULT \'\',
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_hero_mobile_index (slide_index)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** @return list<array{background: string, part1: string, part2: string, part3: string}> */
function hero_mobile_load_saved(PDO $pdo): array
{
    hero_mobile_ensure_schema($pdo);
    $rows = $pdo->query(
        'SELECT background, part1, part2, part3 FROM hero_slides_mobile ORDER BY slide_index ASC'
    )->fetchAll();
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
