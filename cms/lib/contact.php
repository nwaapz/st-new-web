<?php
declare(strict_types=1);

/**
 * Public contact-page settings (phones, WhatsApp, Bale) stored in site_settings.
 */

function contact_default_title(): string
{
    return 'تماس با ما';
}

function contact_default_subtitle(): string
{
    return 'پشتیبانی، سفارش و نمایندگی';
}

function contact_default_explanation(): string
{
    return 'از تلفن، واتساپ یا بله با ما در ارتباط باشید، یا همین‌جا وارد شوید و برای پشتیبانی پیام بگذارید.';
}

function contact_default_llm_prompt(): string
{
    return "شما دستیار پشتیبانی استارتک هستید. استارتک طراح و تولیدکننده تسمه‌های درایو صنعتی و خودرویی است.\n"
        . "به فارسی، کوتاه و مودب پاسخ بده. قیمت قطعی، موجودی انبار و ثبت سفارش را حدس نزن؛ کاربر را به گفتگوی پشتیبانی سایت یا تماس تلفنی راهنمایی کن.\n"
        . "اگر کاربر خواست با انسان / اپراتور صحبت کند، بگو از صفحه «تماس با ما» در سایت پیام بگذارد یا با شماره‌های تماس بگیرد.";
}

function contact_digits(string $value): string
{
    $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];
    $normalized = strtr(trim($value), $map);
    return preg_replace('/\D+/', '', $normalized) ?? '';
}

function contact_tel_href(string $raw): string
{
    $digits = contact_digits($raw);
    return $digits !== '' ? 'tel:' . $digits : '';
}

function contact_whatsapp_url(string $raw): string
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return '';
    }
    if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
        return $trimmed;
    }
    $digits = contact_digits($trimmed);
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '98') === 0) {
        $intl = $digits;
    } elseif (strpos($digits, '0') === 0) {
        $intl = '98' . substr($digits, 1);
    } else {
        $intl = '98' . $digits;
    }
    return 'https://wa.me/' . $intl;
}

function contact_bale_username(string $raw): string
{
    $value = trim($raw);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('#^https?://(ble\.ir|bale\.ai)/#i', '', $value) ?? $value;
    return ltrim(trim($value), '@');
}

function contact_bale_url(string $raw): string
{
    $username = contact_bale_username($raw);
    return $username !== '' ? 'https://ble.ir/' . $username : '';
}

function contact_phone_list(string $raw): array
{
    $parts = preg_split('/[\n,;]+/', $raw);
    if (!is_array($parts)) {
        return [];
    }
    $out = [];
    foreach ($parts as $part) {
        $display = trim((string) $part);
        if ($display === '') {
            continue;
        }
        $href = contact_tel_href($display);
        if ($href === '') {
            continue;
        }
        $out[] = [
            'display' => $display,
            'href' => $href,
        ];
    }
    return $out;
}

/**
 * Public JSON for the contact page. Empty strings when unset.
 *
 * @return array<string, mixed>
 */
function contact_public_payload(): array
{
    $title = trim(cms_setting_get('contact_title', ''));
    $subtitle = trim(cms_setting_get('contact_subtitle', ''));
    $explanation = trim(cms_setting_get('contact_explanation', ''));
    $landlines = contact_phone_list(cms_setting_get('contact_landline', ''));
    $mobile = trim(cms_setting_get('contact_mobile', ''));
    $whatsapp = trim(cms_setting_get('contact_whatsapp', ''));
    $bale = trim(cms_setting_get('contact_bale_username', ''));
    $firstLandline = $landlines[0] ?? null;

    return [
        'title' => $title,
        'subtitle' => $subtitle,
        'explanation' => $explanation,
        'landline' => is_array($firstLandline) ? (string) $firstLandline['display'] : '',
        'landline_href' => is_array($firstLandline) ? (string) $firstLandline['href'] : '',
        'landlines' => $landlines,
        'mobile' => $mobile,
        'mobile_href' => contact_tel_href($mobile),
        'whatsapp' => $whatsapp,
        'whatsapp_url' => contact_whatsapp_url($whatsapp),
        'bale_username' => contact_bale_username($bale),
        'bale_url' => contact_bale_url($bale),
        'hours' => trim(cms_setting_get('contact_hours', '')),
        'address' => trim(cms_setting_get('contact_address', '')),
        'hero_image' => trim(cms_setting_get('contact_hero_image', '')),
    ];
}

function contact_ensure_webhook_secret(): string
{
    $secret = trim(cms_setting_get('contact_bale_webhook_secret', ''));
    if ($secret === '') {
        try {
            $secret = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $secret = bin2hex(hash('sha256', uniqid('bale', true), true));
        }
        cms_setting_set('contact_bale_webhook_secret', $secret);
    }
    return $secret;
}

function contact_public_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = 'localhost';
    }
    return $scheme . '://' . $host;
}

function contact_bale_webhook_url(): string
{
    $secret = contact_ensure_webhook_secret();
    $base = rtrim(cms_site_base(), '/');
    return contact_public_origin() . $base . '/api/bale-webhook.php?secret=' . rawurlencode($secret);
}
