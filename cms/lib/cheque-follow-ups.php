<?php
declare(strict_types=1);

function cheque_follow_ups_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cheque_follow_ups (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          cheque_id INT UNSIGNED NULL,
          customer_phone VARCHAR(20) NOT NULL DEFAULT "",
          user_id INT UNSIGNED NULL,
          method VARCHAR(32) NOT NULL DEFAULT "call",
          result VARCHAR(32) NOT NULL DEFAULT "pending",
          note TEXT NULL,
          next_follow_up_at DATETIME NULL,
          attachment_path VARCHAR(512) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_cfu_cheque (cheque_id),
          KEY idx_cfu_phone (customer_phone),
          KEY idx_cfu_next (next_follow_up_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

/** @return array<string, string> */
function cheque_follow_up_method_labels(): array
{
    return [
        'call' => 'تماس',
        'sms' => 'پیامک',
        'visit' => 'مراجعه',
        'email' => 'ایمیل',
        'other' => 'سایر',
    ];
}

/** @return array<string, string> */
function cheque_follow_up_result_labels(): array
{
    return [
        'pending' => 'در انتظار',
        'contacted' => 'تماس گرفته شد',
        'promised' => 'تعهد پرداخت',
        'no_answer' => 'بدون پاسخ',
        'resolved' => 'حل شد',
        'failed' => 'ناموفق',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function cheque_follow_ups_fetch(
    PDO $pdo,
    ?int $chequeId = null,
    ?string $customerPhone = null,
    int $limit = 50
): array {
    cheque_follow_ups_ensure_schema($pdo);
    $where = ['1=1'];
    $params = [];
    if ($chequeId !== null && $chequeId > 0) {
        $where[] = 'cheque_id = ?';
        $params[] = $chequeId;
    }
    if ($customerPhone !== null && trim($customerPhone) !== '') {
        $where[] = 'customer_phone = ?';
        $params[] = trim($customerPhone);
    }
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT * FROM cheque_follow_ups WHERE ' . implode(' AND ', $where)
        . ' ORDER BY id DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $methodLabels = cheque_follow_up_method_labels();
    $resultLabels = cheque_follow_up_result_labels();
    $out = [];
    foreach ($rows as $row) {
        $method = (string) ($row['method'] ?? 'call');
        $result = (string) ($row['result'] ?? 'pending');
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'cheque_id' => isset($row['cheque_id']) ? (int) $row['cheque_id'] : null,
            'customer_phone' => (string) ($row['customer_phone'] ?? ''),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'method' => $method,
            'method_label' => $methodLabels[$method] ?? $method,
            'result' => $result,
            'result_label' => $resultLabels[$result] ?? $result,
            'note' => isset($row['note']) ? (string) $row['note'] : null,
            'next_follow_up_at' => isset($row['next_follow_up_at']) && $row['next_follow_up_at'] !== null
                ? (string) $row['next_follow_up_at']
                : null,
            'attachment_path' => isset($row['attachment_path']) ? (string) $row['attachment_path'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function cheque_follow_ups_create(PDO $pdo, array $body, ?array $adminUser = null): array
{
    cheque_follow_ups_ensure_schema($pdo);
    $chequeId = isset($body['cheque_id']) ? (int) $body['cheque_id'] : 0;
    $phone = trim((string) ($body['customer_phone'] ?? ''));
    $method = trim((string) ($body['method'] ?? 'call'));
    $result = trim((string) ($body['result'] ?? 'pending'));
    $note = trim((string) ($body['note'] ?? ''));
    $nextAt = trim((string) ($body['next_follow_up_at'] ?? ''));
    $attachment = trim((string) ($body['attachment_path'] ?? ''));
    $userId = is_array($adminUser) ? (int) ($adminUser['id'] ?? 0) : 0;

    if ($phone === '' && $chequeId > 0) {
        require_once __DIR__ . '/analytics-orders.php';
        $stmt = $pdo->prepare('SELECT phone FROM analytics_order_cheque_facts WHERE cheque_id = ? LIMIT 1');
        $stmt->execute([$chequeId]);
        $phone = trim((string) ($stmt->fetchColumn() ?: ''));
    }
    if ($phone === '') {
        throw new RuntimeException('شماره مشتری الزامی است');
    }

    $methods = array_keys(cheque_follow_up_method_labels());
    if (!in_array($method, $methods, true)) {
        $method = 'call';
    }
    $results = array_keys(cheque_follow_up_result_labels());
    if (!in_array($result, $results, true)) {
        $result = 'pending';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO cheque_follow_ups
         (cheque_id, customer_phone, user_id, method, result, note, next_follow_up_at, attachment_path)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $chequeId > 0 ? $chequeId : null,
        $phone,
        $userId > 0 ? $userId : null,
        $method,
        $result,
        $note !== '' ? $note : null,
        $nextAt !== '' ? $nextAt : null,
        $attachment !== '' ? $attachment : null,
    ]);

    return [
        'message' => 'پیگیری ثبت شد',
        'id' => (int) $pdo->lastInsertId(),
    ];
}
