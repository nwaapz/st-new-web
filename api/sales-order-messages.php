<?php

declare(strict_types=1);



require_once __DIR__ . '/_common.php';

require_once __DIR__ . '/_sales_auth.php';

require_once dirname(__DIR__) . '/cms/lib/orders.php';

require_once dirname(__DIR__) . '/cms/lib/order-messages.php';



sales_auth_prepare_cors();



try {

    $pdo = cms_pdo();

    orders_ensure_schema($pdo);

    order_messages_ensure_schema($pdo);

    $salesUser = sales_auth_current_user($pdo);

    if ($salesUser === null) {

        api_error('لطفاً وارد حساب کاربری شوید', 401);

    }

    $salesUserId = (int) $salesUser['id'];
    sales_auth_session_release();



    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

        header('Access-Control-Allow-Headers: Content-Type');

        api_json(['ok' => true]);

    }



    $isMultipart = $method === 'POST' && api_request_is_multipart();

    $postJson = null;

    if ($method === 'POST' && !$isMultipart) {

        $postJson = sales_auth_request_json();

    }



    $orderId = api_request_order_id((int) ($postJson['order_id'] ?? 0));

    if ($orderId <= 0) {

        api_error('شناسه سفارش نامعتبر است', 400);

    }



    $order = order_messages_verify_sales_order($pdo, $orderId, $salesUserId);

    if ($order === null) {

        api_error('سفارش یافت نشد', 404);

    }



    if ($method === 'GET') {

        $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

        $wait = isset($_GET['wait']) && (string) $_GET['wait'] === '1';

        order_messages_mark_read_for_sales($pdo, $orderId);



        $messages = $wait

            ? order_messages_wait($pdo, $orderId, $sinceId, (int) ($_GET['timeout'] ?? 25))

            : order_messages_fetch($pdo, $orderId, $sinceId);



        api_json([

            'ok' => true,

            'order_id' => $orderId,

            'messages' => $messages,

            'unread_count' => order_messages_unread_count_for_sales($pdo, $orderId),

        ]);

    }



    if ($method !== 'POST') {

        api_error('Method not allowed', 405);

    }



    $text = '';

    $image = null;

    if ($isMultipart) {

        $text = isset($_POST['body']) ? trim((string) $_POST['body']) : '';

        $image = order_messages_handle_image_upload('image');

        if ($image === null && $text === '') {

            $upload = $_FILES['image'] ?? null;

            if (is_array($upload)) {

                $uploadErr = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

                if ($uploadErr !== UPLOAD_ERR_NO_FILE && $uploadErr !== UPLOAD_ERR_OK) {

                    throw new InvalidArgumentException(order_messages_upload_error_message($uploadErr));

                }

            }

            throw new InvalidArgumentException('تصویر دریافت نشد. دوباره تلاش کنید.');

        }

    } else {

        $text = trim((string) ($postJson['body'] ?? ''));

    }

    $displayName = trim((string) ($salesUser['display_name'] ?? ''));

    if ($displayName === '') {

        $displayName = (string) ($salesUser['username'] ?? 'فروشنده');

    }



    $message = order_messages_post_sales(
        $pdo,
        $orderId,
        $salesUserId,
        $displayName,
        $text,
        $image,
        false
    );

    $preview = order_messages_notify_preview($text, $image);
    api_json_then(
        ['ok' => true, 'message' => $message],
        static function () use ($pdo, $orderId, $displayName, $preview): void {
            order_messages_notify_admins($pdo, $orderId, $displayName, $preview);
        }
    );

} catch (InvalidArgumentException $e) {

    api_error($e->getMessage(), 400);

} catch (Throwable $e) {

    require_once dirname(__DIR__) . '/cms/lib/application-logs.php';

    cms_app_log_exception($e, [

        'category' => 'chat',

        'event_code' => 'chat_send_failed',

        'summary' => 'ارسال پیام سفارش ناموفق بود',

    ]);

    api_error($e->getMessage(), 500);

}

