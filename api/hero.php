<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

try {
    $pdo = cms_pdo();
    $rows = $pdo->query(
        'SELECT slide_index, background, front_image, part1, part2, part3
         FROM hero_slides ORDER BY slide_index ASC'
    )->fetchAll();

    $defaults = [
        [
            'background' => '/images/main-page-image-top.png',
            'frontImage' => '/images/engine-image.png',
            'part1' => 'با قدرت برانید',
            'part2' => 'استارتک انتخابی است که هزینه‌های پنهان خرابی را کاهش می‌دهد.',
            'part3' => 'قدرت بیشتر. توقف کمتر. استارتک.',
        ],
        [
            'background' => '/images/header-wide.png',
            'frontImage' => '/images/Category/cat5.png',
            'part1' => 'تسمه تام صنعتی',
            'part2' => 'انتقال قدرت پایدار برای ماشین‌آلات سنگین و خطوط تولید.',
            'part3' => 'دوام بیشتر. نگهداری کمتر. استارتک.',
        ],
        [
            'background' => '/images/bg/startechShop.png',
            'frontImage' => '/images/Category/cat1.png',
            'part1' => 'مهندسی دقیق',
            'part2' => 'قطعاتی که برای شرایط سخت خودرویی و صنعتی طراحی شده‌اند.',
            'part3' => 'کیفیت ثابت. عملکرد مطمئن. استارتک.',
        ],
    ];

    if (count($rows) === 0) {
        api_json(['slides' => $defaults]);
    }

    $slides = [];
    foreach ($rows as $row) {
        $slides[] = [
            'background' => $row['background'],
            'frontImage' => $row['front_image'],
            'part1' => $row['part1'],
            'part2' => $row['part2'],
            'part3' => $row['part3'],
        ];
    }

    api_json(['slides' => $slides]);
} catch (Throwable $e) {
    api_error('Hero unavailable', 503);
}
