<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/admin-audit.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    $admin = admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        api_json([
            'ok' => true,
            'show_prices' => !cms_call_for_price_enabled(),
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    if (!array_key_exists('show_prices', $body)) {
        api_error('show_prices الزامی است', 400);
    }
    $showPrices = admin_shop_price_mode_bool($body['show_prices']);
    cms_setting_set('call_for_price', $showPrices ? '0' : '1');
    try {
        cms_audit_settings($pdo, 'اپ ادمین — نمایش قیمت مشتری');
    } catch (Throwable $auditError) {
        error_log('[admin-shop-price-mode] audit ' . $auditError->getMessage());
    }

    api_json([
        'ok' => true,
        'show_prices' => $showPrices,
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-shop-price-mode] ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    api_json([
        'ok' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'خطای سرور',
        'exception' => $e::class,
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], 500);
}

function admin_shop_price_mode_bool(mixed $value): bool
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
    api_error('show_prices نامعتبر است', 400);
    return false;
}
