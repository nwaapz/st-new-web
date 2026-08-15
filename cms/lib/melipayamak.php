<?php
declare(strict_types=1);

/**
 * Melipayamak SendSimpleSMS helper for CMS / future OTP.
 * Credentials live in site_settings (edited via sms-settings.php) — never expose via public APIs.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

/** Prefill-only defaults when CMS fields are empty (password never defaulted). */
const CMS_SMS_DEFAULT_USERNAME = '09121777039';
const CMS_SMS_DEFAULT_FROM = '20001390';
const CMS_SMS_WSDL = 'http://api.payamak-panel.com/post/Send.asmx?wsdl';

function cms_sms_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '98') === 0 && strlen($digits) >= 12) {
        $digits = '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

/**
 * @return array{enabled: bool, username: string, password: string, from: string}
 */
function cms_sms_config(): array
{
    $username = trim(cms_setting_get('sms_username', ''));
    $from = trim(cms_setting_get('sms_from', ''));
    if ($username === '') {
        $username = CMS_SMS_DEFAULT_USERNAME;
    }
    if ($from === '') {
        $from = CMS_SMS_DEFAULT_FROM;
    }

    return [
        'enabled' => cms_setting_get('sms_enabled', '0') === '1',
        'username' => $username,
        'password' => (string) cms_setting_get('sms_password', ''),
        'from' => $from,
    ];
}

function cms_sms_password_is_set(): bool
{
    return trim(cms_setting_get('sms_password', '')) !== '';
}

/**
 * Send an SMS via Melipayamak SOAP SendSimpleSMS.
 *
 * @return array{ok: bool, result?: string, error?: string}
 */
function cms_sms_send(string $phone, string $message): array
{
    $phone = cms_sms_normalize_phone($phone);
    $message = trim($message);

    if ($phone === '') {
        return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست'];
    }
    if ($message === '') {
        return ['ok' => false, 'error' => 'متن پیام خالی است'];
    }

    $cfg = cms_sms_config();
    if (!$cfg['enabled']) {
        return ['ok' => false, 'error' => 'ارسال پیامک در CMS غیرفعال است'];
    }
    if ($cfg['username'] === '' || $cfg['password'] === '' || $cfg['from'] === '') {
        return ['ok' => false, 'error' => 'نام کاربری، رمز یا شماره خط ملی‌پیامک تنظیم نشده است'];
    }

    if (!extension_loaded('soap') || !class_exists('SoapClient')) {
        return ['ok' => false, 'error' => 'افزونه PHP SOAP روی سرور فعال نیست'];
    }

    try {
        @ini_set('soap.wsdl_cache_enabled', '0');
        $sms = new SoapClient(CMS_SMS_WSDL, [
            'encoding' => 'UTF-8',
            'connection_timeout' => 15,
            'default_socket_timeout' => 20,
            'exceptions' => true,
        ]);
        $data = [
            'username' => $cfg['username'],
            'password' => $cfg['password'],
            'to' => [$phone],
            'from' => $cfg['from'],
            'text' => $message,
            'isflash' => false,
        ];
        $raw = $sms->SendSimpleSMS($data)->SendSimpleSMSResult;
        // Melipayamak often returns string|array|stdClass — never cast objects with (string).
        if (is_string($raw) || is_numeric($raw)) {
            $resultText = (string) $raw;
        } else {
            $encoded = json_encode($raw, JSON_UNESCAPED_UNICODE);
            $resultText = $encoded !== false ? $encoded : 'sent';
        }

        return ['ok' => true, 'result' => $resultText];
    } catch (Throwable $e) {
        error_log('[cms_sms_send] ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
