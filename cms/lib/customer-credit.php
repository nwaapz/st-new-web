<?php
declare(strict_types=1);

require_once __DIR__ . '/order-cheques.php';
require_once __DIR__ . '/invoices.php';

function customer_credit_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    order_cheques_ensure_schema($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS customer_credit_profiles (
          phone VARCHAR(20) NOT NULL,
          customer_name VARCHAR(191) NULL,
          credit_limit_toman BIGINT NOT NULL DEFAULT 0,
          notes TEXT NULL,
          updated_by VARCHAR(64) NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (phone),
          KEY idx_ccp_name (customer_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function customer_credit_normalize_phone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', trim($phone)) ?? '';
    if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
        $phone = '0' . $phone;
    }

    return $phone;
}

function customer_credit_setting_int(string $key, int $default): int
{
    if (!function_exists('cms_setting_get')) {
        return $default;
    }
    $raw = cms_setting_get($key);
    if ($raw === null || $raw === '') {
        return $default;
    }
    $value = (int) $raw;

    return $value > 0 ? $value : $default;
}

function customer_credit_warn_pct(): int
{
    return customer_credit_setting_int('cheque_credit_warn_pct', 90);
}

function customer_credit_block_pct(): int
{
    return customer_credit_setting_int('cheque_credit_block_pct', 100);
}

function customer_credit_bounce_restrict_count(): int
{
    return customer_credit_setting_int('cheque_bounce_restrict_count', 3);
}

function customer_credit_overdue_approval_threshold(): int
{
    return customer_credit_setting_int('cheque_overdue_approval_threshold', 50_000_000);
}

/**
 * @return array<string, mixed>|null
 */
function customer_credit_get_profile(PDO $pdo, string $phone): ?array
{
    customer_credit_ensure_schema($pdo);
    $phone = customer_credit_normalize_phone($phone);
    if ($phone === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM customer_credit_profiles WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return array<string, mixed>
 */
function customer_credit_exposure(PDO $pdo, string $phone): array
{
    customer_credit_ensure_schema($pdo);
    analytics_orders_ensure_schema($pdo);
    $phone = customer_credit_normalize_phone($phone);
    if ($phone === '') {
        return [
            'open_invoices_toman' => 0,
            'future_checks_toman' => 0,
            'overdue_toman' => 0,
            'total_exposure_toman' => 0,
            'utilization_pct' => 0.0,
            'bounced_6m_count' => 0,
        ];
    }

    $pendingStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(COALESCE(amount_toman, 0)), 0) AS future_checks_toman,
                COALESCE(SUM(CASE WHEN days_to_due < 0 THEN COALESCE(amount_toman, 0) ELSE 0 END), 0) AS overdue_toman
         FROM analytics_order_cheque_facts
         WHERE phone = ? AND is_pending = 1"
    );
    $pendingStmt->execute([$phone]);
    $pendingRow = $pendingStmt->fetch() ?: [];

    $openStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(COALESCE(f.revenue_toman, 0)), 0) AS open_invoices_toman
         FROM analytics_order_facts f
         INNER JOIN orders o ON o.id = f.order_id
         WHERE o.phone = ? AND f.is_open_backlog = 1"
    );
    $openStmt->execute([$phone]);
    $openRow = $openStmt->fetch() ?: [];

    $bounceStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM analytics_order_cheque_facts
         WHERE phone = ? AND bank_result = 'bounced'
           AND due_on >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)"
    );
    $bounceStmt->execute([$phone]);
    $bounced6m = (int) $bounceStmt->fetchColumn();

    $futureChecks = (int) ($pendingRow['future_checks_toman'] ?? 0);
    $overdue = (int) ($pendingRow['overdue_toman'] ?? 0);
    $openInvoices = (int) ($openRow['open_invoices_toman'] ?? 0);
    $totalExposure = $futureChecks + $openInvoices;

    return [
        'open_invoices_toman' => $openInvoices,
        'future_checks_toman' => $futureChecks,
        'overdue_toman' => $overdue,
        'total_exposure_toman' => $totalExposure,
        'utilization_pct' => 0.0,
        'bounced_6m_count' => $bounced6m,
    ];
}

/**
 * @return array<string, mixed>
 */
function customer_credit_summary(PDO $pdo, string $phone): array
{
    $phone = customer_credit_normalize_phone($phone);
    $profile = customer_credit_get_profile($pdo, $phone);
    $exposure = customer_credit_exposure($pdo, $phone);
    $limit = (int) ($profile['credit_limit_toman'] ?? 0);
    $total = (int) ($exposure['total_exposure_toman'] ?? 0);
    $utilization = $limit > 0 ? round(($total / $limit) * 100, 1) : 0.0;
    $exposure['utilization_pct'] = $utilization;

    $warnPct = customer_credit_warn_pct();
    $blockPct = customer_credit_block_pct();
    $riskLevel = 'normal';
    $riskReasons = [];
    if ($limit > 0 && $utilization >= $blockPct) {
        $riskLevel = 'blocked';
        $riskReasons[] = 'استفاده از اعتبار به حد مسدودسازی رسیده است';
    } elseif ($limit > 0 && $utilization >= $warnPct) {
        $riskLevel = 'warning';
        $riskReasons[] = 'استفاده از اعتبار بالای ' . $warnPct . '٪';
    }
    if ((int) ($exposure['overdue_toman'] ?? 0) >= customer_credit_overdue_approval_threshold()) {
        $riskLevel = $riskLevel === 'blocked' ? 'blocked' : 'warning';
        $riskReasons[] = 'مبلغ معوق بالا';
    }
    if ((int) ($exposure['bounced_6m_count'] ?? 0) >= customer_credit_bounce_restrict_count()) {
        $riskLevel = 'blocked';
        $riskReasons[] = 'تعداد برگشت چک در ۶ ماه اخیر بالا';
    }

    return [
        'phone' => $phone,
        'customer_name' => isset($profile['customer_name']) ? (string) $profile['customer_name'] : null,
        'credit_limit_toman' => $limit,
        'notes' => isset($profile['notes']) ? (string) $profile['notes'] : null,
        'updated_by' => isset($profile['updated_by']) ? (string) $profile['updated_by'] : null,
        'updated_at' => isset($profile['updated_at']) ? (string) $profile['updated_at'] : null,
        'exposure' => $exposure,
        'risk_level' => $riskLevel,
        'risk_reasons' => $riskReasons,
    ];
}

/**
 * @return array<string, mixed>
 */
function customer_credit_payment_profile(PDO $pdo, string $phone): array
{
    customer_credit_ensure_schema($pdo);
    $phone = customer_credit_normalize_phone($phone);
    if ($phone === '') {
        return [
            'on_time_pct' => 0.0,
            'avg_delay_days' => 0.0,
            'bounced_count' => 0,
            'cleared_count' => 0,
            'total_settled' => 0,
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT bank_result, days_to_due FROM analytics_order_cheque_facts WHERE phone = ?"
    );
    $stmt->execute([$phone]);
    $rows = $stmt->fetchAll() ?: [];

    $bounced = 0;
    $cleared = 0;
    $onTime = 0;
    $delays = [];
    foreach ($rows as $row) {
        $result = trim((string) ($row['bank_result'] ?? ''));
        if ($result === 'bounced') {
            $bounced++;
        } elseif ($result === 'funded') {
            $cleared++;
            $daysToDue = (int) ($row['days_to_due'] ?? 0);
            if ($daysToDue >= 0) {
                $onTime++;
            } else {
                $delays[] = abs($daysToDue);
            }
        }
    }
    $settled = $bounced + $cleared;
    $onTimePct = $settled > 0 ? round(($onTime / $settled) * 100, 1) : 0.0;
    $avgDelay = $delays !== [] ? round(array_sum($delays) / count($delays), 1) : 0.0;

    return [
        'on_time_pct' => $onTimePct,
        'avg_delay_days' => $avgDelay,
        'bounced_count' => $bounced,
        'cleared_count' => $cleared,
        'total_settled' => $settled,
    ];
}

/**
 * @return array{customers: list<array<string, mixed>>, total: int, page: int, total_pages: int}
 */
function customer_credit_list(PDO $pdo, string $query = '', int $page = 1, int $perPage = 20): array
{
    customer_credit_ensure_schema($pdo);
    analytics_orders_ensure_schema($pdo);
    $page = max(1, $page);
    $perPage = max(1, min(50, $perPage));
    $offset = ($page - 1) * $perPage;

    $phones = [];
    $phoneStmt = $pdo->query(
        "SELECT DISTINCT phone FROM analytics_order_cheque_facts WHERE phone IS NOT NULL AND phone <> ''"
    );
    foreach ($phoneStmt ? ($phoneStmt->fetchAll() ?: []) : [] as $row) {
        $phones[] = customer_credit_normalize_phone((string) ($row['phone'] ?? ''));
    }
    $profileStmt = $pdo->query('SELECT phone FROM customer_credit_profiles');
    foreach ($profileStmt ? ($profileStmt->fetchAll() ?: []) : [] as $row) {
        $phones[] = customer_credit_normalize_phone((string) ($row['phone'] ?? ''));
    }
    $phones = array_values(array_unique(array_filter($phones)));

    $customers = [];
    foreach ($phones as $phone) {
        $summary = customer_credit_summary($pdo, $phone);
        $name = (string) ($summary['customer_name'] ?? '');
        if ($name === '') {
            $nameStmt = $pdo->prepare(
                'SELECT customer_name FROM analytics_order_cheque_facts WHERE phone = ? AND customer_name IS NOT NULL LIMIT 1'
            );
            $nameStmt->execute([$phone]);
            $name = (string) ($nameStmt->fetchColumn() ?: '');
            $summary['customer_name'] = $name !== '' ? $name : null;
        }
        if ($query !== '') {
            $q = mb_strtolower($query);
            $hay = mb_strtolower($phone . ' ' . ($summary['customer_name'] ?? ''));
            if (!str_contains($hay, $q)) {
                continue;
            }
        }
        $customers[] = $summary;
    }

    usort($customers, static function (array $a, array $b): int {
        $ua = (float) ($a['exposure']['utilization_pct'] ?? 0);
        $ub = (float) ($b['exposure']['utilization_pct'] ?? 0);
        if ($ua !== $ub) {
            return $ub <=> $ua;
        }

        return strcmp((string) ($a['phone'] ?? ''), (string) ($b['phone'] ?? ''));
    });

    $total = count($customers);
    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
    $slice = array_slice($customers, $offset, $perPage);

    return [
        'customers' => $slice,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
    ];
}

/**
 * @return array<string, mixed>
 */
function customer_credit_detail(PDO $pdo, string $phone): array
{
    $phone = customer_credit_normalize_phone($phone);
    $summary = customer_credit_summary($pdo, $phone);
    $paymentProfile = customer_credit_payment_profile($pdo, $phone);

    $chequeStmt = $pdo->prepare(
        'SELECT cheque_id, order_public_code, due_on, amount_toman, bank_result, days_to_due
         FROM analytics_order_cheque_facts WHERE phone = ? ORDER BY due_on ASC LIMIT 50'
    );
    $chequeStmt->execute([$phone]);
    $cheques = [];
    foreach ($chequeStmt->fetchAll() ?: [] as $row) {
        $cheques[] = [
            'id' => (int) ($row['cheque_id'] ?? 0),
            'order_public_code' => (string) ($row['order_public_code'] ?? ''),
            'due_on' => (string) ($row['due_on'] ?? ''),
            'amount_toman' => isset($row['amount_toman']) ? (int) $row['amount_toman'] : null,
            'bank_result' => isset($row['bank_result']) ? (string) $row['bank_result'] : null,
            'days_to_due' => (int) ($row['days_to_due'] ?? 0),
        ];
    }

    return array_merge($summary, [
        'payment_profile' => $paymentProfile,
        'cheques' => $cheques,
    ]);
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function customer_credit_set_limit(PDO $pdo, array $body, ?array $adminUser = null): array
{
    customer_credit_ensure_schema($pdo);
    $phone = customer_credit_normalize_phone((string) ($body['phone'] ?? $body['id'] ?? ''));
    if ($phone === '') {
        throw new RuntimeException('شماره موبایل الزامی است');
    }
    $limit = (int) ($body['credit_limit_toman'] ?? 0);
    if ($limit < 0) {
        throw new RuntimeException('سقف اعتبار نامعتبر است');
    }
    $name = trim((string) ($body['customer_name'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    $username = is_array($adminUser) ? trim((string) ($adminUser['username'] ?? 'admin')) : 'admin';

    $existing = customer_credit_get_profile($pdo, $phone);
    if ($existing === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO customer_credit_profiles (phone, customer_name, credit_limit_toman, notes, updated_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $phone,
            $name !== '' ? $name : null,
            $limit,
            $notes !== '' ? $notes : null,
            $username,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE customer_credit_profiles
             SET customer_name = COALESCE(?, customer_name), credit_limit_toman = ?, notes = ?, updated_by = ?
             WHERE phone = ?'
        );
        $stmt->execute([
            $name !== '' ? $name : null,
            $limit,
            $notes !== '' ? $notes : null,
            $username,
            $phone,
        ]);
    }

    return [
        'message' => 'سقف اعتبار ذخیره شد',
        'customer' => customer_credit_detail($pdo, $phone),
    ];
}

/**
 * @return int
 */
function customer_credit_high_utilization_count(PDO $pdo): int
{
    $result = customer_credit_list($pdo, '', 1, 500);
    $warnPct = customer_credit_warn_pct();
    $count = 0;
    foreach ($result['customers'] as $customer) {
        $limit = (int) ($customer['credit_limit_toman'] ?? 0);
        $util = (float) ($customer['exposure']['utilization_pct'] ?? 0);
        if ($limit > 0 && $util >= $warnPct) {
            $count++;
        }
    }

    return $count;
}
