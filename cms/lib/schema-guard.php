<?php
declare(strict_types=1);

/**
 * One-shot guard for the *_ensure_schema() migration blocks.
 *
 * Those helpers are idempotent, but they are not free: a single request can
 * issue dozens of CREATE TABLE / SHOW COLUMNS / ALTER TABLE statements, and a
 * few of them (ENUM MODIFY, backfill UPDATEs, ADD KEY retries) rebuild or scan
 * whole tables. Running them on every API hit dominates response time.
 *
 * The guard writes a stamp whose signature is derived from the migration source
 * files, so editing a lib file makes its migrations run again exactly once per
 * server after a deploy. Stamps are per database, and a missing/unwritable
 * stamp directory simply falls back to the old always-migrate behaviour.
 */

/** Bump to force every guarded migration to run again. */
const CMS_SCHEMA_GUARD_EPOCH = 1;

function cms_schema_guard_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    if (function_exists('cms_config')) {
        $config = cms_config();
        if (!empty($config['schema_guard_disabled'])) {
            $enabled = false;
            return $enabled;
        }
    }

    $enabled = cms_schema_guard_dir() !== null;
    return $enabled;
}

function cms_schema_guard_dir(): ?string
{
    static $dir = null;
    static $resolved = false;
    if ($resolved) {
        return $dir;
    }
    $resolved = true;

    $base = sys_get_temp_dir();
    if ($base === '' || !is_dir($base)) {
        return null;
    }

    $candidate = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'startech-schema';
    if (!is_dir($candidate) && !@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
        return null;
    }
    if (!is_writable($candidate)) {
        return null;
    }

    $dir = $candidate;
    return $dir;
}

function cms_schema_guard_database_id(): string
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $host = '';
    $name = '';
    if (function_exists('cms_config')) {
        $config = cms_config();
        $host = (string) ($config['db_host'] ?? '');
        $name = (string) ($config['db_name'] ?? '');
    }

    $id = substr(hash('sha256', $host . '/' . $name), 0, 16);
    return $id;
}

/**
 * @param list<string> $sourceFiles
 */
function cms_schema_guard_signature(array $sourceFiles): string
{
    $parts = [(string) CMS_SCHEMA_GUARD_EPOCH];
    foreach ($sourceFiles as $file) {
        $mtime = @filemtime($file);
        $size = @filesize($file);
        $parts[] = basename($file) . ':' . ($mtime === false ? '0' : (string) $mtime)
            . ':' . ($size === false ? '0' : (string) $size);
    }

    return hash('sha256', implode('|', $parts));
}

function cms_schema_guard_stamp_path(string $key): ?string
{
    $dir = cms_schema_guard_dir();
    if ($dir === null) {
        return null;
    }
    $safeKey = preg_replace('/[^a-z0-9_-]+/i', '-', $key) ?? 'schema';

    return $dir . DIRECTORY_SEPARATOR . $safeKey . '-' . cms_schema_guard_database_id() . '.stamp';
}

/**
 * True when the guarded migrations for $key already ran for this code revision.
 *
 * @param list<string> $sourceFiles
 */
function cms_schema_guard_done(string $key, array $sourceFiles): bool
{
    if (!cms_schema_guard_enabled()) {
        return false;
    }
    $path = cms_schema_guard_stamp_path($key);
    if ($path === null || !is_file($path)) {
        return false;
    }

    $stored = @file_get_contents($path);
    if ($stored === false) {
        return false;
    }

    return trim($stored) === cms_schema_guard_signature($sourceFiles);
}

/**
 * Records that the guarded migrations for $key completed.
 *
 * @param list<string> $sourceFiles
 */
function cms_schema_guard_mark(string $key, array $sourceFiles): void
{
    if (!cms_schema_guard_enabled()) {
        return;
    }
    $path = cms_schema_guard_stamp_path($key);
    if ($path === null) {
        return;
    }

    $tmp = $path . '.' . uniqid('', true) . '.tmp';
    if (@file_put_contents($tmp, cms_schema_guard_signature($sourceFiles)) === false) {
        return;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
    }
}

/** Drops every stamp so the next request re-runs all migrations. */
function cms_schema_guard_flush(): int
{
    $dir = cms_schema_guard_dir();
    if ($dir === null) {
        return 0;
    }
    $removed = 0;
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.stamp') ?: [] as $file) {
        if (@unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}
