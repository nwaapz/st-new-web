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
    cms_audit_settings($pdo, 'اپ ادمین — نمایش قیمت مشتری');

    api_json([
        'ok' => true,
        'show_prices' => $showPrices,
        'admin' => $admin['username'] ?? '',
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-shop-price-mode] ' . $e->getMessage());
    api_error('خطای سرور', 500);
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
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            api_error('show_prices نامعتبر است', 400);
        }
        return $parsed;
    }
    api_error('show_prices نامعتبر است', 400);
}
