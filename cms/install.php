<?php
declare(strict_types=1);

/**
 * One-time setup: creates admin user + default hero slides.
 * Run AFTER importing schema.sql in phpMyAdmin.
 * Delete this file after successful install.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = cms_pdo();
    $config = cms_config();

    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($adminCount === 0) {
        $username = (string) ($config['admin_username'] ?? 'admin');
        $password = (string) ($config['admin_password'] ?? 'change-me');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)');
        $stmt->execute([$username, $hash]);
        $adminMsg = "Admin created: {$username}";
    } else {
        $adminMsg = 'Admin already exists — skipped.';
    }

    $heroCount = (int) $pdo->query('SELECT COUNT(*) FROM hero_slides')->fetchColumn();
    if ($heroCount === 0) {
        $defaults = [
            [
                '/images/main-page-image-top.png',
                '/images/engine-image.png',
                'با قدرت برانید',
                'استارتک انتخابی است که هزینه‌های پنهان خرابی را کاهش می‌دهد.',
                'قدرت بیشتر. توقف کمتر. استارتک.',
            ],
            [
                '/images/header-wide.png',
                '/images/Category/cat5.png',
                'تسمه تام صنعتی',
                'انتقال قدرت پایدار برای ماشین‌آلات سنگین و خطوط تولید.',
                'دوام بیشتر. نگهداری کمتر. استارتک.',
            ],
            [
                '/images/bg/startechShop.png',
                '/images/Category/cat1.png',
                'مهندسی دقیق',
                'قطعاتی که برای شرایط سخت خودرویی و صنعتی طراحی شده‌اند.',
                'کیفیت ثابت. عملکرد مطمئن. استارتک.',
            ],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO hero_slides (slide_index, background, front_image, part1, part2, part3) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($defaults as $i => $row) {
            $stmt->execute([$i, $row[0], $row[1], $row[2], $row[3], $row[4]]);
        }
        $heroMsg = '3 default hero slides inserted.';
    } else {
        $heroMsg = 'Hero slides already exist — skipped.';
    }

    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>CMS Install</title></head><body style="font-family:Tahoma;padding:2rem">';
    echo '<h1>نصب CMS انجام شد</h1>';
    echo '<p>' . cms_h($adminMsg) . '</p>';
    echo '<p>' . cms_h($heroMsg) . '</p>';
    echo '<p><strong>حالا این فایل install.php را از سرور حذف کنید.</strong></p>';
    echo '<p><a href="login.php">ورود به CMS</a></p>';
    echo '</body></html>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Install failed: ' . cms_h($e->getMessage()) . '</pre>';
    echo '<p>Import schema.sql in phpMyAdmin first, and check config.local.php.</p>';
}
