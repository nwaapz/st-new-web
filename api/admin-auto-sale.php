<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/admin-audit.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        api_json(admin_auto_sale_payload());
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    if (!array_key_exists('auto_sale_orders', $body) || $body['auto_sale_orders'] === null) {
        api_error('auto_sale_orders الزامی است', 400);
    }

    $enabled = admin_auto_sale_bool($body['auto_sale_orders']);
    cms_setting_set('auto_sale_orders', $enabled ? '1' : '0');

    try {
        cms_audit_settings($pdo, 'اپ ادمین — فروش خودکار');
    } catch (Throwable $auditError) {
        error_log('[admin-auto-sale] audit ' . $auditError->getMessage());
    }

    api_json(admin_auto_sale_payload());
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-auto-sale] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    api_json([
        'ok' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'خطای سرور',
        'exception' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], 500);
}

function admin_auto_sale_payload(): array
{
    return [
        'ok' => true,
        'auto_sale_orders' => orders_auto_sale_enabled(),
    ];
}

function admin_auto_sale_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
    }
    api_error('auto_sale_orders نامعتبر است', 400);
    return false;
}
