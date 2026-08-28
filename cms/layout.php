<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/uploads.php';

/**
 * @param 'website'|'shop'|'communication'|'advanced'|'' $section
 */
function cms_layout_start(string $title, string $username = '', string $section = ''): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    $rootNav = [
        'website.php' => ['label' => 'نمایش وب', 'section' => 'website', 'class' => ''],
        'shop.php' => ['label' => 'فروشگاه', 'section' => 'shop', 'class' => ''],
        'communication.php' => ['label' => 'ارتباطات', 'section' => 'communication', 'class' => ''],
        'advanced.php' => [
            'label' => 'تنظیمات پیشرفته',
            'section' => 'advanced',
            'class' => ' cms-root-tab--advanced',
        ],
    ];

    $subNav = [];
    if ($section === 'website') {
        $subNav = [
            'website.php' => 'خلاصه',
            'hero.php' => 'صفحه اصلی',
            'branches.php' => 'نمایندگان',
            'warranty.php' => 'گارانتی',
            'customerclub.php' => 'باشگاه مشتریان',
            'danestani-media.php' => 'تکنولوژی',
            'about.php' => 'درباره ما',
            'contact.php' => 'تماس با ما',
            'footer.php' => 'پاورقی',
        ];
    } elseif ($section === 'shop') {
        $subNav = [
            'shop.php' => 'خلاصه',
            'factories.php' => 'کارخانه‌ها',
            'car-models.php' => 'مدل‌ها',
            'categories.php' => 'دسته‌بندی‌ها',
            'product-series.php' => 'سری محصولات',
            'products.php' => 'محصولات',
            'media-upload.php' => 'آپلود تصاویر',
            'media-library.php' => 'کتابخانه تصاویر',
            'product-price-import.php' => 'ورود قیمت',
            'product-reviews.php' => 'نظرات',
            'orders.php' => 'سفارش‌ها',
        ];
    } elseif ($section === 'communication') {
        $subNav = [
            'communication.php' => 'خلاصه',
            'messages.php' => 'پیام‌ها',
            'branch-messages.php' => 'پیام نمایندگان',
            'branch-tickets.php' => 'تیکت نمایندگان',
        ];
    } elseif ($section === 'advanced') {
        $fontLabHref = rtrim(cms_site_base(), '/') . '/font-lab/';
        $subNav = [
            'advanced.php' => 'خلاصه',
            'sms-settings.php' => 'پیامک',
            'mechanic-services.php' => 'دوره سرویس‌ها',
            $fontLabHref => 'Font Lab',
        ];
    }

    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="color-scheme" content="dark">';
    echo '<title>' . cms_h($title) . ' | StarTech CMS</title>';
    echo '<link rel="stylesheet" href="assets/cms.css?v=23">';
    echo '</head><body><div class="cms-shell">';

    echo '<header class="cms-nav">';
    echo '<div class="cms-nav__brand"><a href="index.php" class="cms-nav__brand-link"><strong>StarTech CMS</strong></a>';
    if ($username !== '') {
        echo ' <span class="cms-muted">(' . cms_h($username) . ')</span>';
    }
    echo '</div>';

    echo '<nav class="cms-nav__roots" aria-label="بخش‌های اصلی">';
    foreach ($rootNav as $href => $item) {
        $active = $section === $item['section'] ? ' is-active' : '';
        $extra = (string) ($item['class'] ?? '');
        echo '<a class="cms-root-tab' . $extra . $active . '" href="' . cms_h($href) . '">';
        echo cms_h($item['label']);
        if (!empty($item['badge'])) {
            echo ' <span class="cms-root-tab__badge">' . cms_h((string) $item['badge']) . '</span>';
        }
        echo '</a>';
    }
    echo '</nav>';

    echo '<a class="cms-btn cms-btn--ghost" href="logout.php">خروج</a>';
    echo '</header>';

    $subNavAliases = [
        'mechanic-broadcasts.php' => 'customerclub.php',
        'mechanics.php' => 'customerclub.php',
        'mechanic-view.php' => 'customerclub.php',
        'rewards.php' => 'hero.php',
        'home-backgrounds.php' => 'hero.php',
        'home-pattern.php' => 'hero.php',
        'hero-mobile.php' => 'hero.php',
    ];

    $nestedGroups = [
        'customerclub.php' => [
            'customerclub.php' => 'صفحه ورود',
            'mechanics.php' => 'مکانیک‌ها',
            'mechanic-broadcasts.php' => 'پیام گروهی',
        ],
        'hero.php' => [
            'hero.php' => 'هیرو',
            'hero-mobile.php' => 'هیرو موبایل',
            'rewards.php' => 'جوایز',
            'home-backgrounds.php' => 'پس‌زمینه و متن',
            'home-pattern.php' => 'الگوی تکرارشونده',
        ],
    ];
    $nestedParent = $subNavAliases[$script] ?? $script;
    $nestedNav = $nestedGroups[$nestedParent] ?? [];

    if ($subNav !== []) {
        echo '<nav class="cms-subnav" aria-label="زیرمنو">';
        foreach ($subNav as $href => $label) {
            $isExternal = strpos($href, 'http') === 0 || strpos($href, '/font-lab') !== false;
            $parentActive = (!$isExternal && ($script === $href || ($subNavAliases[$script] ?? '') === $href));
            $active = $parentActive ? ' is-active' : '';
            $attrs = $isExternal ? ' target="_blank" rel="noopener"' : '';
            echo '<a class="cms-subnav__link' . $active . '" href="' . cms_h($href) . '"' . $attrs . '>' . cms_h($label) . '</a>';
        }
        echo '</nav>';
    }

    if ($nestedNav !== []) {
        echo '<nav class="cms-subnav cms-subnav--nested" aria-label="زیرمنوی بخش">';
        $nestedActive = $script === 'mechanic-view.php' ? 'mechanics.php' : $script;
        foreach ($nestedNav as $href => $label) {
            $active = $nestedActive === $href ? ' is-active' : '';
            echo '<a class="cms-subnav__link' . $active . '" href="' . cms_h($href) . '">' . cms_h($label) . '</a>';
        }
        echo '</nav>';
    }

    echo '<main class="cms-main">';
    $flash = cms_take_flash();
    if ($flash) {
        $cls = ($flash['type'] ?? '') === 'error' ? 'cms-error' : 'cms-ok';
        echo '<p class="' . $cls . '">' . cms_h($flash['message']) . '</p>';
    }
}

function cms_layout_end(): void
{
    echo '</main></div>';
    echo '<div id="cms-media-modal" class="cms-media-modal" hidden>';
    echo '<div class="cms-media-modal__panel" role="dialog" aria-modal="true" aria-label="انتخاب از سرور">';
    echo '<div class="cms-media-modal__head"><strong id="cms-media-modal-title">انتخاب از سرور</strong>';
    echo '<button type="button" class="cms-btn cms-btn--ghost" onclick="cmsCloseMediaPicker()">بستن</button></div>';
    echo '<p class="cms-muted" id="cms-media-status">در حال بارگذاری…</p>';
    echo '<div id="cms-media-session-block" class="cms-media-session-block" hidden>';
    echo '<h3 class="cms-media-session-block__title" id="cms-media-session-title">تصاویر این نشست</h3>';
    echo '<div class="cms-media-grid cms-media-grid--session" id="cms-media-session-grid"></div>';
    echo '</div>';
    echo '<h3 class="cms-media-all-title" id="cms-media-all-title" hidden>همه تصاویر</h3>';
    echo '<div class="cms-media-grid" id="cms-media-grid"></div>';
    echo '</div></div>';
    echo '<script src="assets/cms-upload.js?v=4"></script>';
    echo '<script src="assets/cms-check-list-filter.js?v=3"></script>';
    echo '</body></html>';
}

function cms_list_thumb(?string $path): void
{
    $path = trim((string) $path);
    if ($path === '') {
        echo '<span class="cms-list-thumb cms-list-thumb--empty" aria-hidden="true"></span>';
        return;
    }
    $src = cms_asset_url($path);
    echo '<img class="cms-list-thumb" src="' . cms_h($src) . '" alt="" loading="lazy">';
}

function cms_image_field(string $name, string $label, string $value): void
{
    $previewId = 'preview-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    $textId = 'text-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    $fileId = 'file-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    $previewSrc = $value !== '' ? cms_asset_url($value) . '?v=' . rawurlencode(basename($value)) : '';

    echo '<div class="cms-field">';
    echo '<span class="cms-label">' . cms_h($label) . '</span>';
    echo '<div class="cms-image-row">';
    if ($value !== '') {
        echo '<img id="' . cms_h($previewId) . '" class="cms-image-preview" src="' . cms_h($previewSrc) . '" alt="">';
    } else {
        echo '<img id="' . cms_h($previewId) . '" class="cms-image-preview cms-image-preview--empty" src="" alt="" style="display:none">';
        echo '<div id="' . cms_h($previewId) . '-empty" class="cms-image-preview cms-image-preview--empty">بدون تصویر</div>';
    }
    echo '<div class="cms-image-actions">';
    echo '<input id="' . cms_h($textId) . '" class="cms-input" type="text" name="' . cms_h($name) . '" value="' . cms_h($value) . '" dir="ltr" placeholder="/uploads/...">';
    echo '<div class="cms-btn-row" style="margin-top:0">';
    echo '<label class="cms-file-pick" for="' . cms_h($fileId) . '">آپلود از رایانه</label>';
    echo '<button type="button" class="cms-btn cms-btn--secondary" onclick="cmsOpenMediaPicker(\'' . cms_h($textId) . '\',\'' . cms_h($previewId) . '\',{kind:\'image\'})">انتخاب از سرور</button>';
    echo '</div>';
    echo '<input id="' . cms_h($fileId) . '" class="cms-file-input" type="file" name="' . cms_h($name) . '_file" accept="image/jpeg,image/png,image/webp,image/gif" '
        . 'data-cms-upload="image" data-cms-text-id="' . cms_h($textId) . '" data-cms-preview-id="' . cms_h($previewId) . '" '
        . 'onclick="this.value=null" '
        . 'onchange="cmsOnImageFileSelected(this,\'' . cms_h($previewId) . '\',\'' . cms_h($textId) . '\')">';
    echo '<div class="cms-upload-progress" hidden><div class="cms-upload-progress__track"><span class="cms-upload-progress__bar"></span></div><span class="cms-upload-progress__text">۰٪</span></div>';
    echo '<span class="cms-muted" data-file-label-for="' . cms_h($fileId) . '"></span>';
    echo '</div></div></div>';
}

function cms_handle_optional_upload(string $fieldName, string $existingPath, array $options = []): string
{
    $fileKey = $fieldName . '_file';
    $hasFile = isset($_FILES[$fileKey]) && is_array($_FILES[$fileKey]);
    $error = $hasFile ? (int) ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

    if (!$hasFile || $error === UPLOAD_ERR_NO_FILE) {
        $posted = trim((string) ($_POST[$fieldName] ?? $existingPath));
        if ($posted !== '' && (preg_match('/^[a-zA-Z]:[\\\\\/]/', $posted) || strpos($posted, '\\\\') === 0)) {
            throw new RuntimeException('مسیر فایل روی هارد سیستم کار نمی‌کند — از دکمه آپلود یا انتخاب از سرور استفاده کنید');
        }
        return $posted;
    }

    return cms_store_uploaded_image($_FILES[$fileKey], $options);
}

function cms_video_field(string $name, string $label, string $value, string $subdir = 'about/videos', string $helpNote = ''): void
{
    $textId = 'text-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    $fileId = 'file-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    $previewId = 'preview-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    $uploadMax = (string) ini_get('upload_max_filesize');
    $postMax = (string) ini_get('post_max_size');
    $src = $value !== '' ? cms_asset_url($value) : '';
    $pickerArgs = '{kind:\'video\',subdir:\'' . cms_h($subdir) . '\'}';

    echo '<div class="cms-field">';
    echo '<span class="cms-label">' . cms_h($label) . '</span>';
    if ($value !== '') {
        echo '<video id="' . cms_h($previewId) . '" class="cms-image-preview" src="' . cms_h($src) . '" controls preload="metadata" style="max-width:min(100%,420px);height:auto;background:#111"></video>';
    }
    echo '<input id="' . cms_h($textId) . '" class="cms-input" type="text" name="' . cms_h($name) . '" value="' . cms_h($value) . '" dir="ltr" placeholder="/uploads/' . cms_h($subdir) . '/...">';
    echo '<div class="cms-btn-row" style="margin-top:.4rem">';
    echo '<label class="cms-file-pick" for="' . cms_h($fileId) . '">آپلود ویدیو از رایانه</label>';
    echo '<button type="button" class="cms-btn cms-btn--secondary" onclick="cmsOpenMediaPicker(\'' . cms_h($textId) . '\',\'' . cms_h($previewId) . '\',' . $pickerArgs . ')">انتخاب از سرور</button>';
    echo '</div>';
    echo '<input id="' . cms_h($fileId) . '" class="cms-file-input" type="file" name="' . cms_h($name) . '_file" accept="video/mp4,video/webm" '
        . 'data-cms-upload="video" data-cms-text-id="' . cms_h($textId) . '" data-cms-preview-id="' . cms_h($previewId) . '" data-cms-upload-subdir="' . cms_h($subdir) . '" '
        . 'onclick="this.value=null" '
        . 'onchange="cmsOnVideoFileSelected(this,\'' . cms_h($previewId) . '\',\'' . cms_h($textId) . '\')">';
    echo '<div class="cms-upload-progress" hidden><div class="cms-upload-progress__track"><span class="cms-upload-progress__bar"></span></div><span class="cms-upload-progress__text">۰٪</span></div>';
    echo '<span class="cms-muted" data-file-label-for="' . cms_h($fileId) . '"></span>';
    if ($helpNote !== '') {
        echo '<p class="cms-muted" style="margin:.45rem 0 0">' . cms_h($helpNote) . '</p>';
    } else {
        echo '<p class="cms-muted" style="margin:.45rem 0 0">MP4 یا WebM، حداکثر ۸۰ مگابایت. فیلم را فشرده کنید؛ فایل خام ۴K نفرستید. حد فعلی PHP: upload_max_filesize=' . cms_h($uploadMax) . ' و post_max_size=' . cms_h($postMax) . ' — اگر آپلود رد شد این دو مقدار را در cPanel افزایش دهید.</p>';
    }
    echo '</div>';
}

function cms_handle_optional_video_upload(string $fieldName, string $existingPath, string $subdir = 'about/videos'): string
{
    $fileKey = $fieldName . '_file';
    $hasFile = isset($_FILES[$fileKey]) && is_array($_FILES[$fileKey]);
    $error = $hasFile ? (int) ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

    if (!$hasFile || $error === UPLOAD_ERR_NO_FILE) {
        $posted = trim((string) ($_POST[$fieldName] ?? $existingPath));
        if ($posted !== '' && (preg_match('/^[a-zA-Z]:[\\\\\/]/', $posted) || strpos($posted, '\\\\') === 0)) {
            throw new RuntimeException('مسیر فایل روی هارد سیستم کار نمی‌کند — از دکمه آپلود استفاده کنید');
        }
        return $posted;
    }

    return cms_store_uploaded_video($_FILES[$fileKey], $subdir);
}

