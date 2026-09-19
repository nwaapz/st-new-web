<?php
declare(strict_types=1);

/**
 * Site-wide header/footer logo image (CMS content — not Font Lab).
 */
function site_logo_setting_key(): string
{
    return 'site_logo_image';
}

function site_logo_default_path(): string
{
    return '/images/logo.png';
}

function site_logo_stored_path(): string
{
    return trim(cms_setting_get(site_logo_setting_key(), ''));
}

function site_logo_load(): string
{
    $stored = site_logo_stored_path();
    return $stored !== '' ? $stored : site_logo_default_path();
}

function site_logo_save(string $path): void
{
    cms_setting_set(site_logo_setting_key(), trim($path));
}

/** @return array{logo_image: ?string} */
function site_logo_public_payload(): array
{
    $image = site_logo_stored_path();

    return [
        'logo_image' => $image !== '' ? $image : null,
    ];
}
