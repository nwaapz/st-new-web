<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_sales_auth.php';

sales_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    sales_users_ensure_schema($pdo);
    $salesUser = sales_auth_current_user($pdo);
    if ($salesUser === null) {
        api_error('لطفاً وارد حساب کاربری شوید', 401);
    }
    $salesUserId = (int) $salesUser['id'];

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'GET' && $method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    sales_users_touch_last_seen($pdo, $salesUserId);
    $stmt = $pdo->prepare('SELECT last_seen_at FROM sales_users WHERE id = ? LIMIT 1');
    $stmt->execute([$salesUserId]);
    $lastSeen = $stmt->fetchColumn();
    $presence = sales_users_presence_status(
        $lastSeen !== false && $lastSeen !== null ? (string) $lastSeen : null
    );

    api_json([
        'ok' => true,
        'sales_user_id' => $salesUserId,
        'is_online' => $presence['is_online'],
        'last_seen_at' => $presence['last_seen_at'],
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
