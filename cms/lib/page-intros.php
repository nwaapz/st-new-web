<?php
declare(strict_types=1);

/**
 * CMS-editable page intro (title + explanation) for public section headers.
 * Keys: {prefix}_title / {prefix}_explanation in site_settings.
 */

/**
 * Known section prefixes and their default Persian copy.
 *
 * @return array<string, array{title:string, explanation:string}>
 */
function cms_page_intro_defaults(): array
{
    return [
        'branch_portal' => [
            'title' => 'نمایندگان استارتک',
            'explanation' => 'استان را از نقشه یا فهرست انتخاب کنید، شعبه را ببینید و پیام بفرستید.',
        ],
        'warranty' => [
            'title' => 'خدمات پس از فروش',
            'explanation' => 'سریال محصول خود را بررسی کنید، گارانتی جدید ثبت کنید یا گزارش گارانتی‌های ثبت‌شده با شماره موبایل حساب خود را مشاهده کنید.',
        ],
        'shop' => [
            'title' => 'فروشگاه',
            'explanation' => 'فیلتر بر اساس کارخانه، خودرو و دسته‌بندی',
        ],
        'tech_header' => [
            'title' => 'تکنولوژی',
            'explanation' => 'شبیه‌سازی‌های آزمایشگاهی تلفات حرارتی و انتقال توان تسمه وی‌شکل استارتک، تحت بار و سرعت دورانی واقعی.',
        ],
        'customerclub' => [
            'title' => 'باشگاه مشتریان',
            'explanation' => 'دفترچه دیجیتال خودرو برای مکانیک‌ها — با شماره موبایل تعمیرگاه وارد شوید و پرونده مشتری، خودرو و سرویس‌ها را مدیریت کنید.',
        ],
        'about' => [
            'title' => 'چرا فقط استارتک',
            'explanation' => 'استارتک طراح و تولیدکننده تسمه‌های درایو صنعتی و خودرویی است — قطعاتی که برای شرایط سخت ساخته می‌شوند تا هزینه پنهان خرابی کم شود.',
        ],
        'contact' => [
            'title' => 'تماس با ما',
            'explanation' => 'از تلفن، واتساپ یا بله با ما در ارتباط باشید، یا همین‌جا وارد شوید و برای پشتیبانی پیام بگذارید.',
        ],
    ];
}

/**
 * @return array{title:string, explanation:string}
 */
function cms_page_intro_get(string $prefix, bool $withDefaults = true): array
{
    $defaults = cms_page_intro_defaults()[$prefix] ?? ['title' => '', 'explanation' => ''];
    $title = trim(cms_setting_get($prefix . '_title', ''));
    $explanation = trim(cms_setting_get($prefix . '_explanation', ''));

    if ($withDefaults) {
        if ($title === '') {
            $title = $defaults['title'];
        }
        if ($explanation === '') {
            $explanation = $defaults['explanation'];
        }
    }

    return [
        'title' => $title,
        'explanation' => $explanation,
    ];
}

/**
 * Raw stored values (may be empty) for CMS form fields.
 *
 * @return array{title:string, explanation:string}
 */
function cms_page_intro_stored(string $prefix): array
{
    return cms_page_intro_get($prefix, false);
}

function cms_page_intro_save(string $prefix, string $title, string $explanation): void
{
    cms_setting_set($prefix . '_title', trim($title));
    cms_setting_set($prefix . '_explanation', trim($explanation));
}

/**
 * Public API fragment: empty strings when unset so clients apply their own defaults.
 *
 * @return array{title:string, explanation:string}
 */
function cms_page_intro_public(string $prefix): array
{
    return cms_page_intro_stored($prefix);
}
