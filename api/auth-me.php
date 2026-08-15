<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/branches.php';
require_once dirname(__DIR__) . '/cms/lib/mechanics.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_json(['ok' => true, 'authenticated' => false, 'user' => null]);
    }

    // Keep branch_id in sync if CMS phone mapping changed.
    branches_sync_user_branch($pdo, (int) $user['id'], (string) $user['phone']);

    $payload = branches_auth_user_payload($pdo, (int) $user['id'], (string) $user['phone']);
    $payload = array_merge($payload, mechanics_auth_user_payload($pdo, (int) $user['id']));

    api_json([
        'ok' => true,
        'authenticated' => true,
        'user' => $payload,
    ]);
} catch (Throwable $e) {
    error_log('[auth-me] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
