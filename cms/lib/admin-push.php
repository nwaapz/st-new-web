<?php
declare(strict_types=1);

/**
 * FCM push notifications for CMS admin mobile app.
 */

/** Bumped when server delivery changes; diag must match this string. */
function admin_push_delivery_mode(): string
{
    return 'fcm';
}

function admin_push_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_push_tokens (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          admin_user_id INT UNSIGNED NOT NULL,
          fcm_token VARCHAR(512) NOT NULL,
          platform VARCHAR(32) NOT NULL DEFAULT \'android\',
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_admin_token (admin_user_id, fcm_token),
          KEY idx_fcm_token (fcm_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_push_inbox (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          admin_user_id INT UNSIGNED NOT NULL,
          title VARCHAR(180) NOT NULL,
          body VARCHAR(500) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_admin_inbox (admin_user_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_push_client_status (
          admin_user_id INT UNSIGNED NOT NULL,
          note VARCHAR(255) NOT NULL DEFAULT \'\',
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (admin_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $ready = true;
}

function admin_push_queue(PDO $pdo, int $adminUserId, string $title, string $body): void
{
    admin_push_ensure_schema($pdo);
    if ($adminUserId <= 0) {
        return;
    }
    $title = mb_substr(trim($title), 0, 180);
    $body = mb_substr(trim($body), 0, 500);
    if ($title === '' && $body === '') {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO admin_push_inbox (admin_user_id, title, body) VALUES (?, ?, ?)');
    $stmt->execute([$adminUserId, $title, $body]);
}

function admin_push_queue_all(PDO $pdo, string $title, string $body): void
{
    admin_push_ensure_schema($pdo);
    $stmt = $pdo->query('SELECT DISTINCT admin_user_id FROM admin_push_tokens');
    $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    foreach ($ids as $id) {
        admin_push_queue($pdo, (int) $id, $title, $body);
    }
}

/**
 * @return list<array{id: int, title: string, body: string}>
 */
function admin_push_pull(PDO $pdo, int $adminUserId): array
{
    admin_push_ensure_schema($pdo);
    if ($adminUserId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT id, title, body FROM admin_push_inbox WHERE admin_user_id = ? ORDER BY id ASC LIMIT 20'
    );
    $stmt->execute([$adminUserId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return [];
    }
    $ids = [];
    $items = [];
    foreach ($rows as $row) {
        $ids[] = (int) $row['id'];
        $items[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
        ];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $delete = $pdo->prepare('DELETE FROM admin_push_inbox WHERE id IN (' . $placeholders . ')');
    $delete->execute($ids);
    return $items;
}

function admin_push_save_client_note(PDO $pdo, int $adminUserId, string $note): void
{
    admin_push_ensure_schema($pdo);
    if ($adminUserId <= 0) {
        return;
    }
    $note = mb_substr(trim($note), 0, 255);
    $stmt = $pdo->prepare(
        'INSERT INTO admin_push_client_status (admin_user_id, note) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$adminUserId, $note]);
}

/**
 * @return array{note: string, updated_at: string}|null
 */
function admin_push_client_status(PDO $pdo, int $adminUserId): ?array
{
    admin_push_ensure_schema($pdo);
    if ($adminUserId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT note, updated_at FROM admin_push_client_status WHERE admin_user_id = ?');
    $stmt->execute([$adminUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return [
        'note' => (string) ($row['note'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

/**
 * Queue for the admin app inbox (works without Google FCM), then try FCM as a bonus.
 *
 * @return array{queued: bool, sent: int, failed: int, fcm_tokens: int}
 */
function admin_push_send_test(PDO $pdo, int $adminUserId, string $title, string $body): array
{
    admin_push_queue($pdo, $adminUserId, $title, $body);
    $tokens = admin_push_all_tokens($pdo);
    $data = [
        'order_id' => '0',
        'type' => 'test',
        'public_code' => '',
        'activity' => 'test',
    ];
    $sent = 0;
    $failed = 0;
    if (admin_push_service_account_path() !== null && $tokens !== []) {
        foreach ($tokens as $token) {
            if (admin_push_send_to_token($token, $title, $body, $data, $pdo)) {
                $sent++;
            } else {
                $failed++;
            }
        }
    }
    return [
        'queued' => true,
        'sent' => $sent,
        'failed' => $failed,
        'fcm_tokens' => count($tokens),
    ];
}

function admin_push_service_account_path(): ?string
{
    $libDir = __DIR__;
    $candidates = [
        dirname($libDir, 3) . '/deploy/firebase-service-account.json',
        dirname($libDir, 2) . '/deploy/firebase-service-account.json',
        dirname($libDir) . '/firebase-service-account.json',
        dirname($libDir, 2) . '/cms/firebase-service-account.json',
    ];
    $seen = [];
    foreach ($candidates as $path) {
        if (isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        if (is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function admin_push_firebase_project_id(): ?string
{
    $path = admin_push_service_account_path();
    if ($path === null) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }
    $projectId = trim((string) ($json['project_id'] ?? ''));
    return $projectId !== '' ? $projectId : null;
}

function admin_push_register_token(PDO $pdo, int $adminUserId, string $token, string $platform = 'android'): void
{
    admin_push_ensure_schema($pdo);
    $token = trim($token);
    if ($adminUserId <= 0 || $token === '') {
        throw new InvalidArgumentException('توکن نامعتبر است');
    }
    if (strlen($token) > 512) {
        throw new InvalidArgumentException('توکن بیش از حد طولانی است');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO admin_push_tokens (admin_user_id, fcm_token, platform)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE platform = VALUES(platform), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$adminUserId, $token, $platform !== '' ? $platform : 'android']);
}

function admin_push_unregister_token(PDO $pdo, int $adminUserId, string $token): void
{
    admin_push_ensure_schema($pdo);
    $token = trim($token);
    if ($adminUserId <= 0 || $token === '') {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM admin_push_tokens WHERE admin_user_id = ? AND fcm_token = ?');
    $stmt->execute([$adminUserId, $token]);
}

function admin_push_unregister_all_for_user(PDO $pdo, int $adminUserId): void
{
    admin_push_ensure_schema($pdo);
    if ($adminUserId <= 0) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM admin_push_tokens WHERE admin_user_id = ?');
    $stmt->execute([$adminUserId]);
}

/**
 * @return list<string>
 */
function admin_push_all_tokens(PDO $pdo): array
{
    admin_push_ensure_schema($pdo);
    $stmt = $pdo->query('SELECT DISTINCT fcm_token FROM admin_push_tokens ORDER BY updated_at DESC');
    $rows = $stmt ? $stmt->fetchAll() : [];
    $tokens = [];
    foreach ($rows as $row) {
        $token = trim((string) ($row['fcm_token'] ?? ''));
        if ($token !== '') {
            $tokens[] = $token;
        }
    }
    return $tokens;
}

/**
 * @return list<array{suffix: string, updated_at: string}>
 */
function admin_push_token_summaries(PDO $pdo): array
{
    admin_push_ensure_schema($pdo);
    $stmt = $pdo->query('SELECT fcm_token, updated_at FROM admin_push_tokens ORDER BY updated_at DESC');
    $rows = $stmt ? $stmt->fetchAll() : [];
    $out = [];
    foreach ($rows as $row) {
        $token = trim((string) ($row['fcm_token'] ?? ''));
        if ($token === '') {
            continue;
        }
        $out[] = [
            'suffix' => substr($token, -8),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    return $out;
}

function admin_push_delete_token(PDO $pdo, string $token): void
{
    admin_push_ensure_schema($pdo);
    $token = trim($token);
    if ($token === '') {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM admin_push_tokens WHERE fcm_token = ?');
    $stmt->execute([$token]);
}

function admin_push_last_fcm_error(): ?string
{
    return $GLOBALS['admin_push_last_fcm_error'] ?? null;
}

function admin_push_set_last_fcm_error(?string $message): void
{
    $GLOBALS['admin_push_last_fcm_error'] = $message;
}

function admin_push_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function admin_push_fetch_access_token(): ?string
{
    $path = admin_push_service_account_path();
    if ($path === null) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $sa = json_decode($raw, true);
    if (!is_array($sa)) {
        return null;
    }

    $clientEmail = trim((string) ($sa['client_email'] ?? ''));
    $privateKey = (string) ($sa['private_key'] ?? '');
    $tokenUri = trim((string) ($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
    if ($clientEmail === '' || $privateKey === '') {
        return null;
    }

    $now = time();
    $header = admin_push_base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = admin_push_base64url_encode(json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $tokenUri,
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $unsigned = $header . '.' . $claim;
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $jwt = $unsigned . '.' . admin_push_base64url_encode($signature);

    $ch = curl_init($tokenUri);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!is_string($response) || $response === '') {
        return null;
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return null;
    }
    $accessToken = trim((string) ($decoded['access_token'] ?? ''));
    return $accessToken !== '' ? $accessToken : null;
}

/**
 * @param array<string, string> $data
 */
function admin_push_send_to_token(string $token, string $title, string $body, array $data = [], ?PDO $pdo = null): bool
{
    admin_push_set_last_fcm_error(null);
    $projectId = admin_push_firebase_project_id();
    $accessToken = admin_push_fetch_access_token();
    if ($projectId === null || $accessToken === null) {
        admin_push_set_last_fcm_error('firebase_service_account_or_oauth_unavailable');
        return false;
    }

    // Standard FCM: notification payload only. Android shows it in the tray;
    // tap opens the app. No data payload and no channel_id (avoids silent drops).
    $payload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'android' => [
                'priority' => 'HIGH',
            ],
        ],
    ];

    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        return true;
    }

    $responseText = is_string($response) ? $response : '';
    $errorMessage = $responseText;
    if ($responseText !== '') {
        $decoded = json_decode($responseText, true);
        if (is_array($decoded)) {
            $errorMessage = trim((string) ($decoded['error']['message'] ?? $responseText));
        }
    }
    admin_push_set_last_fcm_error('HTTP ' . $status . ': ' . $errorMessage);

    if ($pdo !== null && $responseText !== '') {
        $upper = strtoupper($responseText);
        if (str_contains($upper, 'UNREGISTERED') || str_contains($upper, 'NOT_FOUND')) {
            admin_push_delete_token($pdo, $token);
        }
    }

    error_log('[admin-push] FCM send failed HTTP ' . $status . ': ' . $responseText);
    if (is_file(__DIR__ . '/application-logs.php')) {
        require_once __DIR__ . '/application-logs.php';
        cms_app_log([
            'source_app' => 'backend',
            'severity' => 'WARNING',
            'category' => 'push',
            'event_code' => 'push_send_failed',
            'summary' => 'ارسال پوش FCM ناموفق بود',
            'metadata' => [
                'http_status' => $status,
                'provider' => 'fcm',
                'fcm_error' => mb_substr($errorMessage, 0, 500),
            ],
        ]);
    }
    return false;
}

function admin_push_notify_order_activity(PDO $pdo, int $orderId, string $activityType, string $message = ''): void
{
    if ($orderId <= 0) {
        return;
    }
    if (!function_exists('orders_get_by_id')) {
        require_once __DIR__ . '/orders.php';
    }
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        return;
    }

    $copy = admin_push_message_for_client_activity($order, $activityType, $message);
    $publicCode = (string) ($order['public_code'] ?? '');
    $branchId = isset($order['branch_id']) ? (int) $order['branch_id'] : 0;
    $data = [
        'order_id' => (string) $orderId,
        'type' => (string) ($copy['type'] ?? $activityType),
        'public_code' => $publicCode,
        'activity' => $activityType,
        'scope' => $branchId > 0 ? 'branches' : 'customers',
    ];
    admin_push_queue_all($pdo, $copy['title'], $copy['body']);

    if (admin_push_service_account_path() === null) {
        error_log('[admin-push] firebase-service-account.json not found — place it in deploy/ or cms/ on the server');
        return;
    }

    $tokens = admin_push_all_tokens($pdo);
    if ($tokens === []) {
        error_log('[admin-push] no admin FCM tokens registered — open admin app, log in, allow notifications');
        return;
    }

    $sent = 0;
    foreach ($tokens as $token) {
        if (admin_push_send_to_token($token, $copy['title'], $copy['body'], $data, $pdo)) {
            $sent++;
        }
    }
    if ($sent === 0) {
        error_log('[admin-push] FCM send failed for all ' . count($tokens) . ' token(s), activity=' . $activityType);
    }
}

/**
 * @param array<string, mixed> $order
 * @return array{title: string, body: string, type: string}
 */
function admin_push_message_for_client_activity(array $order, string $activityType, string $message = ''): array
{
    $publicCode = (string) ($order['public_code'] ?? '');
    $phone = (string) ($order['phone'] ?? '');
    $branchName = isset($order['branch_name']) && $order['branch_name'] !== null
        ? trim((string) $order['branch_name'])
        : '';
    $codeLabel = $publicCode !== '' ? $publicCode : '—';

    if ($activityType === 'submitted' || $activityType === 'new_order') {
        return [
            'title' => 'سفارش جدید',
            'body' => $branchName !== ''
                ? 'کد ' . $codeLabel . ' — نماینده: ' . $branchName
                : 'کد ' . $codeLabel . ' — مشتری وب: ' . $phone,
            'type' => 'new_order',
        ];
    }
    if ($activityType === 'payment_proof') {
        return [
            'title' => 'مدارک پرداخت جدید',
            'body' => 'سفارش ' . $codeLabel . ' — ' . $phone,
            'type' => 'payment_proof',
        ];
    }
    if ($activityType === 'payment_proof_update') {
        return [
            'title' => 'به‌روزرسانی مدارک پرداخت',
            'body' => 'سفارش ' . $codeLabel . ' — ' . $phone,
            'type' => 'payment_proof_update',
        ];
    }
    if ($activityType === 'payment_warning_answered') {
        return [
            'title' => 'پاسخ مشتری به هشدار پرداخت',
            'body' => 'سفارش ' . $codeLabel . ' — مدارک جدید ارسال شد',
            'type' => 'payment_warning_answered',
        ];
    }
    if ($activityType === 'order_cancelled' || $activityType === 'cancelled') {
        return [
            'title' => 'لغو سفارش',
            'body' => $message !== '' ? $message : ('سفارش ' . $codeLabel . ' توسط مشتری لغو شد'),
            'type' => 'order_cancelled',
        ];
    }

    return [
        'title' => 'فعالیت سفارش',
        'body' => $message !== '' ? $message : ('سفارش ' . $codeLabel),
        'type' => 'order_activity',
    ];
}

function admin_push_notify_new_order(PDO $pdo, int $orderId): void
{
    admin_push_notify_order_activity($pdo, $orderId, 'submitted');
}

function admin_push_notify_order_message(PDO $pdo, int $orderId, string $senderName, string $body): void
{
    if ($orderId <= 0) {
        return;
    }
    if (!function_exists('orders_get_by_id')) {
        require_once __DIR__ . '/orders.php';
    }
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        return;
    }

    $publicCode = (string) ($order['public_code'] ?? '');
    $preview = mb_strlen($body) > 120 ? mb_substr($body, 0, 117) . '…' : $body;
    $data = [
        'order_id' => (string) $orderId,
        'type' => 'order_chat',
        'public_code' => $publicCode,
    ];

    admin_push_queue_all($pdo, 'پیام سفارش ' . $publicCode, $senderName . ': ' . $preview);

    if (admin_push_service_account_path() === null) {
        return;
    }

    $tokens = admin_push_all_tokens($pdo);
    foreach ($tokens as $token) {
        admin_push_send_to_token(
            $token,
            'پیام سفارش ' . $publicCode,
            $senderName . ': ' . $preview,
            $data,
            $pdo
        );
    }
}

function admin_push_notify_direct_message(PDO $pdo, int $salesUserId, string $senderName, string $body): void
{
    if ($salesUserId <= 0) {
        return;
    }
    $preview = mb_strlen($body) > 120 ? mb_substr($body, 0, 117) . '…' : $body;
    $data = [
        'type' => 'direct_chat',
        'sales_user_id' => (string) $salesUserId,
    ];
    admin_push_queue_all($pdo, 'پیام فروشنده', $senderName . ': ' . $preview);
    if (admin_push_service_account_path() === null) {
        error_log('[admin-push] firebase-service-account.json not found — direct_chat push skipped');
        return;
    }
    $tokens = admin_push_all_tokens($pdo);
    if ($tokens === []) {
        error_log('[admin-push] no admin FCM tokens registered — direct_chat push skipped');
        if (is_file(__DIR__ . '/application-logs.php')) {
            require_once __DIR__ . '/application-logs.php';
            cms_app_log([
                'source_app' => 'backend',
                'severity' => 'INFO',
                'category' => 'push',
                'event_code' => 'push_no_admin_tokens',
                'summary' => 'پیام فروشنده ثبت شد اما توکن پوش ادمین وجود ندارد',
                'metadata' => [
                    'sales_user_id' => $salesUserId,
                    'type' => 'direct_chat',
                ],
            ]);
        }
        return;
    }
    foreach ($tokens as $token) {
        admin_push_send_to_token(
            $token,
            'پیام فروشنده',
            $senderName . ': ' . $preview,
            $data,
            $pdo
        );
    }
}
