<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_mechanic.php';
require_once dirname(__DIR__) . '/cms/lib/melipayamak.php';
require_once dirname(__DIR__) . '/cms/lib/seller-credit.php';
require_once dirname(__DIR__) . '/cms/lib/mechanic-invoices.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    seller_credit_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $mechanic = mechanic_api_require($pdo);
    $mechanicId = $mechanic['id'];
    $mechanicPhone = (string) ($mechanic['phone'] ?? '');
    $creditPayload = seller_credit_public_payload($pdo, $mechanicId, $mechanicPhone);

    if ($method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? ''));
        if ($action === 'template') {
            $key = trim((string) ($_GET['key'] ?? ''));
            $customerId = (int) ($_GET['customer_id'] ?? 0);
            $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
            $serviceKey = trim((string) ($_GET['service_key'] ?? ''));
            $invoiceId = (int) ($_GET['invoice_id'] ?? 0);

            $owner = 'مشتری گرامی';
            if ($customerId > 0) {
                $c = $pdo->prepare('SELECT name, phone FROM mechanic_customers WHERE id = ? AND mechanic_id = ? LIMIT 1');
                $c->execute([$customerId, $mechanicId]);
                $row = $c->fetch();
                if ($row) {
                    $label = mechanic_customer_label(
                        (string) ($row['name'] ?? ''),
                        (string) ($row['phone'] ?? '')
                    );
                    $owner = $label !== '' ? $label : 'مشتری گرامی';
                }
            }
            $vehicleLabel = 'خودروی شما';
            $kmUrl = '';
            $lastKmLabel = '';
            $kmSmsMeta = null;
            if ($vehicleId > 0) {
                $v = $pdo->prepare(
                    'SELECT * FROM mechanic_vehicles WHERE id = ? AND mechanic_id = ? LIMIT 1'
                );
                $v->execute([$vehicleId, $mechanicId]);
                $row = $v->fetch();
                if ($row) {
                    $vehicleLabel = trim((string) $row['brand'] . ' ' . (string) $row['model']);
                    $token = mechanic_vehicle_ensure_km_token($pdo, $row);
                    if ($token !== '') {
                        $kmUrl = mechanic_km_public_url($token);
                    }
                    $lastKm = mechanic_vehicle_last_km($pdo, $vehicleId, $row);
                    if ($lastKm !== null) {
                        if (!function_exists('cms_to_persian_digits')) {
                            require_once dirname(__DIR__) . '/cms/lib/jalali.php';
                        }
                        $lastKmLabel = cms_to_persian_digits((string) $lastKm);
                    }
                    $sentAt = $row['km_sms_sent_at'] !== null ? (string) $row['km_sms_sent_at'] : null;
                    $kmSmsMeta = [
                        'cooldown' => mechanic_km_sms_cooldown_active($sentAt),
                        'sent_at' => $sentAt,
                        'last_km' => $lastKm,
                    ];
                }
            }
            $serviceLabel = '';
            $catalogService = mechanic_catalog_service($serviceKey);
            if ($catalogService !== null) {
                $serviceLabel = $catalogService['label'];
            } elseif ($serviceKey !== '' && $vehicleId > 0) {
                $sl = $pdo->prepare(
                    'SELECT service_label FROM mechanic_service_records
                     WHERE vehicle_id = ? AND mechanic_id = ? AND service_key = ?
                     ORDER BY id DESC LIMIT 1'
                );
                $sl->execute([$vehicleId, $mechanicId, $serviceKey]);
                $slRow = $sl->fetch();
                if ($slRow) {
                    $serviceLabel = trim((string) ($slRow['service_label'] ?? ''));
                }
            }

            $invoiceUrl = '';
            if ($invoiceId > 0) {
                $invStmt = $pdo->prepare(
                    'SELECT public_token FROM mechanic_invoices WHERE id = ? AND mechanic_id = ? LIMIT 1'
                );
                $invStmt->execute([$invoiceId, $mechanicId]);
                $invRow = $invStmt->fetch();
                if ($invRow) {
                    $invoiceUrl = mechanic_invoice_public_url((string) $invRow['public_token']);
                }
            }

            $templateUrl = $key === 'km_update' ? $kmUrl : $invoiceUrl;
            $text = mechanic_sms_template($key, [
                'owner' => $owner,
                'workshop' => $mechanic['workshop_name'],
                'city' => $mechanic['city'],
                'vehicle' => $vehicleLabel,
                'service' => $serviceLabel,
                'url' => $templateUrl,
                'phone' => $mechanicPhone,
                'last_km' => $lastKmLabel,
            ]);
            api_json([
                'ok' => true,
                'text' => $text,
                'credit' => $creditPayload,
                'send_window' => mechanic_sms_send_window(),
                'km_sms' => $kmSmsMeta,
            ]);
        }

        $list = $pdo->prepare(
            'SELECT * FROM mechanic_sms_log WHERE mechanic_id = ? ORDER BY id DESC LIMIT 100'
        );
        $list->execute([$mechanicId]);
        $items = [];
        foreach ($list->fetchAll() ?: [] as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'phone' => (string) $row['phone'],
                'template_key' => (string) $row['template_key'],
                'body' => (string) $row['body'],
                'status' => (string) $row['status'],
                'created_at' => $row['created_at'],
            ];
        }
        api_json(['ok' => true, 'items' => $items, 'credit' => $creditPayload]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = mechanic_api_request_json();
    $customerId = (int) ($body['customer_id'] ?? 0);
    $vehicleId = isset($body['vehicle_id']) ? (int) $body['vehicle_id'] : null;
    $templateKey = trim((string) ($body['template_key'] ?? 'custom'));
    $text = trim((string) ($body['text'] ?? ''));
    $invoiceId = (int) ($body['invoice_id'] ?? 0);
    $recordId = (int) ($body['record_id'] ?? 0);

    if ($text === '') {
        api_error('متن پیام خالی است', 400);
    }

    $custStmt = $pdo->prepare('SELECT phone FROM mechanic_customers WHERE id = ? AND mechanic_id = ? LIMIT 1');
    $custStmt->execute([$customerId, $mechanicId]);
    $customer = $custStmt->fetch();
    if (!$customer || !$customer['phone']) {
        api_error('شماره موبایل مشتری ثبت نشده است', 400);
    }

    $text = mechanic_sms_with_shop_phone($text, $mechanicPhone);

    if ($templateKey === 'km_update') {
        if ($vehicleId === null || $vehicleId <= 0) {
            api_error('خودرو نامعتبر است', 400);
        }
        $vehStmt = $pdo->prepare(
            'SELECT km_sms_sent_at FROM mechanic_vehicles WHERE id = ? AND mechanic_id = ? LIMIT 1'
        );
        $vehStmt->execute([$vehicleId, $mechanicId]);
        $vehRow = $vehStmt->fetch();
        if (!$vehRow) {
            api_error('خودرو یافت نشد', 404);
        }
        $sentAt = $vehRow['km_sms_sent_at'] !== null ? (string) $vehRow['km_sms_sent_at'] : null;
        if (mechanic_km_sms_cooldown_active($sentAt)) {
            api_error('درخواست به‌روزرسانی کیلومتر در ۳۰ روز گذشته ارسال شده است.', 400);
        }
    }

    mechanic_sms_require_send_window();

    $segments = seller_credit_sms_segments($text);
    if ($segments < 1) {
        api_error('متن پیام خالی است', 400);
    }

    $canSend = seller_credit_can_send_sms($pdo, $mechanicId, $mechanicPhone, $segments);
    if (!$canSend['ok']) {
        api_error($canSend['error'] ?? 'موجودی اعتبار کافی نیست', 402);
    }
    $smsCost = (int) ($canSend['total_cost'] ?? 0);

    $result = cms_sms_send((string) $customer['phone'], $text);

    $pdo->prepare(
        'INSERT INTO mechanic_sms_log (mechanic_id, vehicle_id, customer_id, phone, template_key, body, status, error)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $mechanicId,
        $vehicleId,
        $customerId,
        (string) $customer['phone'],
        $templateKey,
        $text,
        $result['ok'] ? 'sent' : 'failed',
        $result['ok'] ? null : ($result['error'] ?? null),
    ]);
    $smsLogId = (int) $pdo->lastInsertId();

    if (!$result['ok']) {
        api_error($result['error'] ?? 'ارسال پیامک ناموفق بود', 502);
    }

    if ($smsCost > 0) {
        seller_credit_debit_sms($pdo, $mechanicId, $mechanicPhone, $smsCost, $smsLogId > 0 ? $smsLogId : null);
    }

    if ($invoiceId > 0 && $templateKey === 'invoice') {
        $pdo->prepare(
            'UPDATE mechanic_invoices SET sms_sent_at = NOW()
             WHERE id = ? AND mechanic_id = ?'
        )->execute([$invoiceId, $mechanicId]);
    }

    if ($recordId > 0 && $templateKey === 'recall') {
        $pdo->prepare(
            'UPDATE mechanic_service_records SET sms_sent_at = NOW()
             WHERE id = ? AND mechanic_id = ?'
        )->execute([$recordId, $mechanicId]);
    }

    if ($templateKey === 'km_update' && $vehicleId !== null && $vehicleId > 0) {
        $pdo->prepare(
            'UPDATE mechanic_vehicles SET km_sms_sent_at = NOW()
             WHERE id = ? AND mechanic_id = ?'
        )->execute([$vehicleId, $mechanicId]);
    }

    $creditAfter = seller_credit_public_payload($pdo, $mechanicId, $mechanicPhone);
    api_json([
        'ok' => true,
        'credit' => $creditAfter,
        'segments' => $segments,
        'charged' => $smsCost,
    ]);
} catch (Throwable $e) {
    error_log('[mechanic-sms] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (
        strpos($msg, 'خالی') !== false
        || strpos($msg, 'ثبت نشده') !== false
        || strpos($msg, 'موجودی') !== false
        || strpos($msg, '۳۰ روز') !== false
        || strpos($msg, 'خودرو') !== false
    ) {
        api_error($msg, 400);
    }
    if (strpos($msg, 'ساعت') !== false) {
        api_error($msg, 403);
    }
    api_error('خطای سرور', 500);
}
