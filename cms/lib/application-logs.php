<?php
declare(strict_types=1);

require_once __DIR__ . '/schema-guard.php';
require_once __DIR__ . '/jalali.php';

const APP_LOG_SUMMARY_MAX = 512;
const APP_LOG_METADATA_MAX = 8192;
const APP_LOG_EXCEPTION_MAX = 8192;
const APP_LOG_BATCH_MAX = 50;
const APP_LOG_RATE_MAX = 20;
const APP_LOG_RATE_WINDOW = 60;
const APP_LOG_THROTTLE_SECONDS = 60;
const APP_LOG_CLEANUP_BATCH = 500;
const APP_LOG_DEFAULT_RETENTION_DAYS = 90;

/** @return list<string> */
function application_logs_severities(): array
{
    return ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL'];
}

/** @return array<string, int> */
function application_logs_severity_ranks(): array
{
    return [
        'DEBUG' => 10,
        'INFO' => 20,
        'NOTICE' => 30,
        'WARNING' => 40,
        'ERROR' => 50,
        'CRITICAL' => 60,
    ];
}

/** @return array<string, string> */
function application_logs_severity_labels(): array
{
    return [
        'DEBUG' => 'اشکال‌زدایی',
        'INFO' => 'اطلاعات',
        'NOTICE' => 'اعلان',
        'WARNING' => 'هشدار',
        'ERROR' => 'خطا',
        'CRITICAL' => 'بحرانی',
    ];
}

/** @return array<string, string> */
function application_logs_source_labels(): array
{
    return [
        'sales_app' => 'اپ فروش',
        'admin_app' => 'اپ ادمین',
        'backend' => 'بک‌اند',
        'web_crm' => 'وب CRM',
        'sms_processor' => 'پردازش پیامک',
        'sync_worker' => 'همگام‌سازی',
        'other' => 'سایر',
    ];
}

/** @return array<string, string> */
function application_logs_category_labels(): array
{
    return [
        'authentication' => 'احراز هویت',
        'order' => 'سفارش',
        'synchronization' => 'همگام‌سازی',
        'sms' => 'پیامک',
        'push' => 'پوش',
        'chat' => 'گفتگو',
        'payment' => 'پرداخت',
        'cheque' => 'چک',
        'database' => 'پایگاه داده',
        'api' => 'API',
        'navigation' => 'ناوبری',
        'system' => 'سیستم',
    ];
}

function application_logs_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (cms_schema_guard_done('application-logs', [__FILE__])) {
        $ready = true;
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS application_logs (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          event_id CHAR(36) NOT NULL,
          source_app VARCHAR(32) NOT NULL,
          environment VARCHAR(16) NOT NULL DEFAULT 'production',
          severity ENUM('DEBUG','INFO','NOTICE','WARNING','ERROR','CRITICAL') NOT NULL,
          category VARCHAR(32) NOT NULL DEFAULT 'system',
          event_code VARCHAR(64) NOT NULL,
          summary VARCHAR(512) NOT NULL,
          metadata_json TEXT NULL,
          exception_class VARCHAR(191) NULL,
          exception_detail TEXT NULL,
          user_type VARCHAR(16) NULL,
          user_id INT UNSIGNED NULL,
          user_label VARCHAR(64) NULL,
          related_order_id INT UNSIGNED NULL,
          related_customer_id INT UNSIGNED NULL,
          correlation_id VARCHAR(64) NULL,
          client_occurred_at DATETIME NULL,
          client_timezone VARCHAR(64) NULL,
          occurred_at DATETIME NOT NULL,
          ingested_at DATETIME NOT NULL,
          app_version VARCHAR(32) NULL,
          os_version VARCHAR(64) NULL,
          device_id_hash VARCHAR(64) NULL,
          was_offline TINYINT(1) NOT NULL DEFAULT 0,
          is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
          fingerprint CHAR(40) NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_app_log_event (source_app, event_id),
          KEY idx_app_logs_ingested (ingested_at),
          KEY idx_app_logs_occurred (occurred_at),
          KEY idx_app_logs_severity (severity, occurred_at),
          KEY idx_app_logs_source (source_app, occurred_at),
          KEY idx_app_logs_category (category, occurred_at),
          KEY idx_app_logs_code (event_code),
          KEY idx_app_logs_user (user_id),
          KEY idx_app_logs_order (related_order_id),
          KEY idx_app_logs_corr (correlation_id),
          KEY idx_app_logs_fp (fingerprint, ingested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    cms_schema_guard_mark('application-logs', [__FILE__]);
    $ready = true;
}

function cms_log_pdo(): ?PDO
{
    static $pdo = null;
    static $failed = false;
    if ($failed) {
        return null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    try {
        $c = cms_config();
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $c['db_host'],
            $c['db_name'],
            $c['db_charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        $failed = true;
        return null;
    }
}

function application_logs_utc_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function application_logs_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function application_logs_environment(): string
{
    $cfg = function_exists('cms_config') ? cms_config() : [];
    $env = strtolower(trim((string) ($cfg['app_log_environment'] ?? '')));
    if (in_array($env, ['production', 'staging', 'development'], true)) {
        return $env;
    }

    return 'production';
}

function application_logs_debug_enabled(): bool
{
    $cfg = function_exists('cms_config') ? cms_config() : [];

    return !empty($cfg['app_log_debug']);
}

function application_logs_min_rank(): int
{
    return application_logs_debug_enabled() ? 10 : 20;
}

function application_logs_retention_days(): int
{
    $cfg = function_exists('cms_config') ? cms_config() : [];
    $days = (int) ($cfg['app_log_retention_days'] ?? APP_LOG_DEFAULT_RETENTION_DAYS);
    if ($days < 7) {
        return 7;
    }
    if ($days > 730) {
        return 730;
    }

    return $days;
}

function application_logs_can_view(?string $username): bool
{
    if ($username === null || trim($username) === '') {
        return false;
    }
    $cfg = function_exists('cms_config') ? cms_config() : [];
    $allow = $cfg['app_logs_viewers'] ?? [];
    if (!is_array($allow) || $allow === []) {
        return true;
    }
    $wanted = trim($username);
    foreach ($allow as $name) {
        if (trim((string) $name) === $wanted) {
            return true;
        }
    }

    return false;
}

function application_logs_normalize_event_id(string $id): string
{
    $id = trim($id);
    if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id)) {
        return strtolower($id);
    }
    if ($id === '') {
        return application_logs_uuid();
    }
    $hash = md5($id);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        substr($hash, 12, 4),
        substr($hash, 16, 4),
        substr($hash, 20, 12)
    );
}

function application_logs_clamp_severity(?string $raw, bool $fromClient = false): string
{
    $value = strtoupper(trim((string) $raw));
    $allowed = application_logs_severities();
    if (!in_array($value, $allowed, true)) {
        return $fromClient ? 'WARNING' : 'INFO';
    }
    if ($fromClient && $value === 'CRITICAL') {
        return 'ERROR';
    }

    return $value;
}

function application_logs_clamp_source(?string $raw, ?string $fallback = 'backend'): string
{
    $value = strtolower(trim((string) $raw));
    $allowed = array_keys(application_logs_source_labels());
    if (in_array($value, $allowed, true)) {
        return $value;
    }

    return $fallback ?? 'other';
}

function application_logs_clamp_category(?string $raw): string
{
    $value = strtolower(trim((string) $raw));
    $allowed = array_keys(application_logs_category_labels());
    if (in_array($value, $allowed, true)) {
        return $value;
    }

    return 'system';
}

function application_logs_truncate(string $value, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }

    return substr($value, 0, $max);
}

/**
 * @param mixed $value
 * @return mixed
 */
function application_logs_redact($value, int $depth = 0)
{
    if ($depth > 5) {
        return '[truncated]';
    }
    if (is_array($value)) {
        $out = [];
        $i = 0;
        foreach ($value as $key => $item) {
            if ($i++ > 40) {
                $out['_truncated'] = true;
                break;
            }
            $lk = strtolower((string) $key);
            if (preg_match('/pass|token|secret|cookie|authorization|otp|cvv|pan|session|credential/i', $lk)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            $out[$key] = application_logs_redact($item, $depth + 1);
        }

        return $out;
    }
    if (!is_string($value)) {
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return application_logs_truncate((string) $value, 500);
    }

    $text = $value;
    $text = preg_replace('/(?i)(password|passwd|pwd|token|secret|authorization|cookie|otp)\s*[:=]\s*\S+/', '$1=[REDACTED]', $text) ?? $text;
    $text = preg_replace('/(?<!\\d)(0?9\\d{2})\\d{3}(\\d{4})(?!\\d)/', '$1***$2', $text) ?? $text;
    $text = preg_replace('/(?<!\\d)(\\d{4})[ -]?\\d{4}[ -]?\\d{4}[ -]?(\\d{4})(?!\\d)/', '$1********$2', $text) ?? $text;

    return $text;
}

function application_logs_sanitize_text(?string $value, int $max): string
{
    $text = application_logs_redact((string) ($value ?? ''));
    if (!is_string($text)) {
        $text = (string) $text;
    }
    $text = str_replace(["\0", "\r"], '', $text);
    $text = application_logs_truncate(trim($text), $max);

    return $text;
}

/**
 * @param mixed $metadata
 */
function application_logs_encode_metadata($metadata): ?string
{
    if ($metadata === null || $metadata === '' || $metadata === []) {
        return null;
    }
    $clean = application_logs_redact($metadata);
    $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return null;
    }
    if (strlen($encoded) > APP_LOG_METADATA_MAX) {
        $encoded = application_logs_truncate($encoded, APP_LOG_METADATA_MAX - 20) . '…[truncated]';
    }

    return $encoded;
}

function application_logs_parse_client_time(?string $raw, ?string $timezone): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    try {
        $tzName = trim((string) $timezone);
        $tz = $tzName !== '' ? new DateTimeZone($tzName) : new DateTimeZone('UTC');
        $dt = new DateTimeImmutable(str_replace('T', ' ', $raw), $tz);

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param array<string, mixed> $event
 */
function cms_app_log(array $event): void
{
    static $writing = false;
    if ($writing) {
        return;
    }
    $writing = true;
    try {
        $severity = application_logs_clamp_severity(isset($event['severity']) ? (string) $event['severity'] : 'INFO', false);
        $ranks = application_logs_severity_ranks();
        if (($ranks[$severity] ?? 20) < application_logs_min_rank()) {
            return;
        }

        $pdo = cms_log_pdo();
        if ($pdo === null) {
            return;
        }
        application_logs_ensure_schema($pdo);

        $source = application_logs_clamp_source(isset($event['source_app']) ? (string) $event['source_app'] : 'backend');
        $eventId = application_logs_normalize_event_id((string) ($event['event_id'] ?? ''));
        $summary = application_logs_sanitize_text((string) ($event['summary'] ?? ''), APP_LOG_SUMMARY_MAX);
        if ($summary === '') {
            $summary = (string) ($event['event_code'] ?? 'event');
        }
        $eventCode = application_logs_sanitize_text((string) ($event['event_code'] ?? 'event'), 64);
        if ($eventCode === '') {
            $eventCode = 'event';
        }

        $fingerprint = sha1($source . '|' . $eventCode . '|' . application_logs_truncate($summary, 120));
        if (application_logs_is_throttled($pdo, $fingerprint)) {
            return;
        }

        $now = application_logs_utc_now();
        $exceptionDetail = isset($event['exception_detail'])
            ? application_logs_sanitize_text((string) $event['exception_detail'], APP_LOG_EXCEPTION_MAX)
            : null;
        if ($exceptionDetail === '') {
            $exceptionDetail = null;
        }
        $isSensitive = !empty($event['is_sensitive']) || $exceptionDetail !== null;
        $correlation = application_logs_sanitize_text((string) ($event['correlation_id'] ?? ''), 64);
        if ($correlation === '') {
            $correlation = null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO application_logs (
                event_id, source_app, environment, severity, category, event_code, summary,
                metadata_json, exception_class, exception_detail, user_type, user_id, user_label,
                related_order_id, related_customer_id, correlation_id, client_occurred_at, client_timezone,
                occurred_at, ingested_at, app_version, os_version, device_id_hash, was_offline,
                is_sensitive, fingerprint
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?
            )
            ON DUPLICATE KEY UPDATE id = id'
        );

        $userType = isset($event['user_type']) ? application_logs_truncate(strtolower(trim((string) $event['user_type'])), 16) : null;
        if ($userType === '') {
            $userType = null;
        }
        $userId = isset($event['user_id']) ? (int) $event['user_id'] : null;
        if ($userId !== null && $userId <= 0) {
            $userId = null;
        }
        $orderId = isset($event['related_order_id']) ? (int) $event['related_order_id'] : null;
        if ($orderId !== null && $orderId <= 0) {
            $orderId = null;
        }
        $customerId = isset($event['related_customer_id']) ? (int) $event['related_customer_id'] : null;
        if ($customerId !== null && $customerId <= 0) {
            $customerId = null;
        }
        $clientTz = application_logs_sanitize_text((string) ($event['client_timezone'] ?? ''), 64);
        if ($clientTz === '') {
            $clientTz = null;
        }

        $stmt->execute([
            $eventId,
            $source,
            application_logs_environment(),
            $severity,
            application_logs_clamp_category(isset($event['category']) ? (string) $event['category'] : 'system'),
            $eventCode,
            $summary,
            application_logs_encode_metadata($event['metadata'] ?? null),
            isset($event['exception_class']) ? application_logs_truncate((string) $event['exception_class'], 191) : null,
            $exceptionDetail,
            $userType,
            $userId,
            isset($event['user_label']) ? application_logs_sanitize_text((string) $event['user_label'], 64) : null,
            $orderId,
            $customerId,
            $correlation,
            application_logs_parse_client_time(
                isset($event['client_occurred_at']) ? (string) $event['client_occurred_at'] : null,
                $clientTz
            ),
            $clientTz,
            $now,
            $now,
            isset($event['app_version']) ? application_logs_sanitize_text((string) $event['app_version'], 32) : null,
            isset($event['os_version']) ? application_logs_sanitize_text((string) $event['os_version'], 64) : null,
            isset($event['device_id_hash']) ? application_logs_sanitize_text((string) $event['device_id_hash'], 64) : null,
            !empty($event['was_offline']) ? 1 : 0,
            $isSensitive ? 1 : 0,
            $fingerprint,
        ]);

        application_logs_maybe_cleanup($pdo);
    } catch (Throwable $e) {
        error_log('[application-logs] write failed: ' . $e->getMessage());
    } finally {
        $writing = false;
    }
}

/**
 * @param array<string, mixed> $ctx
 */
function cms_app_log_exception(Throwable $e, array $ctx = []): void
{
    $class = get_class($e);
    $isDb = $e instanceof PDOException || stripos($class, 'PDO') !== false;
    cms_app_log([
        'severity' => $isDb ? 'CRITICAL' : 'ERROR',
        'source_app' => $ctx['source_app'] ?? 'backend',
        'category' => $ctx['category'] ?? ($isDb ? 'database' : 'api'),
        'event_code' => $ctx['event_code'] ?? 'unhandled_exception',
        'summary' => $ctx['summary'] ?? application_logs_sanitize_text($e->getMessage(), 200),
        'metadata' => $ctx['metadata'] ?? null,
        'user_type' => $ctx['user_type'] ?? null,
        'user_id' => $ctx['user_id'] ?? null,
        'user_label' => $ctx['user_label'] ?? null,
        'related_order_id' => $ctx['related_order_id'] ?? null,
        'related_customer_id' => $ctx['related_customer_id'] ?? null,
        'correlation_id' => $ctx['correlation_id'] ?? null,
        'exception_class' => $class,
        'exception_detail' => $class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
        'is_sensitive' => true,
    ]);
}

function application_logs_is_throttled(PDO $pdo, string $fingerprint): bool
{
    try {
        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . APP_LOG_THROTTLE_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'SELECT id FROM application_logs WHERE fingerprint = ? AND ingested_at >= ? LIMIT 1'
        );
        $stmt->execute([$fingerprint, $since]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function application_logs_maybe_cleanup(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (random_int(1, 25) !== 1) {
        return;
    }
    application_logs_cleanup($pdo, APP_LOG_CLEANUP_BATCH);
}

function application_logs_cleanup(PDO $pdo, int $limit = APP_LOG_CLEANUP_BATCH): int
{
    try {
        application_logs_ensure_schema($pdo);
        $days = application_logs_retention_days();
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');
        $limit = max(1, min(5000, $limit));
        $stmt = $pdo->prepare(
            "DELETE FROM application_logs WHERE ingested_at < ? LIMIT {$limit}"
        );
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('[application-logs] cleanup failed: ' . $e->getMessage());

        return 0;
    }
}

function application_logs_rate_allow(string $key): bool
{
    $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'startech-log-ingest';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return true;
    }
    $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    $now = time();
    $times = [];
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return true;
    }
    try {
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $times = $decoded;
            }
        }
        $times = array_values(array_filter(
            $times,
            static fn ($t) => is_int($t) || is_numeric($t) ? ((int) $t) > ($now - APP_LOG_RATE_WINDOW) : false
        ));
        $times = array_map('intval', $times);
        if (count($times) >= APP_LOG_RATE_MAX) {
            return false;
        }
        $times[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($times));

        return true;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * @param array{type:string,id:int,label:string} $actor
 * @param list<mixed> $events
 * @return array{accepted:int,duplicates:int,rejected:int,skipped:int}
 */
function application_logs_ingest_batch(array $actor, array $events, string $forcedSource): array
{
    $accepted = 0;
    $duplicates = 0;
    $rejected = 0;
    $skipped = 0;

    $pdo = cms_log_pdo();
    if ($pdo === null) {
        return ['accepted' => 0, 'duplicates' => 0, 'rejected' => count($events), 'skipped' => 0];
    }
    application_logs_ensure_schema($pdo);

    $rateKey = $actor['type'] . ':' . $actor['id'];
    if (!application_logs_rate_allow($rateKey)) {
        throw new RuntimeException('rate_limited');
    }

    $now = application_logs_utc_now();
    $source = application_logs_clamp_source($forcedSource, $forcedSource);

    foreach ($events as $raw) {
        if (!is_array($raw)) {
            $rejected++;
            continue;
        }
        $severity = application_logs_clamp_severity(isset($raw['severity']) ? (string) $raw['severity'] : 'WARNING', true);
        $ranks = application_logs_severity_ranks();
        if (($ranks[$severity] ?? 40) < application_logs_min_rank()) {
            $skipped++;
            continue;
        }
        $eventCode = application_logs_sanitize_text((string) ($raw['event_code'] ?? $raw['event_name'] ?? ''), 64);
        if ($eventCode === '') {
            $rejected++;
            continue;
        }
        $summary = application_logs_sanitize_text((string) ($raw['summary'] ?? $eventCode), APP_LOG_SUMMARY_MAX);
        $eventId = application_logs_normalize_event_id((string) ($raw['event_id'] ?? ''));
        $fingerprint = sha1($source . '|' . $eventCode . '|' . application_logs_truncate($summary, 120));

        $clientTz = application_logs_sanitize_text((string) ($raw['client_timezone'] ?? $raw['timezone'] ?? ''), 64);
        if ($clientTz === '') {
            $clientTz = null;
        }
        $exceptionDetail = isset($raw['exception_detail']) || isset($raw['stack_trace'])
            ? application_logs_sanitize_text((string) ($raw['exception_detail'] ?? $raw['stack_trace'] ?? ''), APP_LOG_EXCEPTION_MAX)
            : null;
        if ($exceptionDetail === '') {
            $exceptionDetail = null;
        }

        try {
            $check = $pdo->prepare('SELECT id FROM application_logs WHERE source_app = ? AND event_id = ? LIMIT 1');
            $check->execute([$source, $eventId]);
            if ($check->fetchColumn()) {
                $duplicates++;
                continue;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO application_logs (
                    event_id, source_app, environment, severity, category, event_code, summary,
                    metadata_json, exception_class, exception_detail, user_type, user_id, user_label,
                    related_order_id, related_customer_id, correlation_id, client_occurred_at, client_timezone,
                    occurred_at, ingested_at, app_version, os_version, device_id_hash, was_offline,
                    is_sensitive, fingerprint
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?
                )
                ON DUPLICATE KEY UPDATE id = id'
            );
            $stmt->execute([
                $eventId,
                $source,
                application_logs_environment(),
                $severity,
                application_logs_clamp_category(isset($raw['category']) ? (string) $raw['category'] : 'system'),
                $eventCode,
                $summary,
                application_logs_encode_metadata($raw['metadata'] ?? $raw['details'] ?? null),
                isset($raw['exception_class']) ? application_logs_truncate((string) $raw['exception_class'], 191) : null,
                $exceptionDetail,
                $actor['type'],
                $actor['id'],
                application_logs_sanitize_text($actor['label'], 64),
                isset($raw['related_order_id']) ? max(0, (int) $raw['related_order_id']) ?: null : null,
                isset($raw['related_customer_id']) ? max(0, (int) $raw['related_customer_id']) ?: null : null,
                application_logs_sanitize_text((string) ($raw['correlation_id'] ?? ''), 64) ?: null,
                application_logs_parse_client_time(
                    isset($raw['client_occurred_at']) ? (string) $raw['client_occurred_at'] : (isset($raw['occurred_at']) ? (string) $raw['occurred_at'] : null),
                    $clientTz
                ),
                $clientTz,
                $now,
                $now,
                application_logs_sanitize_text((string) ($raw['app_version'] ?? ''), 32) ?: null,
                application_logs_sanitize_text((string) ($raw['os_version'] ?? ''), 64) ?: null,
                application_logs_sanitize_text((string) ($raw['device_id_hash'] ?? $raw['device_id'] ?? ''), 64) ?: null,
                !empty($raw['was_offline']) ? 1 : 0,
                $exceptionDetail !== null ? 1 : 0,
                $fingerprint,
            ]);
            if ($stmt->rowCount() === 0) {
                $duplicates++;
            } else {
                $accepted++;
            }
        } catch (Throwable $e) {
            $rejected++;
        }
    }

    application_logs_maybe_cleanup($pdo);

    return compact('accepted', 'duplicates', 'rejected', 'skipped');
}

/**
 * @param array<string, mixed> $filters
 * @return array{sql:string,params:list<mixed>}
 */
function application_logs_where(array $filters): array
{
    $where = ['1=1'];
    $params = [];

    $range = cms_jalali_range_to_utc(
        isset($filters['from']) ? (string) $filters['from'] : null,
        isset($filters['to']) ? (string) $filters['to'] : null
    );
    if (!empty($range['start'])) {
        $where[] = 'occurred_at >= ?';
        $params[] = $range['start'];
    }
    if (!empty($range['end'])) {
        $where[] = 'occurred_at < ?';
        $params[] = $range['end'];
    }
    $severity = strtoupper(trim((string) ($filters['severity'] ?? '')));
    if ($severity !== '' && in_array($severity, application_logs_severities(), true)) {
        $where[] = 'severity = ?';
        $params[] = $severity;
    }
    $source = trim((string) ($filters['source'] ?? $filters['source_app'] ?? ''));
    if ($source !== '' && isset(application_logs_source_labels()[$source])) {
        $where[] = 'source_app = ?';
        $params[] = $source;
    }
    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '' && isset(application_logs_category_labels()[$category])) {
        $where[] = 'category = ?';
        $params[] = $category;
    }
    $eventCode = trim((string) ($filters['event_code'] ?? ''));
    if ($eventCode !== '') {
        $where[] = 'event_code LIKE ?';
        $params[] = '%' . $eventCode . '%';
    }
    $userId = (int) ($filters['user_id'] ?? 0);
    if ($userId > 0) {
        $where[] = 'user_id = ?';
        $params[] = $userId;
    }
    $orderId = (int) ($filters['order_id'] ?? $filters['related_order_id'] ?? 0);
    if ($orderId > 0) {
        $where[] = 'related_order_id = ?';
        $params[] = $orderId;
    }
    $corr = trim((string) ($filters['correlation_id'] ?? ''));
    if ($corr !== '') {
        $where[] = 'correlation_id = ?';
        $params[] = application_logs_truncate($corr, 64);
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(summary LIKE ? OR event_code LIKE ? OR correlation_id LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    return ['sql' => implode(' AND ', $where), 'params' => $params];
}

/**
 * @param array<string, mixed> $filters
 * @return array{items:list<array<string,mixed>>,total:int,page:int,total_pages:int}
 */
function application_logs_list(PDO $pdo, array $filters = [], int $page = 1, int $pageSize = 40): array
{
    application_logs_ensure_schema($pdo);
    $page = max(1, $page);
    $pageSize = max(1, min(100, $pageSize));
    $parsed = application_logs_where($filters);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM application_logs WHERE {$parsed['sql']}");
    $countStmt->execute($parsed['params']);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;
    $stmt = $pdo->prepare(
        "SELECT * FROM application_logs WHERE {$parsed['sql']} ORDER BY occurred_at DESC, id DESC LIMIT {$pageSize} OFFSET {$offset}"
    );
    $stmt->execute($parsed['params']);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = application_logs_serialize_row($row, false);
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
    ];
}

/**
 * @param array<string, mixed> $filters
 * @return array{
 *   total:int,
 *   by_severity:array<string,int>,
 *   recent_critical:list<array<string,mixed>>,
 *   recent_sync_failures:list<array<string,mixed>>,
 *   recent_notify_failures:list<array<string,mixed>>
 * }
 */
function application_logs_summary(PDO $pdo, array $filters = []): array
{
    application_logs_ensure_schema($pdo);
    $parsed = application_logs_where($filters);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM application_logs WHERE {$parsed['sql']}");
    $countStmt->execute($parsed['params']);
    $total = (int) $countStmt->fetchColumn();

    $by = array_fill_keys(application_logs_severities(), 0);
    $sevStmt = $pdo->prepare(
        "SELECT severity, COUNT(*) AS n FROM application_logs WHERE {$parsed['sql']} GROUP BY severity"
    );
    $sevStmt->execute($parsed['params']);
    foreach ($sevStmt->fetchAll() ?: [] as $row) {
        $sev = (string) ($row['severity'] ?? '');
        if (isset($by[$sev])) {
            $by[$sev] = (int) $row['n'];
        }
    }

    $fetchRecent = static function (string $extra, array $params) use ($pdo, $parsed): array {
        $sql = "SELECT * FROM application_logs WHERE {$parsed['sql']} AND {$extra} ORDER BY occurred_at DESC, id DESC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[] = application_logs_serialize_row($row, false);
        }

        return $out;
    };

    return [
        'total' => $total,
        'by_severity' => $by,
        'recent_critical' => $fetchRecent("severity = 'CRITICAL'", $parsed['params']),
        'recent_sync_failures' => $fetchRecent(
            "category = 'synchronization' AND severity IN ('WARNING','ERROR','CRITICAL')",
            $parsed['params']
        ),
        'recent_notify_failures' => $fetchRecent(
            "category IN ('sms','push') AND severity IN ('WARNING','ERROR','CRITICAL')",
            $parsed['params']
        ),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function application_logs_get(PDO $pdo, int $id): ?array
{
    application_logs_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM application_logs WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return application_logs_serialize_row($row, true);
}

/**
 * @return list<array<string, mixed>>
 */
function application_logs_correlation_siblings(PDO $pdo, string $correlationId, int $excludeId = 0, int $limit = 20): array
{
    $correlationId = trim($correlationId);
    if ($correlationId === '') {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->prepare(
        "SELECT * FROM application_logs WHERE correlation_id = ? AND id <> ? ORDER BY occurred_at ASC, id ASC LIMIT {$limit}"
    );
    $stmt->execute([$correlationId, $excludeId]);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $out[] = application_logs_serialize_row($row, false);
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function application_logs_serialize_row(array $row, bool $includeDetails): array
{
    $occurred = (string) ($row['occurred_at'] ?? '');
    $ingested = (string) ($row['ingested_at'] ?? '');
    $clientAt = isset($row['client_occurred_at']) && $row['client_occurred_at'] !== null
        ? (string) $row['client_occurred_at']
        : null;
    $source = (string) ($row['source_app'] ?? 'other');
    $category = (string) ($row['category'] ?? 'system');
    $severity = (string) ($row['severity'] ?? 'INFO');
    $out = [
        'id' => (int) $row['id'],
        'event_id' => (string) ($row['event_id'] ?? ''),
        'source_app' => $source,
        'source_label' => application_logs_source_labels()[$source] ?? $source,
        'environment' => (string) ($row['environment'] ?? ''),
        'severity' => $severity,
        'severity_label' => application_logs_severity_labels()[$severity] ?? $severity,
        'category' => $category,
        'category_label' => application_logs_category_labels()[$category] ?? $category,
        'event_code' => (string) ($row['event_code'] ?? ''),
        'summary' => (string) ($row['summary'] ?? ''),
        'user_type' => isset($row['user_type']) ? (string) $row['user_type'] : null,
        'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
        'user_label' => isset($row['user_label']) ? (string) $row['user_label'] : null,
        'related_order_id' => isset($row['related_order_id']) ? (int) $row['related_order_id'] : null,
        'related_customer_id' => isset($row['related_customer_id']) ? (int) $row['related_customer_id'] : null,
        'correlation_id' => isset($row['correlation_id']) ? (string) $row['correlation_id'] : null,
        'occurred_at' => $occurred,
        'ingested_at' => $ingested,
        'client_occurred_at' => $clientAt,
        'client_timezone' => isset($row['client_timezone']) ? (string) $row['client_timezone'] : null,
        'occurred_at_jalali' => cms_jalali_format_datetime($occurred !== '' ? $occurred : null),
        'ingested_at_jalali' => cms_jalali_format_datetime($ingested !== '' ? $ingested : null),
        'client_occurred_at_jalali' => $clientAt !== null ? cms_jalali_format_datetime($clientAt) : null,
        'app_version' => isset($row['app_version']) ? (string) $row['app_version'] : null,
        'os_version' => isset($row['os_version']) ? (string) $row['os_version'] : null,
        'device_id_hash' => isset($row['device_id_hash']) ? (string) $row['device_id_hash'] : null,
        'was_offline' => !empty($row['was_offline']),
        'is_sensitive' => !empty($row['is_sensitive']),
    ];
    if ($includeDetails) {
        $meta = null;
        if (!empty($row['metadata_json'])) {
            $decoded = json_decode((string) $row['metadata_json'], true);
            $meta = is_array($decoded) ? $decoded : (string) $row['metadata_json'];
        }
        $out['metadata'] = $meta;
        $out['exception_class'] = isset($row['exception_class']) ? (string) $row['exception_class'] : null;
        $out['exception_detail'] = isset($row['exception_detail']) ? (string) $row['exception_detail'] : null;
    }

    return $out;
}

function application_logs_cleanup_secret(): string
{
    $cfg = function_exists('cms_config') ? cms_config() : [];
    foreach (['app_logs_cleanup_key', 'cheque_cron_key', 'km_cron_key'] as $key) {
        $value = trim((string) ($cfg[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    if (function_exists('cms_setting_get')) {
        $stored = trim((string) cms_setting_get('app_logs_cleanup_key', ''));
        if ($stored !== '') {
            return $stored;
        }
    }

    return '';
}
