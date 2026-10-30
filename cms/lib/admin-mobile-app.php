<?php
declare(strict_types=1);

require_once __DIR__ . '/uploads.php';

function admin_mobile_app_apk_setting_key(): string
{
    return 'admin_mobile_app_apk';
}

function admin_mobile_app_version_setting_key(): string
{
    return 'admin_mobile_app_version';
}

function admin_mobile_app_stored_path(): string
{
    return trim(cms_setting_get(admin_mobile_app_apk_setting_key(), ''));
}

function admin_mobile_app_stored_version(): string
{
    return trim(cms_setting_get(admin_mobile_app_version_setting_key(), ''));
}

function admin_mobile_app_is_public_apk_path(string $path): bool
{
    return (bool) preg_match('#^/uploads/apps/admin/[^/]+\.apk$#i', $path);
}

function admin_mobile_app_absolute_path(?string $publicPath = null): ?string
{
    $path = $publicPath !== null ? trim($publicPath) : admin_mobile_app_stored_path();
    if (!admin_mobile_app_is_public_apk_path($path)) {
        return null;
    }
    $appsDir = cms_uploads_root() . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'admin';
    $abs = $appsDir . DIRECTORY_SEPARATOR . basename($path);
    if (!is_file($abs)) {
        return null;
    }
    $realFile = realpath($abs);
    $realDir = realpath($appsDir);
    if ($realFile === false || $realDir === false) {
        return null;
    }
    if (strpos($realFile, $realDir) !== 0) {
        return null;
    }

    return $realFile;
}

function admin_mobile_app_delete_file(string $publicPath): void
{
    $abs = admin_mobile_app_absolute_path($publicPath);
    if ($abs === null) {
        return;
    }
    @unlink($abs);
}

function admin_mobile_app_save(string $path, string $version): void
{
    cms_setting_set(admin_mobile_app_apk_setting_key(), trim($path));
    cms_setting_set(admin_mobile_app_version_setting_key(), trim($version));
}
