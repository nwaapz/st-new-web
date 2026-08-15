<?php
declare(strict_types=1);

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        return substr($haystack, -$len) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) !== false;
    }
}

function cms_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $local = __DIR__ . '/config.local.php';
    if (!is_file($local)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Missing config.local.php — copy config.local.example.php and fill MySQL settings.";
        exit;
    }

    $config = require $local;
    return $config;
}

function cms_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = cms_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $c['db_host'],
        $c['db_name'],
        $c['db_charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

/**
 * Optional PDO for the warranty SMS DB (startech_sms / Mcode).
 * Returns null when sms_db_* is not configured.
 */
function cms_sms_pdo(): ?PDO
{
    static $pdo = null;
    static $tried = false;
    if ($tried) {
        return $pdo instanceof PDO ? $pdo : null;
    }
    $tried = true;

    $c = cms_config();
    $host = trim((string) ($c['sms_db_host'] ?? ''));
    $name = trim((string) ($c['sms_db_name'] ?? ''));
    $user = trim((string) ($c['sms_db_user'] ?? ''));
    if ($host === '' || $name === '' || $user === '') {
        $pdo = null;
        return null;
    }

    try {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $host,
            $name,
            $c['sms_db_charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $user, (string) ($c['sms_db_pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        error_log('[cms_sms_pdo] ' . $e->getMessage());
        $pdo = null;
        return null;
    }
}

function cms_slugify(string $input): string
{
    $input = trim(mb_strtolower($input, 'UTF-8'));
    $input = preg_replace('/[^\p{L}\p{N}]+/u', '-', $input) ?? '';
    $input = trim($input, '-');
    if ($input === '') {
        return 'item-' . bin2hex(random_bytes(4));
    }
    return mb_substr($input, 0, 180, 'UTF-8');
}

function cms_site_base(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    // /test2/cms/factories.php → /test2
    $cmsDir = rtrim(dirname($script), '/');
    $base = rtrim(dirname($cmsDir), '/');
    return $base === '/' ? '' : $base;
}

function cms_asset_url(string $path): string
{
    if ($path === '') {
        return $path;
    }
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }
    return cms_site_base() . '/' . ltrim($path, '/');
}

function cms_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cms_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function cms_flash(string $message, string $type = 'ok'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['cms_flash'] = ['message' => $message, 'type' => $type];
}

function cms_take_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['cms_flash'])) {
        return null;
    }
    $flash = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
    return $flash;
}

function cms_ensure_settings_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_settings (
          setting_key VARCHAR(64) NOT NULL,
          setting_value TEXT NOT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function cms_setting_get(string $key, string $default = ''): string
{
    try {
        $pdo = cms_pdo();
        cms_ensure_settings_table($pdo);
        $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function cms_setting_set(string $key, string $value): void
{
    $pdo = cms_pdo();
    cms_ensure_settings_table($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function cms_call_for_price_enabled(): bool
{
    return cms_setting_get('call_for_price', '0') === '1';
}

function cms_call_for_price_label(): string
{
    return 'تماس برای قیمت';
}
