<?php
declare(strict_types=1);

/** Same base URL as Android `CMS_BASE_URL` (remote tests). */
const CMS_PUBLIC_SITE_BASE = 'https://startechgroup.ir/test2';

function cms_public_site_base(): string
{
    $override = getenv('CMS_PUBLIC_BASE_URL');
    if (is_string($override) && trim($override) !== '') {
        return rtrim(trim($override), '/');
    }
    return CMS_PUBLIC_SITE_BASE;
}

function cms_is_production_host(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return str_contains($host, 'startechgroup.ir');
}
