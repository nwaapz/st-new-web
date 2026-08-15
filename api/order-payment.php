<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/orders.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    orders_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_error('برای ارسال مدارک پرداخت وارد شوید', 401);
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $isMultipart = strpos($contentType, 'multipart/form-data') !== false;

    $orderId = 0;
    $note = '';
    /** @var list<string> $keepFiles */
    $keepFiles = [];
    /** @var list<array<string, mixed>> $uploadFiles */
    $uploadFiles = [];

    if ($isMultipart) {
        $orderId = (int) ($_POST['order_id'] ?? $_POST['id'] ?? 0);
        $note = isset($_POST['note']) ? trim((string) $_POST['note']) : '';
        $keepRaw = isset($_POST['keep_files']) ? (string) $_POST['keep_files'] : '[]';
        $decodedKeep = json_decode($keepRaw, true);
        if (is_array($decodedKeep)) {
            foreach ($decodedKeep as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $keepFiles[] = trim($item);
                }
            }
        }

        // Support files / files[] / file field names from FormData.
        $bag = null;
        foreach (['files', 'files[]', 'file'] as $key) {
            if (isset($_FILES[$key]) && is_array($_FILES[$key])) {
                $bag = $_FILES[$key];
                break;
            }
        }
        if ($bag !== null) {
            if (is_array($bag['name'] ?? null)) {
                $count = count($bag['name']);
                for ($i = 0; $i < $count; $i++) {
                    $err = (int) ($bag['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                    if ($err === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $uploadFiles[] = [
                        'name' => (string) ($bag['name'][$i] ?? ''),
                        'type' => (string) ($bag['type'][$i] ?? ''),
                        'tmp_name' => (string) ($bag['tmp_name'][$i] ?? ''),
                        'error' => $err,
                        'size' => (int) ($bag['size'][$i] ?? 0),
                    ];
                }
            } else {
                $err = (int) ($bag['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($err !== UPLOAD_ERR_NO_FILE) {
                    $uploadFiles[] = $bag;
                }
            }
        }
    } else {
        $body = site_auth_request_json();
        $orderId = isset($body['order_id']) ? (int) $body['order_id'] : (isset($body['id']) ? (int) $body['id'] : 0);
        $note = isset($body['note']) ? trim((string) $body['note']) : '';
        if (isset($body['keep_files']) && is_array($body['keep_files'])) {
            foreach ($body['keep_files'] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $keepFiles[] = trim($item);
                }
            }
        }
    }

    if ($orderId <= 0) {
        api_error('سفارش نامعتبر است', 400);
    }

    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null || (int) $order['user_id'] !== (int) $user['id']) {
        api_error('سفارش یافت نشد', 404);
    }

    $status = (string) $order['status'];
    if (!in_array($status, ['accepted', 'payment_proof_sent'], true)) {
        api_error('در این وضعیت امکان ارسال مدارک پرداخت نیست', 400);
    }

    $existing = orders_payment_files_list($order);
    $existingSet = array_fill_keys($existing, true);

    $kept = [];
    foreach ($keepFiles as $path) {
        if (isset($existingSet[$path])) {
            $kept[] = $path;
        }
    }
    $kept = array_values(array_unique($kept));

    $max = orders_payment_files_max();
    $slotsLeft = $max - count($kept);
    if ($slotsLeft < 0) {
        api_error('حداکثر ۹ تصویر برای هر سفارش مجاز است', 400);
    }
    if (count($uploadFiles) > $slotsLeft) {
        api_error('حداکثر ۹ تصویر برای هر سفارش مجاز است', 400);
    }

    $newPaths = [];
    try {
        foreach ($uploadFiles as $file) {
            $newPaths[] = orders_save_payment_upload($file);
        }
    } catch (Throwable $e) {
        foreach ($newPaths as $path) {
            orders_delete_upload_file($path);
        }
        throw $e;
    }

    $finalFiles = array_values(array_unique(array_merge($kept, $newPaths)));
    if (count($finalFiles) > $max) {
        foreach ($newPaths as $path) {
            orders_delete_upload_file($path);
        }
        api_error('حداکثر ۹ تصویر برای هر سفارش مجاز است', 400);
    }

    $finalNote = $note;
    if ($finalNote === '' && $finalFiles === []) {
        foreach ($newPaths as $path) {
            orders_delete_upload_file($path);
        }
        api_error('متن یا حداقل یک تصویر مدارک پرداخت الزامی است', 400);
    }

    $toDelete = [];
    foreach ($existing as $path) {
        if (!in_array($path, $finalFiles, true)) {
            $toDelete[] = $path;
        }
    }

    $hadOpenWarning = false;
    $warnState = isset($order['payment_warning_state'])
        ? trim((string) $order['payment_warning_state'])
        : '';
    $warnText = isset($order['payment_warning']) ? trim((string) $order['payment_warning']) : '';
    if ($warnState === 'open' || ($warnState === '' && $warnText !== '')) {
        $hadOpenWarning = true;
    }

    $encoded = orders_payment_files_encode($finalFiles);
    $legacyFirst = $finalFiles[0] ?? null;

    $pdo->beginTransaction();
    try {
        $nextStatus = $status === 'accepted' ? 'payment_proof_sent' : $status;
        if ($hadOpenWarning) {
            $stmt = $pdo->prepare(
                "UPDATE orders
                 SET payment_note = ?,
                     payment_file = ?,
                     payment_files = ?,
                     payment_warning_state = 'answered',
                     payment_submitted_at = CURRENT_TIMESTAMP,
                     status = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                $finalNote !== '' ? $finalNote : null,
                $legacyFirst,
                $encoded,
                $nextStatus,
                $orderId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE orders
                 SET payment_note = ?,
                     payment_file = ?,
                     payment_files = ?,
                     payment_submitted_at = CURRENT_TIMESTAMP,
                     status = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $finalNote !== '' ? $finalNote : null,
                $legacyFirst,
                $encoded,
                $nextStatus,
                $orderId,
            ]);
        }

        if ($status === 'accepted') {
            orders_add_event(
                $pdo,
                $orderId,
                'accepted',
                'payment_proof_sent',
                'client',
                'مدارک پرداخت توسط مشتری ارسال شد'
            );
        } elseif ($hadOpenWarning) {
            orders_add_event(
                $pdo,
                $orderId,
                'payment_proof_sent',
                'payment_proof_sent',
                'client',
                'مشتری به آخرین هشدار با مدارک زیر پاسخ داد'
            );
        } elseif ($status === 'payment_proof_sent') {
            orders_add_event(
                $pdo,
                $orderId,
                'payment_proof_sent',
                'payment_proof_sent',
                'client',
                'مدارک پرداخت توسط مشتری به‌روزرسانی شد'
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($newPaths as $path) {
            orders_delete_upload_file($path);
        }
        throw $e;
    }

    foreach ($toDelete as $path) {
        orders_delete_upload_file($path);
    }

    $fresh = orders_get_by_id($pdo, $orderId);
    if ($fresh === null) {
        api_error('خطا در ذخیره مدارک', 500);
    }

    api_json([
        'ok' => true,
        'order' => orders_serialize(
            $fresh,
            orders_fetch_items($pdo, $orderId),
            orders_fetch_events($pdo, $orderId)
        ),
    ]);
} catch (Throwable $e) {
    error_log('[order-payment] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (
        strpos($msg, 'فقط') === 0
        || strpos($msg, 'حداکثر') === 0
        || strpos($msg, 'آپلود') === 0
        || strpos($msg, 'فایل') === 0
        || strpos($msg, 'پوشه') === 0
        || strpos($msg, 'ذخیره') === 0
    ) {
        api_error($msg, 400);
    }
    api_error('خطای سرور', 500);
}
