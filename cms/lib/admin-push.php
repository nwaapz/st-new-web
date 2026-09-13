<?php
declare(strict_types=1);

/**
 * FCM push notifications for CMS admin mobile app.
 */

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

    $ready = true;
}

function admin_push_service_account_path(): ?string
{
    $candidates = [
        dirname(__DIR__, 2) . '/deploy/firebase-service-account.json',
        dirname(__DIR__) . '/cms/firebase-service-account.json',
    ];
    foreach ($candidates as $path) {
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
function admin_push_send_to_token(string $token, string $title, string $body, array $data = []): bool
{
    $projectId = admin_push_firebase_project_id();
    $accessToken = admin_push_fetch_access_token();
    if ($projectId === null || $accessToken === null) {
        return false;
    }

    $payload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
            'android' => [
                'priority' => 'high',
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

    error_log('[admin-push] FCM send failed HTTP ' . $status . ': ' . (is_string($response) ? $response : ''));
    return false;
}

function admin_push_notify_new_order(PDO $pdo, int $orderId): void
{
    if ($orderId <= 0) {
        return;
    }
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        return;
    }

    $publicCode = (string) ($order['public_code'] ?? '');
    $phone = (string) ($order['phone'] ?? '');
    $branchName = isset($order['branch_name']) && $order['branch_name'] !== null
        ? trim((string) $order['branch_name'])
        : '';
    $source = $branchName !== '' ? 'نماینده: ' . $branchName : 'مشتری وب/فروش';

    $title = 'سفارش جدید';
    $body = 'کد ' . $publicCode . ' — ' . $phone . ' (' . $source . ')';
    $data = [
        'order_id' => (string) $orderId,
        'type' => 'new_order',
        'public_code' => $publicCode,
    ];

    $tokens = admin_push_all_tokens($pdo);
    if ($tokens === []) {
        return;
    }

    foreach ($tokens as $token) {
        admin_push_send_to_token($token, $title, $body, $data);
    }
}
