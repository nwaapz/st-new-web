<?php
declare(strict_types=1);

require_once __DIR__ . '/order-cheques.php';

/** @return list<string> */
function cheque_workflow_statuses(): array
{
    return [
        'registered',
        'pending_verification',
        'confirmed',
        'delivered',
        'deposited',
        'due',
        'cleared',
        'rejected',
        'cancelled',
        'returned',
        'bounced',
        'rescheduled',
    ];
}

/** @return array<string, string> */
function cheque_workflow_status_labels(): array
{
    return [
        'registered' => 'ثبت‌شده',
        'pending_verification' => 'در انتظار تأیید',
        'confirmed' => 'تأیید شده',
        'delivered' => 'تحویل به شرکت',
        'deposited' => 'واریز به بانک',
        'due' => 'سررسید',
        'cleared' => 'وصول شده',
        'rejected' => 'رد شده',
        'cancelled' => 'لغو شده',
        'returned' => 'برگشتی',
        'bounced' => 'برگشت خورده',
        'rescheduled' => 'تغییر سررسید',
    ];
}

function cheque_workflow_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    order_cheques_ensure_schema($pdo);
    order_cheques_ensure_extended_columns($pdo);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cheque_status_history (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          cheque_id INT UNSIGNED NOT NULL,
          from_status VARCHAR(32) NULL,
          to_status VARCHAR(32) NOT NULL,
          changed_by VARCHAR(64) NOT NULL DEFAULT "",
          note VARCHAR(512) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_csh_cheque (cheque_id),
          KEY idx_csh_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function order_cheques_ensure_extended_columns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    order_cheques_ensure_schema($pdo);

    $cols = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM order_cheques');
    foreach ($stmt ? ($stmt->fetchAll() ?: []) : [] as $col) {
        $cols[(string) ($col['Field'] ?? '')] = true;
    }

    $alters = [
        'sayad_id' => 'ADD COLUMN sayad_id VARCHAR(64) NULL AFTER serial',
        'bank_name' => 'ADD COLUMN bank_name VARCHAR(128) NULL AFTER sayad_id',
        'issue_date' => 'ADD COLUMN issue_date DATE NULL AFTER bank_name',
        'image_path' => 'ADD COLUMN image_path VARCHAR(512) NULL AFTER issue_date',
        'workflow_status' => "ADD COLUMN workflow_status VARCHAR(32) NOT NULL DEFAULT 'registered' AFTER image_path",
        'notes' => 'ADD COLUMN notes TEXT NULL AFTER workflow_status',
        'verified_by' => 'ADD COLUMN verified_by VARCHAR(64) NULL AFTER notes',
        'verified_at' => 'ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL AFTER verified_by',
    ];

    foreach ($alters as $name => $sql) {
        if (!isset($cols[$name])) {
            $pdo->exec('ALTER TABLE order_cheques ' . $sql);
        }
    }

    $checked = true;
}

function cheque_workflow_resolve_status(array $row): string
{
    $workflow = trim((string) ($row['workflow_status'] ?? ''));
    if ($workflow !== '' && in_array($workflow, cheque_workflow_statuses(), true)) {
        return $workflow;
    }
    $bankResult = trim((string) ($row['bank_result'] ?? ''));
    if ($bankResult === 'funded') {
        return 'cleared';
    }
    if ($bankResult === 'bounced') {
        return 'bounced';
    }
    $dueOn = (string) ($row['due_on'] ?? '');
    if ($dueOn !== '') {
        $daysToDue = (int) ((strtotime($dueOn . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);
        if ($daysToDue < 0) {
            return 'due';
        }
    }

    return 'registered';
}

function cheque_workflow_sync_bank_result(PDO $pdo, int $chequeId, string $workflowStatus): void
{
    $bankResult = null;
    if ($workflowStatus === 'cleared') {
        $bankResult = 'funded';
    } elseif (in_array($workflowStatus, ['bounced', 'returned'], true)) {
        $bankResult = 'bounced';
    }
    if ($bankResult === null) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE order_cheques SET bank_result = ? WHERE id = ?');
    $stmt->execute([$bankResult, $chequeId]);
}

/** @return array<string, list<string>> */
function cheque_workflow_allowed_transitions(): array
{
    return [
        'registered' => ['pending_verification', 'confirmed', 'cancelled'],
        'pending_verification' => ['confirmed', 'rejected', 'cancelled'],
        'confirmed' => ['delivered', 'deposited', 'due', 'cancelled'],
        'delivered' => ['deposited', 'due'],
        'deposited' => ['due', 'cleared', 'bounced'],
        'due' => ['cleared', 'bounced', 'returned', 'rescheduled'],
        'rescheduled' => ['due', 'cleared', 'bounced'],
        'returned' => ['rescheduled', 'cancelled'],
        'bounced' => ['rescheduled'],
        'cleared' => [],
        'rejected' => [],
        'cancelled' => [],
    ];
}

/** @return array<string, string> */
function cheque_workflow_action_map(): array
{
    return [
        'verify' => 'confirmed',
        'reject' => 'rejected',
        'deliver' => 'delivered',
        'deposit' => 'deposited',
        'mark_due' => 'due',
        'clear' => 'cleared',
        'bounce' => 'bounced',
        'return' => 'returned',
        'reschedule' => 'rescheduled',
        'cancel' => 'cancelled',
        'pending_verification' => 'pending_verification',
    ];
}

function cheque_workflow_record_history(
    PDO $pdo,
    int $chequeId,
    ?string $fromStatus,
    string $toStatus,
    string $changedBy,
    ?string $note = null
): void {
    cheque_workflow_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO cheque_status_history (cheque_id, from_status, to_status, changed_by, note)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $chequeId,
        $fromStatus,
        $toStatus,
        $changedBy,
        $note,
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function cheque_workflow_fetch_history(PDO $pdo, int $chequeId): array
{
    cheque_workflow_ensure_schema($pdo);
    if ($chequeId <= 0) {
        return [];
    }
    $labels = cheque_workflow_status_labels();
    $stmt = $pdo->prepare(
        'SELECT * FROM cheque_status_history WHERE cheque_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$chequeId]);
    $rows = $stmt->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $to = (string) ($row['to_status'] ?? '');
        $from = isset($row['from_status']) ? (string) $row['from_status'] : null;
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'from_status' => $from,
            'to_status' => $to,
            'from_label' => $from !== null && $from !== '' ? ($labels[$from] ?? $from) : null,
            'to_label' => $labels[$to] ?? $to,
            'changed_by' => (string) ($row['changed_by'] ?? ''),
            'note' => isset($row['note']) ? (string) $row['note'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function cheque_workflow_timeline_steps(string $currentStatus): array
{
    $pipeline = ['registered', 'pending_verification', 'confirmed', 'delivered', 'deposited', 'due', 'cleared'];
    $labels = cheque_workflow_status_labels();
    $currentIndex = array_search($currentStatus, $pipeline, true);
    if ($currentIndex === false) {
        if (in_array($currentStatus, ['bounced', 'returned', 'rejected', 'cancelled'], true)) {
            return [
                [
                    'status' => $currentStatus,
                    'label' => $labels[$currentStatus] ?? $currentStatus,
                    'state' => 'current',
                ],
            ];
        }
        $currentIndex = 0;
    }

    $steps = [];
    foreach ($pipeline as $index => $status) {
        $state = 'upcoming';
        if ($index < $currentIndex) {
            $state = 'completed';
        } elseif ($index === $currentIndex) {
            $state = 'current';
        }
        $steps[] = [
            'status' => $status,
            'label' => $labels[$status] ?? $status,
            'state' => $state,
        ];
    }

    return $steps;
}

/**
 * @return list<string>
 */
function cheque_workflow_allowed_actions(string $status): array
{
    $map = [
        'registered' => ['pending_verification', 'verify', 'cancel'],
        'pending_verification' => ['verify', 'reject', 'cancel'],
        'confirmed' => ['deliver', 'deposit', 'cancel'],
        'delivered' => ['deposit', 'mark_due'],
        'deposited' => ['mark_due', 'clear', 'bounce'],
        'due' => ['clear', 'bounce', 'return', 'reschedule'],
        'rescheduled' => ['mark_due', 'clear', 'bounce'],
        'returned' => ['reschedule', 'cancel'],
        'bounced' => ['reschedule'],
        'cleared' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    return $map[$status] ?? [];
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function cheque_workflow_apply_transition(PDO $pdo, int $chequeId, array $body, ?array $adminUser = null): array
{
    cheque_workflow_ensure_schema($pdo);
    $action = trim((string) ($body['action'] ?? ''));
    $note = trim((string) ($body['note'] ?? ''));
    $newDueOn = trim((string) ($body['due_on'] ?? ''));
    $actionMap = cheque_workflow_action_map();

    if ($action === 'set_funded' || $action === 'funded') {
        $action = 'clear';
    }
    if ($action === 'set_bounced' || $action === 'bounced') {
        $action = 'bounce';
    }

    if (!isset($actionMap[$action])) {
        throw new RuntimeException('عملیات نامعتبر است');
    }

    $row = order_cheques_get($pdo, $chequeId);
    if ($row === null) {
        throw new RuntimeException('چک یافت نشد');
    }

    $fromStatus = cheque_workflow_resolve_status($row);
    $toStatus = $actionMap[$action];
    $allowed = cheque_workflow_allowed_transitions();
    $canGo = $allowed[$fromStatus] ?? [];
    if (!in_array($toStatus, $canGo, true) && $fromStatus !== $toStatus) {
        throw new RuntimeException('انتقال وضعیت مجاز نیست');
    }

    $username = is_array($adminUser) ? trim((string) ($adminUser['username'] ?? 'admin')) : 'admin';
    $updates = ['workflow_status = ?'];
    $params = [$toStatus];

    if ($action === 'verify') {
        $updates[] = 'verified_by = ?';
        $updates[] = 'verified_at = NOW()';
        $params[] = $username;
    }
    if ($action === 'reschedule' && $newDueOn !== '') {
        $normalized = order_cheques_normalize_date($newDueOn, 'سررسید جدید');
        $updates[] = 'due_on = ?';
        $updates[] = 'reminder_sent_at = NULL';
        $params[] = $normalized;
    }

    $params[] = $chequeId;
    $sql = 'UPDATE order_cheques SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    cheque_workflow_sync_bank_result($pdo, $chequeId, $toStatus);
    cheque_workflow_record_history($pdo, $chequeId, $fromStatus, $toStatus, $username, $note !== '' ? $note : null);
    order_cheques_sync_analytics($pdo, $chequeId);

    require_once __DIR__ . '/admin-audit.php';
    admin_audit_ensure_schema($pdo);
    $auditUserId = is_array($adminUser) ? (int) ($adminUser['id'] ?? 0) : 0;
    $auditUsername = is_array($adminUser) ? trim((string) ($adminUser['username'] ?? 'admin')) : 'admin';
    $auditStmt = $pdo->prepare(
        'INSERT INTO admin_audit_log
         (admin_user_id, admin_username, action, entity_type, entity_id, entity_label, summary, detail_json, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $auditStmt->execute([
        $auditUserId > 0 ? $auditUserId : null,
        $auditUsername,
        'order.cheque.transition',
        'cheque',
        $chequeId,
        'چک #' . $chequeId,
        'تغییر وضعیت چک: ' . $fromStatus . ' → ' . $toStatus,
        json_encode(['from' => $fromStatus, 'to' => $toStatus, 'note' => $note], JSON_UNESCAPED_UNICODE),
        'api',
    ]);

    $labels = cheque_workflow_status_labels();

    return [
        'message' => 'وضعیت چک به «' . ($labels[$toStatus] ?? $toStatus) . '» تغییر کرد',
        'workflow_status' => $toStatus,
        'status_label' => $labels[$toStatus] ?? $toStatus,
    ];
}

/**
 * @param array<int, int> $chequeIds
 * @return array<string, mixed>
 */
function cheque_workflow_bulk_transition(PDO $pdo, array $chequeIds, string $action, ?string $note, ?array $adminUser): array
{
    $success = 0;
    $failed = 0;
    $errors = [];
    foreach ($chequeIds as $chequeId) {
        $chequeId = (int) $chequeId;
        if ($chequeId <= 0) {
            continue;
        }
        try {
            cheque_workflow_apply_transition($pdo, $chequeId, [
                'action' => $action,
                'note' => $note,
            ], $adminUser);
            $success++;
        } catch (Throwable $e) {
            $failed++;
            $errors[] = ['cheque_id' => $chequeId, 'error' => $e->getMessage()];
        }
    }

    return [
        'success' => $success,
        'failed' => $failed,
        'errors' => $errors,
        'message' => $success . ' چک به‌روز شد' . ($failed > 0 ? '، ' . $failed . ' ناموفق' : ''),
    ];
}
