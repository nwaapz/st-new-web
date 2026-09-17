<?php
declare(strict_types=1);

/**
 * Power BI Embedded token helper for admin Android app.
 */

function powerbi_config(): array
{
    $cfg = cms_config();

    return [
        'enabled' => !empty($cfg['powerbi_enabled']),
        'tenant_id' => trim((string) ($cfg['powerbi_tenant_id'] ?? '')),
        'client_id' => trim((string) ($cfg['powerbi_client_id'] ?? '')),
        'client_secret' => trim((string) ($cfg['powerbi_client_secret'] ?? '')),
        'workspace_id' => trim((string) ($cfg['powerbi_workspace_id'] ?? '')),
        'report_id' => trim((string) ($cfg['powerbi_report_id'] ?? '')),
    ];
}

function powerbi_is_configured(): bool
{
    $cfg = powerbi_config();
    if (!$cfg['enabled']) {
        return false;
    }

    return $cfg['tenant_id'] !== ''
        && $cfg['client_id'] !== ''
        && $cfg['client_secret'] !== ''
        && $cfg['workspace_id'] !== ''
        && $cfg['report_id'] !== '';
}

/**
 * @return array{access_token:string,expires_in:int}
 */
function powerbi_aad_token(): array
{
    $cfg = powerbi_config();
    if (!powerbi_is_configured()) {
        throw new RuntimeException('Power BI is not configured on the server');
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode($cfg['tenant_id']) . '/oauth2/v2.0/token';
    $body = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'scope' => 'https://analysis.windows.net/powerbi/api/.default',
    ]);

    $response = powerbi_http_post($url, $body, [
        'Content-Type: application/x-www-form-urlencoded',
    ]);

    if (!isset($response['access_token'])) {
        $msg = isset($response['error_description'])
            ? (string) $response['error_description']
            : 'Azure AD token request failed';
        throw new RuntimeException($msg);
    }

    return [
        'access_token' => (string) $response['access_token'],
        'expires_in' => isset($response['expires_in']) ? (int) $response['expires_in'] : 3600,
    ];
}

/**
 * @return array{embedUrl:string,accessToken:string,expiration:string,reportId:string,workspaceId:string}
 */
function powerbi_embed_config(): array
{
    $cfg = powerbi_config();
    if (!powerbi_is_configured()) {
        throw new RuntimeException('Power BI is not configured on the server');
    }

    $aad = powerbi_aad_token();
    $url = 'https://api.powerbi.com/v1.0/myorg/groups/'
        . rawurlencode($cfg['workspace_id'])
        . '/reports/'
        . rawurlencode($cfg['report_id'])
        . '/GenerateToken';

    $payload = json_encode([
        'accessLevel' => 'View',
        'allowSaveAs' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $response = powerbi_http_post($url, $payload ?: '{}', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $aad['access_token'],
    ]);

    if (!isset($response['token'])) {
        $msg = isset($response['error']['message'])
            ? (string) $response['error']['message']
            : 'Power BI embed token request failed';
        throw new RuntimeException($msg);
    }

    $embedUrl = 'https://app.powerbi.com/reportEmbed?reportId='
        . rawurlencode($cfg['report_id'])
        . '&groupId='
        . rawurlencode($cfg['workspace_id']);

    return [
        'embedUrl' => $embedUrl,
        'accessToken' => (string) $response['token'],
        'expiration' => isset($response['expiration']) ? (string) $response['expiration'] : '',
        'reportId' => $cfg['report_id'],
        'workspaceId' => $cfg['workspace_id'],
    ];
}

/**
 * @param list<string> $headers
 * @return array<string, mixed>
 */
function powerbi_http_post(string $url, string $body, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is required for Power BI embed');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Power BI HTTP error: ' . $err);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Power BI returned invalid JSON (HTTP ' . $status . ')');
    }

    if ($status >= 400) {
        $msg = isset($json['error_description'])
            ? (string) $json['error_description']
            : (isset($json['error']['message']) ? (string) $json['error']['message'] : 'HTTP ' . $status);
        throw new RuntimeException($msg);
    }

    return $json;
}

function powerbi_status_payload(PDO $pdo): array
{
    require_once __DIR__ . '/analytics-orders.php';
    analytics_orders_ensure_schema($pdo);

    $summary = analytics_orders_summary($pdo);
    $configured = powerbi_is_configured();

    return [
        'configured' => $configured,
        'enabled' => powerbi_config()['enabled'],
        'analytics' => $summary,
        'setup_hint' => $configured
            ? null
            : 'Set powerbi_* keys in cms/config.local.php and publish the Orders report to the workspace.',
    ];
}
