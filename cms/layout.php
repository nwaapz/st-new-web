<?php
declare(strict_types=1);

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
    echo '<link rel="stylesheet" href="assets/cms.css?v=15">';
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
    ];

    $nestedGroups = [
        'customerclub.php' => [
            'customerclub.php' => 'صفحه ورود',
            'mechanics.php' => 'مکانیک‌ها',
            'mechanic-broadcasts.php' => 'پیام گروهی',
        ],
        'hero.php' => [
            'hero.php' => 'هیرو',
            'rewards.php' => 'جوایز',
            'home-backgrounds.php' => 'پس‌زمینه و متن',
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
    echo '<div class="cms-media-modal__panel" role="dialog" aria-modal="true" aria-label="تصاویر سرور">';
    echo '<div class="cms-media-modal__head"><strong>انتخاب از تصاویر سرور</strong>';
    echo '<button type="button" class="cms-btn cms-btn--ghost" onclick="cmsCloseMediaPicker()">بستن</button></div>';
    echo '<p class="cms-muted" id="cms-media-status">در حال بارگذاری…</p>';
    echo '<div class="cms-media-grid" id="cms-media-grid"></div>';
    echo '</div></div>';
    echo '<script>';
    echo 'function cmsOnImageFileSelected(input,previewId,textId){';
    echo 'if(!input.files||!input.files[0])return;';
    echo 'var f=input.files[0];';
    echo 'var label=document.querySelector("[data-file-label-for=\\""+input.id+"\\"]");';
    echo 'if(label)label.textContent="انتخاب شد: "+f.name+" — دکمه ذخیره را بزنید";';
    echo 'var img=document.getElementById(previewId);';
    echo 'var empty=document.getElementById(previewId+"-empty");';
    echo 'if(img){img.style.display="block";img.classList.remove("cms-image-preview--empty");';
    echo 'if(img._cmsObjectUrl)URL.revokeObjectURL(img._cmsObjectUrl);';
    echo 'img._cmsObjectUrl=URL.createObjectURL(f);img.src=img._cmsObjectUrl;}';
    echo 'if(empty)empty.style.display="none";';
    echo 'var text=document.getElementById(textId);';
    echo 'if(text)text.dataset.pendingUpload="1";';
    echo '}';
    echo 'var cmsMediaTarget={textId:"",previewId:""};';
    echo 'function cmsOpenMediaPicker(textId,previewId){';
    echo 'cmsMediaTarget={textId:textId,previewId:previewId};';
    echo 'var m=document.getElementById("cms-media-modal");';
    echo 'var g=document.getElementById("cms-media-grid");';
    echo 'var s=document.getElementById("cms-media-status");';
    echo 'm.hidden=false;g.innerHTML="";s.textContent="در حال بارگذاری…";';
    echo 'fetch("media-list.php",{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(data){';
    echo 'var items=data.items||[];';
    echo 'if(!items.length){s.textContent="هنوز تصویری در uploads نیست.";return;}';
    echo 's.textContent=items.length+" تصویر — یکی را انتخاب کنید";';
    echo 'items.forEach(function(it){';
    echo 'var b=document.createElement("button");b.type="button";b.className="cms-media-item";';
    echo 'b.title=it.name;b.innerHTML="<img src=\\""+it.url+"\\" alt=\\"\\"><span>"+it.name+"</span>";';
    echo 'b.onclick=function(){cmsPickMedia(it.path,it.url)};';
    echo 'g.appendChild(b);';
    echo '});';
    echo '}).catch(function(){s.textContent="بارگذاری لیست تصاویر ناموفق بود";});';
    echo '}';
    echo 'function cmsPickMedia(path,url){';
    echo 'var text=document.getElementById(cmsMediaTarget.textId);';
    echo 'var img=document.getElementById(cmsMediaTarget.previewId);';
    echo 'var empty=document.getElementById(cmsMediaTarget.previewId+"-empty");';
    echo 'if(text){text.value=path;delete text.dataset.pendingUpload;}';
    echo 'if(img){if(img._cmsObjectUrl){URL.revokeObjectURL(img._cmsObjectUrl);img._cmsObjectUrl=null;}';
    echo 'img.style.display="block";img.classList.remove("cms-image-preview--empty");img.src=url+"?v="+encodeURIComponent(path);}';
    echo 'if(empty)empty.style.display="none";';
    echo 'cmsCloseMediaPicker();';
    echo '}';
    echo 'function cmsCloseMediaPicker(){document.getElementById("cms-media-modal").hidden=true;}';
    echo 'document.getElementById("cms-media-modal").addEventListener("click",function(e){if(e.target===this)cmsCloseMediaPicker();});';
    echo '</script>';
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
    echo '<button type="button" class="cms-btn cms-btn--secondary" onclick="cmsOpenMediaPicker(\'' . cms_h($textId) . '\',\'' . cms_h($previewId) . '\')">انتخاب از سرور</button>';
    echo '</div>';
    echo '<input id="' . cms_h($fileId) . '" class="cms-file-input" type="file" name="' . cms_h($name) . '_file" accept="image/jpeg,image/png,image/webp,image/gif" '
        . 'onclick="this.value=null" '
        . 'onchange="cmsOnImageFileSelected(this,\'' . cms_h($previewId) . '\',\'' . cms_h($textId) . '\')">';
    echo '<span class="cms-muted" data-file-label-for="' . cms_h($fileId) . '"></span>';
    echo '</div></div></div>';
}

function cms_unique_upload_name(string $uploadsDir, string $originalName, string $ext): string
{
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    // Keep letters (incl. Persian), digits, dot, underscore, dash
    $safe = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $base);
    $safe = trim((string) $safe, '-._');
    $safe = preg_replace('/-+/', '-', $safe);
    if ($safe === '' || $safe === null) {
        $safe = 'image';
    }
    if (function_exists('mb_strlen') && mb_strlen($safe) > 80) {
        $safe = mb_substr($safe, 0, 80);
    } elseif (strlen($safe) > 80) {
        $safe = substr($safe, 0, 80);
    }
    $safe = rtrim($safe, '-._');

    // Prefer original name; only add unique suffix if that file already exists
    $name = $safe . $ext;
    if (!file_exists($uploadsDir . DIRECTORY_SEPARATOR . $name)) {
        return $name;
    }
    // e.g. brake-pad-a1b2c3.jpg  (real name kept as the main part)
    return $safe . '-' . bin2hex(random_bytes(3)) . $ext;
}

function cms_handle_optional_upload(string $fieldName, string $existingPath): string
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

    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'حجم فایل از حد مجاز سرور بیشتر است',
        UPLOAD_ERR_FORM_SIZE => 'حجم فایل خیلی بزرگ است',
        UPLOAD_ERR_PARTIAL => 'آپلود ناقص بود — دوباره تلاش کنید',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور موجود نیست',
        UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل روی دیسک ممکن نیست',
        UPLOAD_ERR_EXTENSION => 'افزونه PHP آپلود را مسدود کرد',
    ];
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException($uploadErrors[$error] ?? ('آپلود ناموفق بود (کد ' . $error . ')'));
    }

    $file = $_FILES[$fileKey];
    if (!is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('فایل آپلود معتبر نیست');
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($file['tmp_name']);
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $extGuess = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $extMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $mime = $extMap[$extGuess] ?? $mime;
    }

    $map = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
        'image/gif' => '.gif',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('فقط JPEG/PNG/WebP/GIF مجاز است (نوع تشخیص‌داده‌شده: ' . ($mime !== '' ? $mime : 'نامشخص') . ')');
    }
    if ((int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('حداکثر حجم تصویر ۵ مگابایت است');
    }

    $uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
        throw new RuntimeException('ساخت پوشه uploads ممکن نیست — دسترسی نوشتن را بررسی کنید');
    }
    if (!is_writable($uploadsDir)) {
        throw new RuntimeException('پوشه uploads قابل نوشتن نیست');
    }

    $name = cms_unique_upload_name($uploadsDir, (string) $file['name'], $map[$mime]);
    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره فایل ناموفق بود');
    }

    return '/uploads/' . $name;
}

function cms_video_field(string $name, string $label, string $value): void
{
    $textId = 'text-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    $fileId = 'file-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    $previewId = 'preview-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    $uploadMax = (string) ini_get('upload_max_filesize');
    $postMax = (string) ini_get('post_max_size');
    $src = $value !== '' ? cms_asset_url($value) : '';

    echo '<div class="cms-field">';
    echo '<span class="cms-label">' . cms_h($label) . '</span>';
    if ($value !== '') {
        echo '<video id="' . cms_h($previewId) . '" class="cms-image-preview" src="' . cms_h($src) . '" controls preload="metadata" style="max-width:min(100%,420px);height:auto;background:#111"></video>';
    }
    echo '<input id="' . cms_h($textId) . '" class="cms-input" type="text" name="' . cms_h($name) . '" value="' . cms_h($value) . '" dir="ltr" placeholder="/uploads/about/videos/...">';
    echo '<div class="cms-btn-row" style="margin-top:.4rem">';
    echo '<label class="cms-file-pick" for="' . cms_h($fileId) . '">آپلود ویدیو از رایانه</label>';
    echo '</div>';
    echo '<input id="' . cms_h($fileId) . '" class="cms-file-input" type="file" name="' . cms_h($name) . '_file" accept="video/mp4,video/webm">';
    echo '<p class="cms-muted" style="margin:.45rem 0 0">MP4 یا WebM، حداکثر ۸۰ مگابایت. فیلم غرفه را فشرده کنید؛ فایل خام ۴K نفرستید. حد فعلی PHP: upload_max_filesize=' . cms_h($uploadMax) . ' و post_max_size=' . cms_h($postMax) . ' — اگر آپلود رد شد این دو مقدار را در cPanel افزایش دهید.</p>';
    echo '</div>';
}

function cms_handle_optional_video_upload(string $fieldName, string $existingPath): string
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

    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'حجم ویدیو از حد مجاز سرور (upload_max_filesize) بیشتر است — در cPanel آن را افزایش دهید',
        UPLOAD_ERR_FORM_SIZE => 'حجم ویدیو خیلی بزرگ است',
        UPLOAD_ERR_PARTIAL => 'آپلود ناقص بود — دوباره تلاش کنید',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور موجود نیست',
        UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل روی دیسک ممکن نیست',
        UPLOAD_ERR_EXTENSION => 'افزونه PHP آپلود را مسدود کرد',
    ];
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException($uploadErrors[$error] ?? ('آپلود ویدیو ناموفق بود (کد ' . $error . ')'));
    }

    $file = $_FILES[$fileKey];
    if (!is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('فایل ویدیو معتبر نیست');
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($file['tmp_name']);
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $extGuess = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $extMap = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
        $mime = $extMap[$extGuess] ?? $mime;
    }

    $map = [
        'video/mp4' => '.mp4',
        'video/webm' => '.webm',
    ];
    if (!isset($map[$mime])) {
        throw new RuntimeException('فقط MP4 یا WebM مجاز است (نوع تشخیص‌داده‌شده: ' . ($mime !== '' ? $mime : 'نامشخص') . ')');
    }
    if ((int) $file['size'] > 80 * 1024 * 1024) {
        throw new RuntimeException('حداکثر حجم ویدیو ۸۰ مگابایت است — فیلم را فشرده کنید');
    }

    $uploadsRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $uploadsDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'about' . DIRECTORY_SEPARATOR . 'videos';
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
        throw new RuntimeException('ساخت پوشه uploads/about/videos ممکن نیست');
    }
    if (!is_writable($uploadsDir)) {
        throw new RuntimeException('پوشه uploads/about/videos قابل نوشتن نیست');
    }

    $name = cms_unique_upload_name($uploadsDir, (string) $file['name'], $map[$mime]);
    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('ذخیره ویدیو ناموفق بود');
    }

    return '/uploads/about/videos/' . $name;
}

