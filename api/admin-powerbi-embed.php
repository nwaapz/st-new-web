<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/powerbi-embed.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    if (!powerbi_is_configured()) {
        api_json([
            'ok' => false,
            'configured' => false,
            'error' => 'Power BI روی سرور پیکربندی نشده است',
            'status' => powerbi_status_payload($pdo),
        ], 503);
    }

    $embed = powerbi_embed_config();
    api_json([
        'ok' => true,
        'configured' => true,
        'embedUrl' => $embed['embedUrl'],
        'accessToken' => $embed['accessToken'],
        'expiration' => $embed['expiration'],
        'reportId' => $embed['reportId'],
        'workspaceId' => $embed['workspaceId'],
        'status' => powerbi_status_payload($pdo),
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 500);
}
