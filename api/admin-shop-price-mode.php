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
        api_json(admin_shop_price_mode_payload());
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();

    $changedClient = false;
    $changedWebsite = false;
    $showClientValue = false;
    $showWebsiteValue = false;

    if (array_key_exists('show_prices', $body) && $body['show_prices'] !== null) {
        $target = isset($body['price_target']) ? trim((string) $body['price_target']) : 'client';
        $value = admin_shop_price_mode_bool($body['show_prices']);
        if ($target === 'website') {
            $changedWebsite = true;
            $showWebsiteValue = $value;
        } else {
            $changedClient = true;
            $showClientValue = $value;
        }
    }

    if (!$changedClient && !$changedWebsite && array_key_exists('show_website_prices', $body) && $body['show_website_prices'] !== null) {
        $changedWebsite = true;
        $showWebsiteValue = admin_shop_price_mode_bool($body['show_website_prices']);
    }

    if (!$changedClient && !$changedWebsite) {
        api_error('show_prices الزامی است', 400);
    }

    if ($changedWebsite) {
        cms_client_app_call_for_price_pin_if_unset();
        cms_setting_set('call_for_price', $showWebsiteValue ? '0' : '1');
    }
    if ($changedClient) {
        cms_setting_set('client_app_call_for_price', $showClientValue ? '0' : '1');
    }

    $auditLabel = $changedClient && $changedWebsite
        ? 'اپ ادمین — نمایش قیمت مشتری و وب‌سایت'
        : ($changedWebsite
            ? 'اپ ادمین — نمایش قیمت وب‌سایت'
            : 'اپ ادمین — نمایش قیمت اپ مشتری');
    try {
        cms_audit_settings($pdo, $auditLabel);
    } catch (Throwable $auditError) {
        error_log('[admin-shop-price-mode] audit ' . $auditError->getMessage());
    }

    api_json(admin_shop_price_mode_payload());
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-shop-price-mode] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    api_json([
        'ok' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'خطای سرور',
        'exception' => get_class($e),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], 500);
}

function admin_shop_price_mode_payload(): array
{
    return [
        'ok' => true,
        'show_prices' => !cms_client_app_call_for_price_enabled(),
        'show_website_prices' => !cms_call_for_price_enabled(),
    ];
}

function admin_shop_price_mode_bool($value): bool
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
