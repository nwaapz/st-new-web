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
    if (!str_starts_with($path, '/uploads/')) {
        throw new RuntimeException('مسیر تصویر نامعتبر است');
    }
    if (str_contains($path, '..')) {
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

function admin_bool_from_payload(mixed $value, bool $default = true): bool
{
    if ($value === null) {
        return $default;
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
