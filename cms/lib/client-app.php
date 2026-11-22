<?php
declare(strict_types=1);

require_once __DIR__ . '/uploads.php';

function client_app_apk_setting_key(): string
{
    return 'client_app_apk';
}

function client_app_version_setting_key(): string
{
    return 'client_app_version';
}

function client_app_stored_path(): string
{
    return trim(cms_setting_get(client_app_apk_setting_key(), ''));
}

function client_app_stored_version(): string
{
    return trim(cms_setting_get(client_app_version_setting_key(), ''));
}

function client_app_is_public_apk_path(string $path): bool
{
    return (bool) preg_match('#^/uploads/apps/[^/]+\.apk$#i', $path);
}

function client_app_absolute_path(?string $publicPath = null): ?string
{
    $path = $publicPath !== null ? trim($publicPath) : client_app_stored_path();
    if (!client_app_is_public_apk_path($path)) {
        return null;
    }
    $appsDir = cms_uploads_root() . DIRECTORY_SEPARATOR . 'apps';
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

function client_app_delete_file(string $publicPath): void
{
    $abs = client_app_absolute_path($publicPath);
    if ($abs === null) {
        return;
    }
    @unlink($abs);
}

function client_app_save(string $path, string $version): void
{
    cms_setting_set(client_app_apk_setting_key(), trim($path));
    cms_setting_set(client_app_version_setting_key(), trim($version));
}

/** @return array{apk: ?string, version: ?string, filename: ?string} */
function client_app_public_payload(): array
{
    $path = client_app_stored_path();
    if ($path === '' || client_app_absolute_path($path) === null) {
        return [
            'apk' => null,
            'version' => null,
            'filename' => null,
        ];
    }
    $version = client_app_stored_version();

    return [
        'apk' => $path,
        'version' => $version !== '' ? $version : null,
        'filename' => basename($path),
    ];
}
