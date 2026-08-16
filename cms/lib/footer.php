<?php
declare(strict_types=1);

const FOOTER_SOCIAL_MAX = 24;
const FOOTER_LINK_MAX = 16;

/** @return array<string, array{label: string, placeholder: string}> */
function footer_networks(): array
{
    return [
        'instagram' => ['label' => 'اینستاگرام', 'placeholder' => 'https://www.instagram.com/...'],
        'telegram' => ['label' => 'تلگرام', 'placeholder' => 'https://t.me/...'],
        'whatsapp' => ['label' => 'واتساپ', 'placeholder' => 'https://wa.me/98...'],
        'aparat' => ['label' => 'آپارات', 'placeholder' => 'https://www.aparat.com/...'],
        'rubika' => ['label' => 'روبیکا', 'placeholder' => 'https://rubika.ir/...'],
        'balad' => ['label' => 'بلد', 'placeholder' => 'https://balad.ir/...'],
        'bale' => ['label' => 'بله', 'placeholder' => 'https://ble.ir/...'],
        'eitaa' => ['label' => 'ایتا', 'placeholder' => 'https://eitaa.com/...'],
        'soroush' => ['label' => 'سروش', 'placeholder' => 'https://splus.ir/...'],
        'linkedin' => ['label' => 'لینکدین', 'placeholder' => 'https://www.linkedin.com/...'],
        'youtube' => ['label' => 'یوتیوب', 'placeholder' => 'https://www.youtube.com/...'],
        'x' => ['label' => 'ایکس / توییتر', 'placeholder' => 'https://x.com/...'],
        'facebook' => ['label' => 'فیسبوک', 'placeholder' => 'https://www.facebook.com/...'],
        'tiktok' => ['label' => 'تیک‌تاک', 'placeholder' => 'https://www.tiktok.com/...'],
        'threads' => ['label' => 'تردز', 'placeholder' => 'https://www.threads.net/...'],
        'pinterest' => ['label' => 'پینترست', 'placeholder' => 'https://www.pinterest.com/...'],
        'discord' => ['label' => 'دیسکورد', 'placeholder' => 'https://discord.gg/...'],
        'email' => ['label' => 'ایمیل', 'placeholder' => 'mailto:info@example.com'],
        'phone' => ['label' => 'تلفن', 'placeholder' => 'tel:+9821...'],
        'website' => ['label' => 'وب‌سایت', 'placeholder' => 'https://...'],
        'custom' => ['label' => 'لینک سفارشی', 'placeholder' => 'https://...'],
    ];
}

function footer_default_config(): array
{
    $networks = footer_networks();
    return [
        'tagline' => 'ارتباط با استارتک در شبکه‌های اجتماعی',
        'copyright' => '© {year} استارتک. تمامی حقوق محفوظ است.',
        'creditText' => 'طراحی سایت از شرکت پایامش',
        'creditHref' => 'https://payamesh.ir',
        'showCredit' => true,
        'showFactories' => true,
        'factoriesLabel' => 'کارخانه‌ها',
        'navLabel' => 'پیوندهای پاورقی',
        'socialLabel' => 'شبکه‌های اجتماعی',
        'phone' => '',
        'whatsapp' => '',
        'email' => '',
        'address' => '',
        'links' => [
            ['label' => 'نمایندگان', 'href' => '/branch-portal'],
            ['label' => 'خدمات پس از فروش', 'href' => '/warranty'],
            ['label' => 'فروشگاه', 'href' => '/products'],
            ['label' => 'تکنولوژی', 'href' => '/danestaniha'],
            ['label' => 'دستیار اوستا کار', 'href' => '/customerclub'],
            ['label' => 'درباره ما', 'href' => '/about'],
            ['label' => 'تماس با ما', 'href' => '/contact'],
        ],
        'socials' => [
            ['network' => 'instagram', 'label' => $networks['instagram']['label'], 'href' => 'https://www.instagram.com/'],
            ['network' => 'telegram', 'label' => $networks['telegram']['label'], 'href' => 'https://t.me/'],
            ['network' => 'linkedin', 'label' => $networks['linkedin']['label'], 'href' => 'https://www.linkedin.com/'],
            ['network' => 'youtube', 'label' => $networks['youtube']['label'], 'href' => 'https://www.youtube.com/'],
        ],
    ];
}

function footer_blank_social(?string $network = null): array
{
    $networks = footer_networks();
    $id = $network !== null && isset($networks[$network]) ? $network : 'instagram';
    return [
        'network' => $id,
        'label' => $networks[$id]['label'],
        'href' => '',
    ];
}

function footer_blank_link(): array
{
    return ['label' => '', 'href' => ''];
}

function footer_sanitize_href(string $href): string
{
    $href = trim($href);
    if ($href === '') {
        return '';
    }
    if (preg_match('#^(https?:|mailto:|tel:|/|//)#i', $href) === 1) {
        return $href;
    }
    return 'https://' . $href;
}

function footer_normalize_social(array $row, bool $requireHref = false): ?array
{
    $networks = footer_networks();
    $network = trim((string) ($row['network'] ?? ''));
    if (!isset($networks[$network])) {
        $network = 'custom';
    }
    $rawHref = trim((string) ($row['href'] ?? ''));
    $href = $rawHref === '' ? '' : footer_sanitize_href($rawHref);
    $label = trim((string) ($row['label'] ?? ''));
    if ($label === '') {
        $label = $networks[$network]['label'];
    }
    if ($requireHref && $href === '') {
        return null;
    }
    return [
        'network' => $network,
        'label' => $label,
        'href' => $href,
    ];
}

function footer_normalize_link(array $row, bool $requireHref = false): ?array
{
    $label = trim((string) ($row['label'] ?? ''));
    $rawHref = trim((string) ($row['href'] ?? ''));
    $href = $rawHref === '' ? '' : footer_sanitize_href($rawHref);
    if ($label === '' && $href === '') {
        return $requireHref ? null : ['label' => '', 'href' => ''];
    }
    if ($requireHref && ($label === '' || $href === '')) {
        return null;
    }
    return ['label' => $label, 'href' => $href];
}

function footer_parse_config($raw): array
{
    $defaults = footer_default_config();
    if (!is_array($raw)) {
        return $defaults;
    }

    $bool = static function ($value, bool $fallback): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) || is_int($value)) {
            return !in_array((string) $value, ['0', 'false', ''], true);
        }
        return $fallback;
    };
    $text = static function ($value, string $fallback): string {
        return is_string($value) ? $value : $fallback;
    };

    $links = [];
    if (isset($raw['links']) && is_array($raw['links'])) {
        foreach ($raw['links'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $link = footer_normalize_link($row, false);
            if ($link !== null) {
                $links[] = $link;
            }
            if (count($links) >= FOOTER_LINK_MAX) {
                break;
            }
        }
    } else {
        $links = $defaults['links'];
    }

    $socials = [];
    if (isset($raw['socials']) && is_array($raw['socials'])) {
        foreach ($raw['socials'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $social = footer_normalize_social($row, false);
            if ($social !== null) {
                $socials[] = $social;
            }
            if (count($socials) >= FOOTER_SOCIAL_MAX) {
                break;
            }
        }
    } else {
        $socials = $defaults['socials'];
    }

    return [
        'tagline' => $text($raw['tagline'] ?? null, $defaults['tagline']),
        'copyright' => $text($raw['copyright'] ?? null, $defaults['copyright']),
        'creditText' => $text($raw['creditText'] ?? null, $defaults['creditText']),
        'creditHref' => footer_sanitize_href($text($raw['creditHref'] ?? null, $defaults['creditHref'])),
        'showCredit' => $bool($raw['showCredit'] ?? null, true),
        'showFactories' => $bool($raw['showFactories'] ?? null, true),
        'factoriesLabel' => $text($raw['factoriesLabel'] ?? null, $defaults['factoriesLabel']),
        'navLabel' => $text($raw['navLabel'] ?? null, $defaults['navLabel']),
        'socialLabel' => $text($raw['socialLabel'] ?? null, $defaults['socialLabel']),
        'phone' => trim($text($raw['phone'] ?? null, '')),
        'whatsapp' => trim($text($raw['whatsapp'] ?? null, '')),
        'email' => trim($text($raw['email'] ?? null, '')),
        'address' => trim($text($raw['address'] ?? null, '')),
        'links' => $links,
        'socials' => $socials,
    ];
}

function footer_load_config(): array
{
    $raw = cms_setting_get('footer_config', '');
    if ($raw === '') {
        return footer_default_config();
    }
    $decoded = json_decode($raw, true);
    return footer_parse_config(is_array($decoded) ? $decoded : null);
}

function footer_save_config(array $config): void
{
    cms_setting_set(
        'footer_config',
        (string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function footer_collect_from_post(): array
{
    $networks = footer_networks();
    $linkCount = max(0, (int) ($_POST['link_count'] ?? 0));
    $socialCount = max(0, (int) ($_POST['social_count'] ?? 0));
    if ($linkCount > FOOTER_LINK_MAX) {
        $linkCount = FOOTER_LINK_MAX;
    }
    if ($socialCount > FOOTER_SOCIAL_MAX) {
        $socialCount = FOOTER_SOCIAL_MAX;
    }

    $links = [];
    for ($i = 0; $i < $linkCount; $i++) {
        $links[] = [
            'label' => trim((string) ($_POST['link_label_' . $i] ?? '')),
            'href' => trim((string) ($_POST['link_href_' . $i] ?? '')),
        ];
    }

    $socials = [];
    for ($i = 0; $i < $socialCount; $i++) {
        $network = trim((string) ($_POST['social_network_' . $i] ?? 'custom'));
        if (!isset($networks[$network])) {
            $network = 'custom';
        }
        $socials[] = [
            'network' => $network,
            'label' => trim((string) ($_POST['social_label_' . $i] ?? '')),
            'href' => trim((string) ($_POST['social_href_' . $i] ?? '')),
        ];
    }

    return footer_parse_config([
        'tagline' => (string) ($_POST['tagline'] ?? ''),
        'copyright' => (string) ($_POST['copyright'] ?? ''),
        'creditText' => (string) ($_POST['creditText'] ?? ''),
        'creditHref' => (string) ($_POST['creditHref'] ?? ''),
        'showCredit' => isset($_POST['showCredit']),
        'showFactories' => isset($_POST['showFactories']),
        'factoriesLabel' => (string) ($_POST['factoriesLabel'] ?? ''),
        'navLabel' => (string) ($_POST['navLabel'] ?? ''),
        'socialLabel' => (string) ($_POST['socialLabel'] ?? ''),
        'phone' => (string) ($_POST['phone'] ?? ''),
        'whatsapp' => (string) ($_POST['whatsapp'] ?? ''),
        'email' => (string) ($_POST['email'] ?? ''),
        'address' => (string) ($_POST['address'] ?? ''),
        'links' => $links,
        'socials' => $socials,
    ]);
}

function footer_editor_state_from_post(): array
{
    return footer_collect_from_post();
}

function footer_public_payload(): array
{
    $config = footer_load_config();
    $links = [];
    foreach ($config['links'] as $row) {
        $link = footer_normalize_link($row, true);
        if ($link !== null) {
            $links[] = $link;
        }
    }
    $socials = [];
    foreach ($config['socials'] as $row) {
        $social = footer_normalize_social($row, true);
        if ($social !== null) {
            $socials[] = $social;
        }
    }
    $config['links'] = $links;
    $config['socials'] = $socials;
    return $config;
}
