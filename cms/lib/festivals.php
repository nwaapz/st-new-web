<?php
declare(strict_types=1);

/**
 * Festival campaigns (جشنواره): timed purchase-volume tiers per sales rep.
 * Progress accrues when an order is marked paid, or when a cheque is registered
 * as received for a cheque-method order (see festivals_record_paid_order).
 */

require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/jalali.php';

class FestivalException extends RuntimeException
{
    public string $errorCode;
    public int $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus = 409)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }
}

function festivals_emit_api_error(FestivalException $e): void
{
    if (!function_exists('api_json')) {
        throw $e;
    }
    api_json([
        'ok' => false,
        'error' => $e->getMessage(),
        'code' => $e->errorCode,
        'error_code' => $e->errorCode,
    ], $e->httpStatus);
}

/** @return list<string> */
function festivals_statuses(): array
{
    return ['draft', 'scheduled', 'active', 'paused', 'ended', 'archived'];
}

/** @return array<string, string> */
function festivals_status_labels(): array
{
    return [
        'draft' => 'پیش‌نویس',
        'scheduled' => 'زمان‌بندی‌شده',
        'active' => 'فعال',
        'paused' => 'متوقف',
        'ended' => 'پایان‌یافته',
        'archived' => 'بایگانی',
    ];
}

function festivals_now_utc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function festivals_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS festival_campaigns (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          title VARCHAR(191) NOT NULL,
          description TEXT NULL,
          starts_at DATETIME NOT NULL,
          ends_at DATETIME NOT NULL,
          status ENUM('draft','scheduled','active','paused','ended','archived') NOT NULL DEFAULT 'draft',
          display_priority INT NOT NULL DEFAULT 0,
          version INT UNSIGNED NOT NULL DEFAULT 1,
          created_by INT UNSIGNED NULL,
          updated_by INT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_festival_status_window (status, starts_at, ends_at),
          KEY idx_festival_priority (display_priority, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS festival_tiers (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          festival_id INT UNSIGNED NOT NULL,
          threshold_toman BIGINT UNSIGNED NOT NULL,
          benefit_title VARCHAR(191) NOT NULL,
          benefit_description TEXT NULL,
          sort_order INT NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          KEY idx_festival_tiers_festival (festival_id, sort_order, threshold_toman),
          CONSTRAINT fk_festival_tiers_campaign FOREIGN KEY (festival_id) REFERENCES festival_campaigns (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS festival_order_contributions (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          festival_id INT UNSIGNED NOT NULL,
          order_id INT UNSIGNED NOT NULL,
          sales_user_id INT UNSIGNED NOT NULL,
          amount_toman BIGINT UNSIGNED NOT NULL,
          paid_at DATETIME NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_festival_order (festival_id, order_id),
          KEY idx_foc_sales_user (festival_id, sales_user_id),
          CONSTRAINT fk_foc_festival FOREIGN KEY (festival_id) REFERENCES festival_campaigns (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS festival_sales_progress (
          festival_id INT UNSIGNED NOT NULL,
          sales_user_id INT UNSIGNED NOT NULL,
          total_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (festival_id, sales_user_id),
          CONSTRAINT fk_fsp_festival FOREIGN KEY (festival_id) REFERENCES festival_campaigns (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS festival_tier_gifts (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          festival_id INT UNSIGNED NOT NULL,
          sales_user_id INT UNSIGNED NOT NULL,
          tier_id INT UNSIGNED NOT NULL,
          delivered_at DATETIME NOT NULL,
          delivered_by INT UNSIGNED NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_festival_tier_gift (festival_id, sales_user_id, tier_id),
          KEY idx_ftg_festival_user (festival_id, sales_user_id),
          CONSTRAINT fk_ftg_festival FOREIGN KEY (festival_id) REFERENCES festival_campaigns (id) ON DELETE CASCADE,
          CONSTRAINT fk_ftg_tier FOREIGN KEY (tier_id) REFERENCES festival_tiers (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

function festivals_input_to_utc(string $date, string $time): string
{
    $date = trim(cms_from_persian_digits($date));
    $time = trim(cms_from_persian_digits($time));
    if ($date === '' || $time === '') {
        throw new InvalidArgumentException('تاریخ و ساعت شروع و پایان الزامی است');
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $tm)) {
        throw new InvalidArgumentException('ساعت نامعتبر است');
    }
    $hour = (int) $tm[1];
    $minute = (int) $tm[2];
    if ($hour > 23 || $minute > 59) {
        throw new InvalidArgumentException('ساعت نامعتبر است');
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $g) && (int) $g[1] > 1700) {
        $gy = (int) $g[1];
        $gm = (int) $g[2];
        $gd = (int) $g[3];
    } else {
        $jalali = cms_jalali_parse_date($date);
        if ($jalali === null) {
            throw new InvalidArgumentException('تاریخ نامعتبر است');
        }
        [$gy, $gm, $gd] = cms_jalali_to_gregorian($jalali[0], $jalali[1], $jalali[2]);
    }

    try {
        $local = new DateTimeImmutable(
            sprintf('%04d-%02d-%02d %02d:%02d:00', $gy, $gm, $gd, $hour, $minute),
            new DateTimeZone('Asia/Tehran')
        );
    } catch (Throwable $e) {
        throw new InvalidArgumentException('تاریخ نامعتبر است');
    }

    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function festivals_utc_to_jalali_parts(?string $utc): array
{
    if ($utc === null || trim($utc) === '') {
        return ['date' => '', 'time' => ''];
    }
    $formatted = cms_jalali_format_datetime($utc);
    if (!preg_match('/^(\d{4}\/\d{2}\/\d{2}) - (\d{2}:\d{2})/', $formatted, $m)) {
        return ['date' => '', 'time' => ''];
    }

    return ['date' => $m[1], 'time' => $m[2]];
}

function festivals_parse_threshold($raw): int
{
    if (is_int($raw)) {
        $amount = $raw;
    } else {
        $amount = invoices_parse_toman_amount(trim(cms_from_persian_digits((string) $raw)));
        if ($amount === null) {
            throw new InvalidArgumentException('آستانه خرید نامعتبر است');
        }
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('آستانه خرید باید بزرگ‌تر از صفر باشد');
    }
    if ($amount > 999999999999) {
        throw new InvalidArgumentException('مبلغ آستانه بیش از حد بزرگ است');
    }

    return $amount;
}

function festivals_sync_row(PDO $pdo, array $row, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? festivals_now_utc();
    $status = (string) ($row['status'] ?? '');
    if (!in_array($status, ['scheduled', 'active'], true)) {
        return $row;
    }
    $start = new DateTimeImmutable((string) $row['starts_at'], new DateTimeZone('UTC'));
    $end = new DateTimeImmutable((string) $row['ends_at'], new DateTimeZone('UTC'));
    $next = $status;
    if ($now >= $end) {
        $next = 'ended';
    } elseif ($status === 'scheduled' && $now >= $start && $now < $end) {
        $next = 'active';
    }
    if ($next === $status) {
        return $row;
    }
    $pdo->prepare('UPDATE festival_campaigns SET status = ? WHERE id = ? AND status = ?')
        ->execute([$next, (int) $row['id'], $status]);
    $row['status'] = $next;

    return $row;
}

function festivals_is_live(array $campaign, ?DateTimeImmutable $now = null): bool
{
    $now = $now ?? festivals_now_utc();
    if ((string) ($campaign['status'] ?? '') !== 'active') {
        return false;
    }
    $start = new DateTimeImmutable((string) $campaign['starts_at'], new DateTimeZone('UTC'));
    $end = new DateTimeImmutable((string) $campaign['ends_at'], new DateTimeZone('UTC'));

    return $now >= $start && $now < $end;
}

/** @return list<array<string, mixed>> */
function festivals_fetch_tiers(PDO $pdo, int $festivalId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM festival_tiers WHERE festival_id = ? ORDER BY sort_order ASC, threshold_toman ASC, id ASC'
    );
    $stmt->execute([$festivalId]);
    $out = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'threshold_toman' => (int) $row['threshold_toman'],
            'threshold_text' => invoices_format_toman((int) $row['threshold_toman']),
            'benefit_title' => (string) $row['benefit_title'],
            'benefit_description' => $row['benefit_description'] !== null ? (string) $row['benefit_description'] : '',
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $tiers
 * @return list<array<string, mixed>>
 */
function festivals_tiers_with_unlock(int $totalToman, array $tiers): array
{
    $out = [];
    foreach ($tiers as $tier) {
        $threshold = (int) ($tier['threshold_toman'] ?? 0);
        $out[] = array_merge($tier, [
            'unlocked' => $totalToman >= $threshold,
        ]);
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $tiers
 * @return array{next:?array<string,mixed>,remaining:int}
 */
function festivals_next_tier(int $totalToman, array $tiers): array
{
    foreach ($tiers as $tier) {
        $threshold = (int) ($tier['threshold_toman'] ?? 0);
        if ($totalToman < $threshold) {
            return [
                'next' => $tier,
                'remaining' => max(0, $threshold - $totalToman),
            ];
        }
    }

    return ['next' => null, 'remaining' => 0];
}

/**
 * @param list<array<string, mixed>> $tiers
 */
function festivals_serialize(array $campaign, array $tiers = []): array
{
    $startParts = festivals_utc_to_jalali_parts((string) ($campaign['starts_at'] ?? ''));
    $endParts = festivals_utc_to_jalali_parts((string) ($campaign['ends_at'] ?? ''));
    $start = new DateTimeImmutable((string) $campaign['starts_at'], new DateTimeZone('UTC'));
    $end = new DateTimeImmutable((string) $campaign['ends_at'], new DateTimeZone('UTC'));

    return [
        'id' => (int) $campaign['id'],
        'title' => (string) $campaign['title'],
        'description' => isset($campaign['description']) && $campaign['description'] !== null
            ? (string) $campaign['description']
            : '',
        'starts_at' => $start->format('Y-m-d\TH:i:s\Z'),
        'ends_at' => $end->format('Y-m-d\TH:i:s\Z'),
        'starts_at_jalali' => $startParts['date'] . ' ' . $startParts['time'],
        'ends_at_jalali' => $endParts['date'] . ' ' . $endParts['time'],
        'start_date' => $startParts['date'],
        'start_time' => $startParts['time'],
        'end_date' => $endParts['date'],
        'end_time' => $endParts['time'],
        'status' => (string) $campaign['status'],
        'status_label' => festivals_status_labels()[(string) $campaign['status']] ?? (string) $campaign['status'],
        'display_priority' => (int) ($campaign['display_priority'] ?? 0),
        'version' => (int) ($campaign['version'] ?? 1),
        'updated_at' => (string) ($campaign['updated_at'] ?? ''),
        'updated_at_jalali' => cms_jalali_format_datetime((string) ($campaign['updated_at'] ?? '')),
        'tier_count' => count($tiers),
        'tiers' => $tiers,
    ];
}

function festivals_get(PDO $pdo, int $id, bool $sync = true): ?array
{
    festivals_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM festival_campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($sync) {
        $row = festivals_sync_row($pdo, $row);
    }
    $tiers = festivals_fetch_tiers($pdo, $id);

    return festivals_serialize($row, $tiers);
}

/**
 * @param array{q?:string,status?:string} $filters
 * @return array{items:list<array<string,mixed>>,total:int,page:int,total_pages:int}
 */
function festivals_list(PDO $pdo, array $filters = [], int $page = 1, int $pageSize = 20): array
{
    festivals_ensure_schema($pdo);
    $page = max(1, $page);
    $pageSize = min(50, max(1, $pageSize));
    $where = ['1=1'];
    $params = [];
    $status = trim((string) ($filters['status'] ?? ''));
    if ($status === '' || $status === 'open') {
        $where[] = "status <> 'archived'";
    } elseif ($status !== 'all' && in_array($status, festivals_statuses(), true)) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = 'title LIKE ?';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare("SELECT COUNT(*) FROM festival_campaigns WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $offset = ($page - 1) * $pageSize;
    $stmt = $pdo->prepare(
        "SELECT c.*,
                (SELECT COUNT(*) FROM festival_tiers ft WHERE ft.festival_id = c.id) AS tier_count
         FROM festival_campaigns c
         WHERE {$whereSql}
         ORDER BY c.display_priority DESC, c.updated_at DESC, c.id DESC
         LIMIT {$pageSize} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $row = festivals_sync_row($pdo, $row);
        $items[] = festivals_serialize($row);
    }
    $pages = max(1, (int) ceil($total / $pageSize));

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => $pages,
    ];
}

/**
 * @param list<array<string, mixed>> $rawTiers
 * @return list<array{threshold_toman:int,benefit_title:string,benefit_description:string,sort_order:int}>
 */
function festivals_prepare_tiers(array $rawTiers, bool $requireAtLeastOne): array
{
    $prepared = [];
    $order = 0;
    foreach ($rawTiers as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $threshold = festivals_parse_threshold($raw['threshold_toman'] ?? $raw['threshold'] ?? 0);
        $title = trim((string) ($raw['benefit_title'] ?? $raw['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('عنوان جایزه هر پله الزامی است');
        }
        if (mb_strlen($title) > 191) {
            throw new InvalidArgumentException('عنوان جایزه بیش از حد طولانی است');
        }
        $description = trim((string) ($raw['benefit_description'] ?? $raw['description'] ?? ''));
        $sortOrder = isset($raw['sort_order']) ? (int) $raw['sort_order'] : $order;
        $prepared[] = [
            'threshold_toman' => $threshold,
            'benefit_title' => $title,
            'benefit_description' => $description,
            'sort_order' => $sortOrder,
        ];
        ++$order;
    }
    if ($requireAtLeastOne && $prepared === []) {
        throw new InvalidArgumentException('حداقل یک پله آستانه لازم است');
    }
    usort($prepared, static function (array $a, array $b): int {
        return [$a['sort_order'], $a['threshold_toman']] <=> [$b['sort_order'], $b['threshold_toman']];
    });
    $last = 0;
    foreach ($prepared as $tier) {
        if ($tier['threshold_toman'] <= $last) {
            throw new InvalidArgumentException('آستانه‌های خرید باید به‌صورت صعودی و بدون تکرار باشند');
        }
        $last = $tier['threshold_toman'];
    }

    return $prepared;
}

/**
 * @param array<string, mixed> $body
 */
function festivals_save(PDO $pdo, array $body): array
{
    festivals_ensure_schema($pdo);
    $id = (int) ($body['id'] ?? 0);
    $title = trim((string) ($body['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('عنوان الزامی است');
    }
    if (mb_strlen($title) > 191) {
        throw new InvalidArgumentException('عنوان بیش از حد طولانی است');
    }
    $description = trim((string) ($body['description'] ?? ''));
    $intent = trim((string) ($body['intent'] ?? 'draft'));
    if (!in_array($intent, ['draft', 'schedule', 'publish'], true)) {
        throw new InvalidArgumentException('وضعیت ذخیره نامعتبر است');
    }
    $priority = (int) ($body['display_priority'] ?? 0);
    $startsAt = festivals_input_to_utc((string) ($body['start_date'] ?? ''), (string) ($body['start_time'] ?? ''));
    $endsAt = festivals_input_to_utc((string) ($body['end_date'] ?? ''), (string) ($body['end_time'] ?? ''));
    if ($endsAt <= $startsAt) {
        throw new InvalidArgumentException('زمان پایان باید بعد از زمان شروع باشد');
    }

    $rawTiers = $body['tiers'] ?? [];
    if (!is_array($rawTiers)) {
        throw new InvalidArgumentException('پله‌های جشنواره نامعتبر است');
    }
    $requireTiers = $intent !== 'draft';
    $tiers = festivals_prepare_tiers($rawTiers, $requireTiers);

    $now = festivals_now_utc();
    $status = 'draft';
    if ($intent === 'schedule') {
        $status = 'scheduled';
    } elseif ($intent === 'publish') {
        $start = new DateTimeImmutable($startsAt, new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($endsAt, new DateTimeZone('UTC'));
        if ($now >= $end) {
            throw new InvalidArgumentException('بازه جشنواره در گذشته است');
        }
        $status = $now < $start ? 'scheduled' : 'active';
    }

    $version = (int) ($body['version'] ?? 0);
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $lock = $pdo->prepare('SELECT * FROM festival_campaigns WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $existing = $lock->fetch();
            if (!$existing) {
                throw new FestivalException('FESTIVAL_NOT_FOUND', 'جشنواره یافت نشد', 404);
            }
            if ($version > 0 && $version !== (int) $existing['version']) {
                throw new FestivalException('FESTIVAL_CONFLICT', 'این جشنواره توسط مدیر دیگری به‌روز شده است.', 409);
            }
            $upd = $pdo->prepare(
                'UPDATE festival_campaigns
                 SET title = ?, description = ?, starts_at = ?, ends_at = ?, status = ?, display_priority = ?,
                     version = version + 1, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            );
            $upd->execute([$title, $description !== '' ? $description : null, $startsAt, $endsAt, $status, $priority, $id]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO festival_campaigns (title, description, starts_at, ends_at, status, display_priority)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$title, $description !== '' ? $description : null, $startsAt, $endsAt, $status, $priority]);
            $id = (int) $pdo->lastInsertId();
        }

        $existingTiers = festivals_fetch_tiers($pdo, $id);
        $existingByThreshold = [];
        foreach ($existingTiers as $existingTier) {
            $existingByThreshold[(int) $existingTier['threshold_toman']] = (int) $existingTier['id'];
        }
        $keepIds = [];
        $tierUpd = $pdo->prepare(
            'UPDATE festival_tiers
             SET benefit_title = ?, benefit_description = ?, sort_order = ?
             WHERE id = ? AND festival_id = ?'
        );
        $tierIns = $pdo->prepare(
            'INSERT INTO festival_tiers (festival_id, threshold_toman, benefit_title, benefit_description, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($tiers as $tier) {
            $threshold = (int) $tier['threshold_toman'];
            $desc = $tier['benefit_description'] !== '' ? $tier['benefit_description'] : null;
            if (isset($existingByThreshold[$threshold])) {
                $tierId = $existingByThreshold[$threshold];
                $tierUpd->execute([
                    $tier['benefit_title'],
                    $desc,
                    $tier['sort_order'],
                    $tierId,
                    $id,
                ]);
                $keepIds[] = $tierId;
            } else {
                $tierIns->execute([
                    $id,
                    $threshold,
                    $tier['benefit_title'],
                    $desc,
                    $tier['sort_order'],
                ]);
                $keepIds[] = (int) $pdo->lastInsertId();
            }
        }
        if ($keepIds === []) {
            $pdo->prepare('DELETE FROM festival_tiers WHERE festival_id = ?')->execute([$id]);
        } else {
            $in = implode(',', array_map('intval', $keepIds));
            $pdo->prepare(
                "DELETE FROM festival_tiers WHERE festival_id = ? AND id NOT IN ({$in})"
            )->execute([$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $saved = festivals_get($pdo, $id, false);
    if ($saved === null) {
        throw new RuntimeException('ذخیره جشنواره ناموفق بود');
    }

    return $saved;
}

function festivals_contribution_count(PDO $pdo, int $festivalId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM festival_order_contributions WHERE festival_id = ?');
    $stmt->execute([$festivalId]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array<string, mixed>
 */
function festivals_action(PDO $pdo, int $id, string $action, int $version): array
{
    festivals_ensure_schema($pdo);
    $allowed = ['pause', 'resume', 'end', 'archive', 'duplicate', 'delete'];
    if (!in_array($action, $allowed, true)) {
        throw new InvalidArgumentException('عملیات نامعتبر است');
    }
    if ($action === 'duplicate') {
        return festivals_duplicate($pdo, $id);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM festival_campaigns WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new FestivalException('FESTIVAL_NOT_FOUND', 'جشنواره یافت نشد', 404);
        }
        if ($version > 0 && $version !== (int) $row['version']) {
            throw new FestivalException('FESTIVAL_CONFLICT', 'این جشنواره توسط مدیر دیگری به‌روز شده است.', 409);
        }
        $status = (string) $row['status'];
        $now = festivals_now_utc();
        $nowSql = $now->format('Y-m-d H:i:s');
        $endsAt = (string) $row['ends_at'];

        if ($action === 'delete') {
            if (festivals_contribution_count($pdo, $id) > 0) {
                throw new FestivalException(
                    'FESTIVAL_DELETE_BLOCKED',
                    'حذف ممکن نیست چون سفارش‌های پرداخت‌شده در این جشنواره ثبت شده‌اند. بایگانی کنید.',
                    409
                );
            }
            $pdo->prepare('DELETE FROM festival_campaigns WHERE id = ?')->execute([$id]);
            $pdo->commit();

            return ['deleted' => true, 'id' => $id];
        }

        $next = $status;
        if ($action === 'pause') {
            if (!in_array($status, ['active', 'scheduled'], true)) {
                throw new InvalidArgumentException('فقط جشنواره فعال یا زمان‌بندی‌شده قابل توقف است');
            }
            $next = 'paused';
        } elseif ($action === 'resume') {
            if ($status !== 'paused') {
                throw new InvalidArgumentException('فقط جشنواره متوقف‌شده قابل ادامه است');
            }
            $end = new DateTimeImmutable((string) $row['ends_at'], new DateTimeZone('UTC'));
            $start = new DateTimeImmutable((string) $row['starts_at'], new DateTimeZone('UTC'));
            if ($now >= $end) {
                throw new InvalidArgumentException('بازه جشنواره تمام شده است');
            }
            if (festivals_fetch_tiers($pdo, $id) === []) {
                throw new InvalidArgumentException('حداقل یک پله آستانه لازم است');
            }
            $next = $now < $start ? 'scheduled' : 'active';
        } elseif ($action === 'end') {
            $next = 'ended';
            if ($endsAt > $nowSql) {
                $endsAt = $nowSql;
            }
        } elseif ($action === 'archive') {
            $next = 'archived';
        }

        $pdo->prepare(
            'UPDATE festival_campaigns SET status = ?, ends_at = ?, version = version + 1 WHERE id = ?'
        )->execute([$next, $endsAt, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return festivals_get($pdo, $id) ?? ['id' => $id];
}

/**
 * @return array<string, mixed>
 */
function festivals_duplicate(PDO $pdo, int $id): array
{
    $source = festivals_get($pdo, $id, false);
    if ($source === null) {
        throw new FestivalException('FESTIVAL_NOT_FOUND', 'جشنواره یافت نشد', 404);
    }
    $body = [
        'title' => $source['title'] . ' (کپی)',
        'description' => $source['description'],
        'start_date' => $source['start_date'],
        'start_time' => $source['start_time'],
        'end_date' => $source['end_date'],
        'end_time' => $source['end_time'],
        'display_priority' => $source['display_priority'],
        'intent' => 'draft',
        'tiers' => array_map(static function (array $tier): array {
            return [
                'threshold_toman' => $tier['threshold_toman'],
                'benefit_title' => $tier['benefit_title'],
                'benefit_description' => $tier['benefit_description'],
                'sort_order' => $tier['sort_order'],
            ];
        }, $source['tiers'] ?? []),
    ];

    return festivals_save($pdo, $body);
}

function festivals_progress_total(PDO $pdo, int $festivalId, int $salesUserId): int
{
    $stmt = $pdo->prepare(
        'SELECT total_toman FROM festival_sales_progress WHERE festival_id = ? AND sales_user_id = ? LIMIT 1'
    );
    $stmt->execute([$festivalId, $salesUserId]);
    $value = $stmt->fetchColumn();

    return $value !== false ? (int) $value : 0;
}

/**
 * Paid cash/cheque orders, or cheque-method orders after office receipt of at least one cheque.
 *
 * @param array<string, mixed> $order
 */
function festivals_order_counts_for_progress(PDO $pdo, array $order): bool
{
    $status = (string) ($order['status'] ?? '');
    if ($status === 'paid') {
        return true;
    }
    if ($status !== 'payment_proof_sent') {
        return false;
    }
    $method = strtolower(trim((string) ($order['payment_method'] ?? '')));
    if ($method !== 'cheque') {
        return false;
    }
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0) {
        return false;
    }
    if (!function_exists('order_cheques_ensure_schema')) {
        require_once __DIR__ . '/order-cheques.php';
    }
    order_cheques_ensure_schema($pdo);
    $count = $pdo->prepare('SELECT COUNT(*) FROM order_cheques WHERE order_id = ?');
    $count->execute([$orderId]);

    return (int) $count->fetchColumn() > 0;
}

function festivals_record_paid_order(PDO $pdo, int $orderId): void
{
    festivals_ensure_schema($pdo);
    if (!function_exists('orders_fetch_items')) {
        require_once __DIR__ . '/orders.php';
    }

    $orderStmt = $pdo->prepare(
        'SELECT id, sales_user_id, status, payment_method FROM orders WHERE id = ? LIMIT 1'
    );
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();
    if (!$order || !festivals_order_counts_for_progress($pdo, $order)) {
        return;
    }
    $salesUserId = isset($order['sales_user_id']) ? (int) $order['sales_user_id'] : 0;
    if ($salesUserId <= 0) {
        return;
    }

    $items = orders_fetch_items($pdo, $orderId);
    $totals = invoices_totals_from_items($items);
    $amount = (int) ($totals['total'] ?? 0);
    if ($amount <= 0) {
        return;
    }

    $paidAt = festivals_now_utc()->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "SELECT * FROM festival_campaigns
         WHERE status = 'active' AND starts_at <= ? AND ends_at > ?"
    );
    $stmt->execute([$paidAt, $paidAt]);
    $campaigns = $stmt->fetchAll() ?: [];
    if ($campaigns === []) {
        return;
    }

    foreach ($campaigns as $row) {
        $row = festivals_sync_row($pdo, $row);
        if ((string) $row['status'] !== 'active') {
            continue;
        }
        $festivalId = (int) $row['id'];
        try {
            $ins = $pdo->prepare(
                'INSERT INTO festival_order_contributions (festival_id, order_id, sales_user_id, amount_toman, paid_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$festivalId, $orderId, $salesUserId, $amount, $paidAt]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                continue;
            }
            throw $e;
        }

        $upd = $pdo->prepare(
            'INSERT INTO festival_sales_progress (festival_id, sales_user_id, total_toman)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE total_toman = total_toman + VALUES(total_toman), updated_at = CURRENT_TIMESTAMP'
        );
        $upd->execute([$festivalId, $salesUserId, $amount]);
    }
}

/**
 * @return array{server_time:string,festivals:list<array<string,mixed>>}
 */
function festivals_sales_snapshot(PDO $pdo, int $salesUserId): array
{
    festivals_ensure_schema($pdo);
    $now = festivals_now_utc();
    $nowSql = $now->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "SELECT * FROM festival_campaigns
         WHERE status IN ('active', 'scheduled')
           AND ends_at > ?
           AND starts_at <= ends_at
         ORDER BY display_priority DESC, id DESC"
    );
    $stmt->execute([$nowSql]);
    $rows = $stmt->fetchAll() ?: [];

    $festivals = [];
    foreach ($rows as $row) {
        $row = festivals_sync_row($pdo, $row, $now);
        if (!in_array((string) $row['status'], ['active', 'scheduled'], true)) {
            continue;
        }
        $end = new DateTimeImmutable((string) $row['ends_at'], new DateTimeZone('UTC'));
        if ($now >= $end) {
            continue;
        }
        $id = (int) $row['id'];
        $tiers = festivals_fetch_tiers($pdo, $id);
        $total = festivals_progress_total($pdo, $id, $salesUserId);
        $tiersOut = festivals_tiers_with_unlock($total, $tiers);
        $next = festivals_next_tier($total, $tiers);
        $base = festivals_serialize($row, $tiersOut);
        $festivals[] = array_merge($base, [
            'total_toman' => $total,
            'total_text' => invoices_format_toman($total),
            'next_tier' => $next['next'],
            'remaining_to_next_toman' => $next['remaining'],
            'remaining_to_next_text' => $next['remaining'] > 0 ? invoices_format_toman($next['remaining']) : null,
            'is_live' => festivals_is_live($row, $now),
        ]);
    }

    return [
        'server_time' => $now->format('Y-m-d\TH:i:s\Z'),
        'festivals' => $festivals,
    ];
}

/**
 * @param list<array<string, mixed>> $tiers
 * @param array<int, array{delivered_at:string,delivered_by:?int}> $giftsByTier
 * @return array<string, mixed>
 */
function festivals_participant_progress_row(
    int $salesUserId,
    ?string $name,
    int $totalToman,
    string $updatedAt,
    array $tiers,
    array $giftsByTier
): array {
    $tierStates = [];
    $highest = null;
    foreach ($tiers as $tier) {
        $tierId = (int) ($tier['id'] ?? 0);
        $threshold = (int) ($tier['threshold_toman'] ?? 0);
        $unlocked = $totalToman >= $threshold && $threshold > 0;
        $gift = $giftsByTier[$tierId] ?? null;
        $deliveredAt = is_array($gift) ? (string) ($gift['delivered_at'] ?? '') : '';
        $state = [
            'tier_id' => $tierId,
            'threshold_toman' => $threshold,
            'threshold_text' => (string) ($tier['threshold_text'] ?? invoices_format_toman($threshold)),
            'benefit_title' => (string) ($tier['benefit_title'] ?? ''),
            'unlocked' => $unlocked,
            'delivered' => $deliveredAt !== '',
            'delivered_at' => $deliveredAt !== '' ? $deliveredAt : null,
            'delivered_at_jalali' => $deliveredAt !== '' ? cms_jalali_format_datetime($deliveredAt) : null,
            'delivered_by' => is_array($gift) ? ($gift['delivered_by'] ?? null) : null,
        ];
        $tierStates[] = $state;
        if ($unlocked) {
            $highest = $tier;
        }
    }
    $next = festivals_next_tier($totalToman, $tiers);

    return [
        'sales_user_id' => $salesUserId,
        'sales_user_name' => $name,
        'total_toman' => $totalToman,
        'total_text' => invoices_format_toman($totalToman),
        'updated_at' => $updatedAt,
        'updated_at_jalali' => cms_jalali_format_datetime($updatedAt),
        'highest_tier_title' => $highest !== null ? (string) ($highest['benefit_title'] ?? '') : null,
        'highest_tier_id' => $highest !== null ? (int) ($highest['id'] ?? 0) : null,
        'next_tier' => $next['next'],
        'remaining_to_next_toman' => $next['remaining'],
        'remaining_to_next_text' => $next['remaining'] > 0 ? invoices_format_toman($next['remaining']) : null,
        'tiers' => $tierStates,
    ];
}

/**
 * @param array{page?:int,page_size?:int,q?:string} $opts
 * @return array{items:list<array<string,mixed>>,total:int,page:int,total_pages:int,page_size:int}
 */
function festivals_admin_participants(PDO $pdo, int $festivalId, array $opts = []): array
{
    festivals_ensure_schema($pdo);
    require_once __DIR__ . '/sales-users.php';
    sales_users_ensure_schema($pdo);

    $page = max(1, (int) ($opts['page'] ?? 1));
    $pageSize = min(50, max(1, (int) ($opts['page_size'] ?? 50)));
    $q = trim((string) ($opts['q'] ?? ''));
    $tiers = festivals_fetch_tiers($pdo, $festivalId);

    $where = ['p.festival_id = ?'];
    $params = [$festivalId];
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where[] = '(su.display_name LIKE ? OR su.username LIKE ? OR CAST(p.sales_user_id AS CHAR) = ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $q;
    }
    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare(
        "SELECT COUNT(*)
         FROM festival_sales_progress p
         LEFT JOIN sales_users su ON su.id = p.sales_user_id
         WHERE {$whereSql}"
    );
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $pageSize));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $pageSize;

    $stmt = $pdo->prepare(
        "SELECT p.sales_user_id, p.total_toman, p.updated_at, su.display_name, su.username
         FROM festival_sales_progress p
         LEFT JOIN sales_users su ON su.id = p.sales_user_id
         WHERE {$whereSql}
         ORDER BY p.total_toman DESC, p.updated_at DESC, p.sales_user_id ASC
         LIMIT {$pageSize} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $userIds = [];
    foreach ($rows as $row) {
        $userIds[] = (int) $row['sales_user_id'];
    }

    $giftsByUser = [];
    if ($userIds !== []) {
        $in = implode(',', array_map('intval', $userIds));
        $giftStmt = $pdo->prepare(
            "SELECT sales_user_id, tier_id, delivered_at, delivered_by
             FROM festival_tier_gifts
             WHERE festival_id = ? AND sales_user_id IN ({$in})"
        );
        $giftStmt->execute([$festivalId]);
        foreach ($giftStmt->fetchAll() ?: [] as $gift) {
            $uid = (int) $gift['sales_user_id'];
            $tid = (int) $gift['tier_id'];
            $giftsByUser[$uid][$tid] = [
                'delivered_at' => (string) $gift['delivered_at'],
                'delivered_by' => $gift['delivered_by'] !== null ? (int) $gift['delivered_by'] : null,
            ];
        }
    }

    $items = [];
    foreach ($rows as $row) {
        $salesUserId = (int) $row['sales_user_id'];
        $name = trim((string) ($row['display_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['username'] ?? ''));
        }
        $items[] = festivals_participant_progress_row(
            $salesUserId,
            $name !== '' ? $name : null,
            (int) $row['total_toman'],
            (string) $row['updated_at'],
            $tiers,
            $giftsByUser[$salesUserId] ?? []
        );
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => $pages,
        'page_size' => $pageSize,
    ];
}

/**
 * @return array<string, mixed>
 */
function festivals_mark_tier_gift(
    PDO $pdo,
    int $festivalId,
    int $salesUserId,
    int $tierId,
    bool $delivered,
    int $adminId
): array {
    festivals_ensure_schema($pdo);
    if ($festivalId <= 0 || $salesUserId <= 0 || $tierId <= 0) {
        throw new InvalidArgumentException('شناسه نامعتبر است');
    }

    $tierStmt = $pdo->prepare(
        'SELECT id, threshold_toman, benefit_title FROM festival_tiers WHERE id = ? AND festival_id = ? LIMIT 1'
    );
    $tierStmt->execute([$tierId, $festivalId]);
    $tier = $tierStmt->fetch();
    if (!$tier) {
        throw new InvalidArgumentException('پله جشنواره یافت نشد');
    }

    $total = festivals_progress_total($pdo, $festivalId, $salesUserId);
    $threshold = (int) $tier['threshold_toman'];
    if ($delivered && $total < $threshold) {
        throw new InvalidArgumentException('این نماینده هنوز به این پله نرسیده است');
    }

    if ($delivered) {
        $now = festivals_now_utc()->format('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO festival_tier_gifts (festival_id, sales_user_id, tier_id, delivered_at, delivered_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE delivered_at = VALUES(delivered_at), delivered_by = VALUES(delivered_by)'
        );
        $ins->execute([$festivalId, $salesUserId, $tierId, $now, $adminId > 0 ? $adminId : null]);
    } else {
        $del = $pdo->prepare(
            'DELETE FROM festival_tier_gifts
             WHERE festival_id = ? AND sales_user_id = ? AND tier_id = ?'
        );
        $del->execute([$festivalId, $salesUserId, $tierId]);
    }

    $gifts = [];
    $giftStmt = $pdo->prepare(
        'SELECT tier_id, delivered_at, delivered_by
         FROM festival_tier_gifts
         WHERE festival_id = ? AND sales_user_id = ?'
    );
    $giftStmt->execute([$festivalId, $salesUserId]);
    foreach ($giftStmt->fetchAll() ?: [] as $gift) {
        $gifts[(int) $gift['tier_id']] = [
            'delivered_at' => (string) $gift['delivered_at'],
            'delivered_by' => $gift['delivered_by'] !== null ? (int) $gift['delivered_by'] : null,
        ];
    }

    require_once __DIR__ . '/sales-users.php';
    $progressStmt = $pdo->prepare(
        'SELECT total_toman, updated_at FROM festival_sales_progress
         WHERE festival_id = ? AND sales_user_id = ? LIMIT 1'
    );
    $progressStmt->execute([$festivalId, $salesUserId]);
    $progress = $progressStmt->fetch() ?: ['total_toman' => $total, 'updated_at' => ''];
    $name = sales_users_display_name_for_id($pdo, $salesUserId);

    return festivals_participant_progress_row(
        $salesUserId,
        $name,
        (int) ($progress['total_toman'] ?? $total),
        (string) ($progress['updated_at'] ?? ''),
        festivals_fetch_tiers($pdo, $festivalId),
        $gifts
    );
}
