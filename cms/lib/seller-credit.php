<?php
declare(strict_types=1);

/**
 * Seller credit wallet: live old_serials.score → tomans, spent on mechanic outbound SMS.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

function seller_credit_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mechanic_credit_ledger (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          mechanic_id INT UNSIGNED NOT NULL,
          phone VARCHAR(20) NOT NULL,
          amount_toman BIGINT NOT NULL,
          reason VARCHAR(40) NOT NULL,
          ref_type VARCHAR(40) NULL,
          ref_id BIGINT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_mcl_mechanic (mechanic_id),
          KEY idx_mcl_phone (phone),
          KEY idx_mcl_ref (ref_type, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

function seller_credit_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '98') === 0 && strlen($digits) >= 12) {
        $digits = '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

/**
 * Phone variants that may appear in legacy old_serials.phone rows.
 *
 * @return string[]
 */
function seller_credit_phone_candidates(string $phone): array
{
    $norm = seller_credit_normalize_phone($phone);
    if ($norm === '') {
        return [];
    }
    $out = [$norm];
    if (strpos($norm, '0') === 0 && strlen($norm) === 11) {
        $out[] = substr($norm, 1);
        $out[] = '98' . substr($norm, 1);
    }
    return array_values(array_unique($out));
}

/**
 * @return array{toman_per_score:int, sms_cost_toman:int}
 */
function seller_credit_rates(): array
{
    $perScore = (int) cms_setting_get('seller_credit_toman_per_score', '0');
    $smsCost = (int) cms_setting_get('seller_credit_sms_cost_toman', '0');
    return [
        'toman_per_score' => max(0, $perScore),
        'sms_cost_toman' => max(0, $smsCost),
    ];
}

function seller_credit_mcode_score_total(?PDO $smsPdo, string $phone): float
{
    if (!$smsPdo instanceof PDO) {
        return 0.0;
    }
    $candidates = seller_credit_phone_candidates($phone);
    if ($candidates === []) {
        return 0.0;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $hasCategory = false;
        try {
            $col = $smsPdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                 LIMIT 1'
            );
            $col->execute(['old_serials', 'category']);
            $hasCategory = (bool) $col->fetchColumn();
        } catch (Throwable $e) {
            $hasCategory = false;
        }

        if ($hasCategory) {
            $sql = "SELECT COALESCE(SUM(score), 0) AS total
                    FROM old_serials
                    WHERE phone IN ($placeholders)
                      AND phone != ''
                      AND phone != '0'
                      AND score > 0
                      AND category = 'seller_to_end_user'";
        } else {
            $sql = "SELECT COALESCE(SUM(score), 0) AS total
                    FROM old_serials
                    WHERE phone IN ($placeholders)
                      AND phone != ''
                      AND phone != '0'
                      AND score > 0";
        }
        $stmt = $smsPdo->prepare($sql);
        $stmt->execute($candidates);
        $row = $stmt->fetch();
        return $row ? (float) $row['total'] : 0.0;
    } catch (Throwable $e) {
        error_log('[seller_credit_mcode_score_total] ' . $e->getMessage());
        return 0.0;
    }
}

function seller_credit_spent_total(PDO $cmsPdo, int $mechanicId): int
{
    seller_credit_ensure_schema($cmsPdo);
    $stmt = $cmsPdo->prepare(
        'SELECT COALESCE(SUM(ABS(amount_toman)), 0) AS total
         FROM mechanic_credit_ledger
         WHERE mechanic_id = ? AND amount_toman < 0'
    );
    $stmt->execute([$mechanicId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['total'] : 0;
}

/**
 * @return array{
 *   score:float,
 *   earned:float,
 *   spent:int,
 *   available:float,
 *   sms_cost:int,
 *   toman_per_score:int,
 *   sms_db_configured:bool
 * }
 */
function seller_credit_balance(PDO $cmsPdo, ?PDO $smsPdo, int $mechanicId, string $phone): array
{
    seller_credit_ensure_schema($cmsPdo);
    $rates = seller_credit_rates();
    $score = seller_credit_mcode_score_total($smsPdo, $phone);
    $earned = $score * $rates['toman_per_score'];
    $spent = seller_credit_spent_total($cmsPdo, $mechanicId);
    $available = max(0, $earned - $spent);

    return [
        'score' => $score,
        'earned' => $earned,
        'spent' => $spent,
        'available' => $available,
        'sms_cost' => $rates['sms_cost_toman'],
        'toman_per_score' => $rates['toman_per_score'],
        'sms_db_configured' => $smsPdo instanceof PDO,
    ];
}

/**
 * Public/auth payload fragment for mechanic credit.
 *
 * @return array{available:int, sms_cost:int, score:float, earned:float, spent:int}
 */
function seller_credit_public_payload(PDO $cmsPdo, int $mechanicId, string $phone): array
{
    $balance = seller_credit_balance($cmsPdo, cms_sms_pdo(), $mechanicId, $phone);
    return [
        'available' => (int) max(0, floor($balance['available'])),
        'sms_cost' => $balance['sms_cost'],
        'score' => $balance['score'],
        'earned' => $balance['earned'],
        'spent' => $balance['spent'],
    ];
}

/**
 * True when the body cannot go out as GSM-7 (English) and must use Unicode (70 chars / SMS).
 */
function seller_credit_sms_is_unicode(string $text): bool
{
    if ($text === '') {
        return false;
    }
    if (function_exists('mb_strlen')) {
        return strlen($text) !== mb_strlen($text, 'UTF-8');
    }
    return (bool) preg_match('/[^\x00-\x7F]/', $text);
}

/**
 * How many billed SMS parts this body needs.
 * Unicode / Farsi: 70 characters each. Pure English: 160 characters each.
 */
function seller_credit_sms_segments(string $text): int
{
    $text = str_replace("\r\n", "\n", $text);
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($len <= 0) {
        return 0;
    }
    $per = seller_credit_sms_is_unicode($text) ? 70 : 160;
    return (int) ceil($len / $per);
}

/**
 * Insert a debit after a successful SMS send.
 */
function seller_credit_debit_sms(
    PDO $cmsPdo,
    int $mechanicId,
    string $phone,
    int $amountToman,
    ?int $smsLogId = null
): void {
    if ($amountToman <= 0) {
        return;
    }
    seller_credit_ensure_schema($cmsPdo);
    $norm = seller_credit_normalize_phone($phone);
    $neg = -1 * abs($amountToman);
    $stmt = $cmsPdo->prepare(
        'INSERT INTO mechanic_credit_ledger
           (mechanic_id, phone, amount_toman, reason, ref_type, ref_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $mechanicId,
        $norm !== '' ? $norm : $phone,
        $neg,
        'sms_send',
        $smsLogId !== null ? 'mechanic_sms_log' : null,
        $smsLogId,
    ]);
}

/**
 * Check whether the mechanic can afford $segments outbound SMS parts at the configured cost.
 *
 * @return array{ok:bool, balance:array, error:?string, segments:int, total_cost:int}
 */
function seller_credit_can_send_sms(PDO $cmsPdo, int $mechanicId, string $phone, int $segments = 1): array
{
    $segments = max(1, $segments);
    $balance = seller_credit_balance($cmsPdo, cms_sms_pdo(), $mechanicId, $phone);
    $unit = $balance['sms_cost'];
    $totalCost = $unit * $segments;
    if ($unit <= 0) {
        return ['ok' => true, 'balance' => $balance, 'error' => null, 'segments' => $segments, 'total_cost' => 0];
    }
    if ($balance['available'] < $totalCost) {
        return [
            'ok' => false,
            'balance' => $balance,
            'error' => 'موجودی اعتبار کافی نیست. برای ارسال پیامک به باشگاه مشتریان مراجعه کنید.',
            'segments' => $segments,
            'total_cost' => $totalCost,
        ];
    }
    return ['ok' => true, 'balance' => $balance, 'error' => null, 'segments' => $segments, 'total_cost' => $totalCost];
}
