<?php
declare(strict_types=1);

/**
 * One-time DB diagnostic — delete after fixing config.local.php + schema import.
 * Open: /test2/cms/db-check.php
 */
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$configPath = __DIR__ . '/config.local.php';
echo "StarTech CMS DB check\n";
echo str_repeat('=', 40) . "\n\n";

if (!is_file($configPath)) {
    echo "FAIL: cms/config.local.php is MISSING.\n";
    echo "Fix: copy config.local.production.php → config.local.php and fill db_* credentials.\n";
    exit(1);
}

echo "PHP: " . PHP_VERSION . "\n";
echo (extension_loaded('gd') ? 'OK' : 'FAIL') . ": GD extension " . (extension_loaded('gd') ? 'enabled — image optimization active' : 'MISSING — images are stored UN-optimized! Enable gd in cPanel → Select PHP Version → Extensions') . "\n";
echo (extension_loaded('zip') ? 'OK' : 'FAIL') . ": Zip extension " . (extension_loaded('zip') ? 'enabled — Excel price import works' : 'MISSING — enable extension=zip in php.ini for ورود قیمت') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n\n";

echo "OK: config.local.php exists\n";

$config = require $configPath;
$required = ['db_host', 'db_name', 'db_user', 'db_pass'];
foreach ($required as $key) {
    $val = $config[$key] ?? null;
    if ($val === null || $val === '' || str_starts_with((string) $val, 'YOUR_')) {
        echo "FAIL: db_* key \"{$key}\" is empty or still a placeholder.\n";
        exit(1);
    }
}

echo "OK: db_* keys look filled\n";
echo "db_name: {$config['db_name']}\n";
echo "db_user: {$config['db_user']}\n\n";

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $config['db_charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "OK: MySQL connection succeeded\n\n";
} catch (Throwable $e) {
    echo "FAIL: MySQL connection — {$e->getMessage()}\n";
    echo "Fix: cPanel → MySQL Databases — verify db name, user, password, and ALL PRIVILEGES.\n";
    exit(1);
}

$tables = ['admin_users', 'hero_slides', 'products', 'site_settings'];
foreach ($tables as $table) {
    try {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        echo "OK: table {$table}\n";
    } catch (Throwable $e) {
        echo "FAIL: table {$table} — import deploy/st-new-web.sql or public/cms/schema.sql in phpMyAdmin\n";
    }
}

$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
echo "\nadmin_users rows: {$adminCount}\n";
if ($adminCount === 0) {
    echo "Hint: open /test2/cms/install.php once OR import st-new-web.sql (includes admin from XAMPP).\n";
}

$smsPdo = null;
if (!empty($config['sms_db_name']) && !empty($config['sms_db_user'])) {
    try {
        $smsDsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['sms_db_host'] ?? 'localhost',
            $config['sms_db_name'],
            $config['sms_db_charset'] ?? 'utf8mb4'
        );
        $smsPdo = new PDO($smsDsn, $config['sms_db_user'], $config['sms_db_pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $smsPdo->query('SELECT 1 FROM old_serials LIMIT 1');
        echo "OK: sms_db old_serials reachable\n";
    } catch (Throwable $e) {
        echo "WARN: sms_db — {$e->getMessage()}\n";
    }
}

echo "\nDone. Delete db-check.php when finished.\n";
