<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_mechanic.php';
require_once dirname(__DIR__) . '/cms/lib/seller-credit.php';
require_once dirname(__DIR__) . '/cms/lib/mechanic-broadcasts.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    seller_credit_ensure_schema($pdo);
    mechanic_broadcasts_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $mechanic = mechanic_api_require($pdo);
    $mechanicId = (int) $mechanic['id'];
    $mechanicPhone = (string) ($mechanic['phone'] ?? '');
    $phoneCustomers = mechanic_broadcast_phone_customers_count($pdo, $mechanicId);
    $credit = seller_credit_public_payload($pdo, $mechanicId, $mechanicPhone);

    if ($method === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id > 0) {
            $row = mechanic_broadcast_find($pdo, $id, $mechanicId);
            if ($row === null) {
                api_error('پیام گروهی یافت نشد', 404);
            }
            api_json([
                'ok' => true,
                'item' => mechanic_broadcast_serialize($pdo, $row, $phoneCustomers),
                'phone_customers' => $phoneCustomers,
                'credit' => $credit,
                'send_window' => mechanic_sms_send_window(),
            ]);
        }

        api_json([
            'ok' => true,
            'items' => mechanic_broadcast_list($pdo, $mechanicId, $phoneCustomers),
            'phone_customers' => $phoneCustomers,
            'credit' => $credit,
            'send_window' => mechanic_sms_send_window(),
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = mechanic_api_request_json();
    $action = trim((string) ($body['action'] ?? 'save'));
    $id = (int) ($body['id'] ?? 0);

    $row = null;
    if ($id > 0) {
        $row = mechanic_broadcast_find($pdo, $id, $mechanicId);
        if ($row === null) {
            api_error('پیام گروهی یافت نشد', 404);
        }
    }

    if ($action === 'save') {
        $text = (string) ($body['body'] ?? ($row['body'] ?? ''));
        $exemptIds = $body['exempt_ids'] ?? null;
        if ($row === null) {
            $row = mechanic_broadcast_create($pdo, $mechanicId, $text);
        } else {
            mechanic_broadcast_save_body($pdo, $row, $text);
        }
        if (is_array($exemptIds)) {
            $ids = [];
            foreach ($exemptIds as $cid) {
                $ids[] = (int) $cid;
            }
            mechanic_broadcast_set_exempts($pdo, $row, $mechanicId, $ids);
        }
        $fresh = mechanic_broadcast_find($pdo, (int) $row['id'], $mechanicId);
        api_json([
            'ok' => true,
            'item' => mechanic_broadcast_serialize($pdo, $fresh, $phoneCustomers),
            'phone_customers' => $phoneCustomers,
            'credit' => $credit,
        ]);
    }

    if ($action === 'submit') {
        if ($row === null) {
            $text = trim((string) ($body['body'] ?? ''));
            if ($text === '') {
                api_error('متن پیام خالی است', 400);
            }
            $row = mechanic_broadcast_create($pdo, $mechanicId, $text);
            $exemptIds = $body['exempt_ids'] ?? null;
            if (is_array($exemptIds)) {
                $ids = [];
                foreach ($exemptIds as $cid) {
                    $ids[] = (int) $cid;
                }
                mechanic_broadcast_set_exempts($pdo, $row, $mechanicId, $ids);
            }
        } else {
            if (isset($body['body'])) {
                mechanic_broadcast_save_body($pdo, $row, (string) $body['body']);
                $row = mechanic_broadcast_find($pdo, (int) $row['id'], $mechanicId);
            }
            if (isset($body['exempt_ids']) && is_array($body['exempt_ids'])) {
                $ids = [];
                foreach ($body['exempt_ids'] as $cid) {
                    $ids[] = (int) $cid;
                }
                mechanic_broadcast_set_exempts($pdo, $row, $mechanicId, $ids);
            }
        }
        $row = mechanic_broadcast_find($pdo, (int) $row['id'], $mechanicId);
        mechanic_broadcast_submit($pdo, $row);
        $fresh = mechanic_broadcast_find($pdo, (int) $row['id'], $mechanicId);
        api_json([
            'ok' => true,
            'item' => mechanic_broadcast_serialize($pdo, $fresh, $phoneCustomers),
            'phone_customers' => $phoneCustomers,
            'credit' => $credit,
        ]);
    }

    if ($action === 'send_batch') {
        if ($row === null) {
            api_error('پیام گروهی یافت نشد', 404);
        }
        $result = mechanic_broadcast_send_batch($pdo, (int) $row['id'], 20);
        $fresh = mechanic_broadcast_find($pdo, (int) $row['id'], $mechanicId);
        $creditAfter = seller_credit_public_payload($pdo, $mechanicId, $mechanicPhone);
        api_json([
            'ok' => true,
            'batch' => $result,
            'item' => mechanic_broadcast_serialize($pdo, $fresh, $phoneCustomers),
            'phone_customers' => $phoneCustomers,
            'credit' => $creditAfter,
            'send_window' => mechanic_sms_send_window(),
        ]);
    }

    api_error('عملیات نامعتبر', 400);
} catch (Throwable $e) {
    error_log('[mechanic-broadcasts] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (
        strpos($msg, 'خالی') !== false
        || strpos($msg, 'ویرایش') !== false
        || strpos($msg, 'تأیید') !== false
        || strpos($msg, 'مستثنا') !== false
        || strpos($msg, 'یافت نشد') !== false
        || strpos($msg, 'دلیل') !== false
        || strpos($msg, 'ساعت') !== false
    ) {
        api_error($msg, strpos($msg, 'ساعت') !== false ? 403 : 400);
    }
    api_error('خطای سرور', 500);
}
