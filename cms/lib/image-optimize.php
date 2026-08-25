<?php
declare(strict_types=1);

/** Longest edge (px) for images stored on the server. */
function cms_image_optimize_max_edge(): int
{
    return 2048;
}

function cms_image_optimize_jpeg_quality(): int
{
    return 82;
}

function cms_image_gd_available(): bool
{
    return extension_loaded('gd')
        && function_exists('imagecreatetruecolor')
        && function_exists('imagecopyresampled');
}

function cms_image_mime_extension(string $mime): ?string
{
    $map = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/gif' => '.gif',
    ];

    return $map[$mime] ?? null;
}

function cms_image_is_animated_gif(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $chunk = fread($handle, 262144);
    fclose($handle);
    if ($chunk === false || $chunk === '') {
        return false;
    }

    return preg_match_all('/\x00\x21\xF9\x04/', $chunk) > 1;
}

/**
 * @return resource|\GdImage|null
 */
function cms_image_load_gd(string $path, string $mime)
{
    switch ($mime) {
        case 'image/jpeg':
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null;
        case 'image/png':
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null;
        case 'image/gif':
            return function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : null;
        case 'image/webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        default:
            return null;
    }
}

/** @param resource|\GdImage $img */
function cms_image_has_visible_alpha($img, int $width, int $height): bool
{
    if (!function_exists('imagecolorat')) {
        return true;
    }

    $stepX = max(1, (int) floor($width / 48));
    $stepY = max(1, (int) floor($height / 48));
    for ($y = 0; $y < $height; $y += $stepY) {
        for ($x = 0; $x < $width; $x += $stepX) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            if ($alpha > 0) {
                return true;
            }
        }
    }

    return false;
}

/** @param resource|\GdImage $dst */
function cms_image_fill_canvas($dst, int $width, int $height, bool $preserveAlpha): void
{
    if ($preserveAlpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($dst, 0, 0, $width, $height, $transparent);
        }
        return;
    }

    $white = imagecolorallocate($dst, 255, 255, 255);
    if ($white !== false) {
        imagefilledrectangle($dst, 0, 0, $width, $height, $white);
    }
}

function cms_detect_image_mime(string $path): string
{
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    return $map[$ext] ?? '';
}

/**
 * @param resource|\GdImage $img
 * @return array{0:int,1:int,2:int,3:int}|null minX, minY, maxX, maxY
 */
function cms_image_find_alpha_bbox($img, int $width, int $height): ?array
{
    $minX = $width;
    $minY = $height;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            if ($alpha < 110) {
                if ($x < $minX) {
                    $minX = $x;
                }
                if ($y < $minY) {
                    $minY = $y;
                }
                if ($x > $maxX) {
                    $maxX = $x;
                }
                if ($y > $maxY) {
                    $maxY = $y;
                }
            }
        }
    }

    if ($maxX < 0) {
        return null;
    }

    return [$minX, $minY, $maxX, $maxY];
}

/** @param resource|\GdImage $img */
function cms_image_save_to_path($img, string $path, string $mime): bool
{
    switch ($mime) {
        case 'image/jpeg':
            imageinterlace($img, true);
            return imagejpeg($img, $path, cms_image_optimize_jpeg_quality());
        case 'image/png':
            imagealphablending($img, false);
            imagesavealpha($img, true);
            return imagepng($img, $path, 8);
        case 'image/gif':
            return imagegif($img, $path);
        default:
            return false;
    }
}

/**
 * Trim transparent borders and center the product on a square PNG canvas.
 * Opaque JPEG uploads are left unchanged.
 */
function cms_auto_frame_product_image(string $absolutePath, string $mime): string
{
    if (!cms_image_gd_available() || !is_file($absolutePath)) {
        return $absolutePath;
    }

    if ($mime === 'image/jpeg' || ($mime === 'image/gif' && cms_image_is_animated_gif($absolutePath))) {
        return $absolutePath;
    }

    if (!in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
        return $absolutePath;
    }

    $src = cms_image_load_gd($absolutePath, $mime);
    if ($src === null || $src === false) {
        return $absolutePath;
    }

    $width = imagesx($src);
    $height = imagesy($src);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($src);

        return $absolutePath;
    }

    if (!cms_image_has_visible_alpha($src, $width, $height)) {
        imagedestroy($src);

        return $absolutePath;
    }

    $bbox = cms_image_find_alpha_bbox($src, $width, $height);
    if ($bbox === null) {
        imagedestroy($src);

        return $absolutePath;
    }

    [$minX, $minY, $maxX, $maxY] = $bbox;
    if ($minX <= 2 && $minY <= 2 && $maxX >= $width - 3 && $maxY >= $height - 3) {
        imagedestroy($src);

        return $absolutePath;
    }

    $cropW = $maxX - $minX + 1;
    $cropH = $maxY - $minY + 1;
    $pad = (int) max(12, min(48, round(max($cropW, $cropH) * 0.04)));
    $innerW = $cropW + ($pad * 2);
    $innerH = $cropH + ($pad * 2);
    $square = max($innerW, $innerH);

    $dst = imagecreatetruecolor($square, $square);
    if ($dst === false) {
        imagedestroy($src);

        return $absolutePath;
    }

    cms_image_fill_canvas($dst, $square, $square, true);
    imagealphablending($dst, true);

    $offsetX = (int) floor(($square - $cropW) / 2);
    $offsetY = (int) floor(($square - $cropH) / 2);
    imagecopy($dst, $src, $offsetX, $offsetY, $minX, $minY, $cropW, $cropH);
    imagedestroy($src);

    $dir = dirname($absolutePath);
    $base = pathinfo($absolutePath, PATHINFO_FILENAME);
    $finalPath = $dir . DIRECTORY_SEPARATOR . $base . '.png';
    if (is_file($finalPath) && realpath($finalPath) !== realpath($absolutePath)) {
        $finalPath = $dir . DIRECTORY_SEPARATOR . $base . '-' . bin2hex(random_bytes(3)) . '.png';
    }

    $tmpPath = $finalPath . '.frame.tmp';
    if (!cms_image_save_to_path($dst, $tmpPath, 'image/png')) {
        imagedestroy($dst);
        @unlink($tmpPath);

        return $absolutePath;
    }
    imagedestroy($dst);

    if (is_file($absolutePath) && realpath($absolutePath) !== realpath($finalPath)) {
        @unlink($absolutePath);
    }

    if (!rename($tmpPath, $finalPath)) {
        if (!copy($tmpPath, $finalPath)) {
            @unlink($tmpPath);

            return $absolutePath;
        }
        @unlink($tmpPath);
    }

    return $finalPath;
}

/**
 * Resize and compress an uploaded image. Output stays JPEG/PNG/GIF for universal browser support.
 * WebP uploads are converted to JPEG or PNG.
 */
function cms_optimize_stored_image(string $absolutePath, string $mime): string
{
    if (!cms_image_gd_available() || !is_file($absolutePath)) {
        return $absolutePath;
    }

    if ($mime === 'image/gif' && cms_image_is_animated_gif($absolutePath)) {
        return $absolutePath;
    }

    $src = cms_image_load_gd($absolutePath, $mime);
    if ($src === null || $src === false) {
        return $absolutePath;
    }

    $width = imagesx($src);
    $height = imagesy($src);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($src);

        return $absolutePath;
    }

    $maxEdge = cms_image_optimize_max_edge();
    $targetW = $width;
    $targetH = $height;
    if ($width > $maxEdge || $height > $maxEdge) {
        if ($width >= $height) {
            $targetW = $maxEdge;
            $targetH = (int) max(1, round($height * ($maxEdge / $width)));
        } else {
            $targetH = $maxEdge;
            $targetW = (int) max(1, round($width * ($maxEdge / $height)));
        }
    }

    $needsResize = $targetW !== $width || $targetH !== $height;

    $hasAlpha = in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)
        && cms_image_has_visible_alpha($src, $width, $height);

    if ($mime === 'image/gif') {
        $outputMime = 'image/gif';
    } elseif ($hasAlpha) {
        $outputMime = 'image/png';
    } else {
        $outputMime = 'image/jpeg';
    }

    $ext = cms_image_mime_extension($outputMime);
    if ($ext === null) {
        imagedestroy($src);

        return $absolutePath;
    }

    $dst = imagecreatetruecolor($targetW, $targetH);
    if ($dst === false) {
        imagedestroy($src);

        return $absolutePath;
    }

    cms_image_fill_canvas($dst, $targetW, $targetH, $outputMime !== 'image/jpeg');
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
    imagedestroy($src);

    $dir = dirname($absolutePath);
    $base = pathinfo($absolutePath, PATHINFO_FILENAME);
    $currentExt = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $newExt = ltrim($ext, '.');
    $formatChanged = $currentExt !== $newExt;
    $finalPath = $formatChanged
        ? $dir . DIRECTORY_SEPARATOR . $base . $ext
        : $absolutePath;

    if ($formatChanged && is_file($finalPath) && realpath($finalPath) !== realpath($absolutePath)) {
        $finalPath = $dir . DIRECTORY_SEPARATOR . $base . '-' . bin2hex(random_bytes(3)) . $ext;
    }

    $tmpPath = $finalPath . '.opt.tmp';
    $saved = false;
    switch ($outputMime) {
        case 'image/jpeg':
            imageinterlace($dst, true);
            $saved = imagejpeg($dst, $tmpPath, cms_image_optimize_jpeg_quality());
            break;
        case 'image/png':
            $saved = imagepng($dst, $tmpPath, 8);
            break;
        case 'image/gif':
            $saved = imagegif($dst, $tmpPath);
            break;
    }
    imagedestroy($dst);

    if (!$saved || !is_file($tmpPath)) {
        @unlink($tmpPath);

        return $absolutePath;
    }

    $origSize = (int) filesize($absolutePath);
    $newSize = (int) filesize($tmpPath);
    $shouldUse = $formatChanged || $needsResize || $newSize < $origSize;

    if (!$shouldUse) {
        @unlink($tmpPath);

        return $absolutePath;
    }

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }

    if (!rename($tmpPath, $finalPath)) {
        if (!copy($tmpPath, $finalPath)) {
            @unlink($tmpPath);

            return $absolutePath;
        }
        @unlink($tmpPath);
    }

    return $finalPath;
}
