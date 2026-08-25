<?php
declare(strict_types=1);

const HOME_PATTERN_SETTING_KEY = 'home_pattern_config';

/** @return list<string> */
function home_pattern_preset_images(): array
{
    return [
        '/images/bg/floor1.png',
        '/images/bg/floor1-2.png',
        '/images/bg/floor.png',
        '/images/bg/e.png',
        '/images/bg/medal-floor.png',
        '/images/bg/startechShop.png',
        '/images/bg/third-section-pattern-baked.png',
    ];
}

function home_pattern_defaults(): array
{
    return [
        'enabled' => true,
        'image' => '/images/bg/third-section-pattern-baked.png',
        'tile_size' => 32,
        'columns' => 0,
        'gap' => 0,
        'rotation' => 13,
        'column_offset' => 2,
        'overlay_color' => '#d42121',
        'overlay_opacity' => 0,
        'highlight_enabled' => true,
        'highlight_color' => '#fdbe4c',
        'highlight_opacity' => 85,
        'highlight_duration' => 4.0,
    ];
}

function home_pattern_clamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function home_pattern_normalize_image(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return home_pattern_defaults()['image'];
    }
    if (
        preg_match('#^https?://#i', $path) === 1
        || str_starts_with($path, 'data:')
    ) {
        return $path;
    }
    return str_starts_with($path, '/') ? $path : '/' . $path;
}

function home_pattern_normalize_color(string $value, string $fallback): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }
    if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1) {
        return $value;
    }
    return $fallback;
}

function home_pattern_parse_config($raw): array
{
    $defaults = home_pattern_defaults();
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

    return [
        'enabled' => $bool($raw['enabled'] ?? null, $defaults['enabled']),
        'image' => home_pattern_normalize_image((string) ($raw['image'] ?? $defaults['image'])),
        'tile_size' => (int) home_pattern_clamp((float) ($raw['tile_size'] ?? $defaults['tile_size']), 24, 400),
        'columns' => (int) home_pattern_clamp((float) ($raw['columns'] ?? $defaults['columns']), 0, 24),
        'gap' => (int) home_pattern_clamp((float) ($raw['gap'] ?? $defaults['gap']), 0, 80),
        'rotation' => (int) home_pattern_clamp((float) ($raw['rotation'] ?? $defaults['rotation']), 0, 360),
        'column_offset' => (int) home_pattern_clamp((float) ($raw['column_offset'] ?? $defaults['column_offset']), 0, 50),
        'overlay_color' => home_pattern_normalize_color(
            (string) ($raw['overlay_color'] ?? $defaults['overlay_color']),
            $defaults['overlay_color']
        ),
        'overlay_opacity' => (int) home_pattern_clamp((float) ($raw['overlay_opacity'] ?? $defaults['overlay_opacity']), 0, 100),
        'highlight_enabled' => $bool($raw['highlight_enabled'] ?? null, $defaults['highlight_enabled']),
        'highlight_color' => home_pattern_normalize_color(
            (string) ($raw['highlight_color'] ?? $defaults['highlight_color']),
            $defaults['highlight_color']
        ),
        'highlight_opacity' => (int) home_pattern_clamp((float) ($raw['highlight_opacity'] ?? $defaults['highlight_opacity']), 0, 100),
        'highlight_duration' => home_pattern_clamp((float) ($raw['highlight_duration'] ?? $defaults['highlight_duration']), 1.5, 12),
    ];
}

function home_pattern_load(): array
{
    $raw = cms_setting_get(HOME_PATTERN_SETTING_KEY, '');
    if ($raw === '') {
        return home_pattern_defaults();
    }
    $decoded = json_decode($raw, true);
    return home_pattern_parse_config(is_array($decoded) ? $decoded : null);
}

function home_pattern_save(array $config): void
{
    $normalized = home_pattern_parse_config($config);
    cms_setting_set(
        HOME_PATTERN_SETTING_KEY,
        (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function home_pattern_seed_if_missing(): void
{
    if (cms_setting_get(HOME_PATTERN_SETTING_KEY, '') !== '') {
        return;
    }
    home_pattern_save(home_pattern_defaults());
}

function home_pattern_collect_from_post(string $existingImage): array
{
    $preset = trim((string) ($_POST['image_preset'] ?? ''));
    $image = $existingImage;
    if ($preset !== '' && in_array($preset, home_pattern_preset_images(), true)) {
        $image = $preset;
    } else {
        $image = cms_handle_optional_upload('image', $existingImage);
    }

    return home_pattern_parse_config([
        'enabled' => isset($_POST['enabled']),
        'image' => $image,
        'tile_size' => $_POST['tile_size'] ?? null,
        'columns' => $_POST['columns'] ?? null,
        'gap' => $_POST['gap'] ?? null,
        'rotation' => $_POST['rotation'] ?? null,
        'column_offset' => $_POST['column_offset'] ?? null,
        'overlay_color' => $_POST['overlay_color'] ?? null,
        'overlay_opacity' => $_POST['overlay_opacity'] ?? null,
        'highlight_enabled' => isset($_POST['highlight_enabled']),
        'highlight_color' => $_POST['highlight_color'] ?? null,
        'highlight_opacity' => $_POST['highlight_opacity'] ?? null,
        'highlight_duration' => $_POST['highlight_duration'] ?? null,
    ]);
}

/** @return array<string, mixed> */
function home_pattern_public_payload(): array
{
    return home_pattern_load();
}
