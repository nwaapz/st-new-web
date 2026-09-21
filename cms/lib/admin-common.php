<?php
declare(strict_types=1);

function admin_normalize_upload_path(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }
    if (preg_match('#(/uploads/[^?#]+)#', $path, $matches)) {
        $path = $matches[1];
    }
    if (strpos($path, '/uploads/') !== 0) {
        throw new RuntimeException('مسیر تصویر نامعتبر است');
    }
    if (strpos($path, '..') !== false) {
        throw new RuntimeException('مسیر تصویر نامعتبر است');
    }
    return $path;
}

function admin_slug_from_payload(string $name, string $slug): string
{
    $slug = trim($slug);
    if ($slug === '') {
        $slug = cms_slugify($name);
    }
    return $slug;
}

function admin_bool_from_payload($value, bool $default = true): bool
{
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === 'false' || $normalized === '0') {
            return false;
        }
        if ($normalized === 'true' || $normalized === '1') {
            return true;
        }
    }
    return (bool) $value;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function admin_base_entity_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'image' => (string) ($row['image'] ?? ''),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'published' => (int) ($row['published'] ?? 0) === 1,
    ];
}
