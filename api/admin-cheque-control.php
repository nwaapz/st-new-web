<?php

declare(strict_types=1);



require_once __DIR__ . '/_common.php';

require_once __DIR__ . '/_admin_auth.php';

require_once dirname(__DIR__) . '/cms/lib/cheque-control.php';



admin_auth_prepare_cors();



try {

    $pdo = cms_pdo();

    $adminUser = admin_auth_require_user($pdo);



    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

        header('Access-Control-Allow-Headers: Content-Type');

        api_json(['ok' => true]);

    }



    $view = trim((string) ($_GET['view'] ?? $_POST['view'] ?? 'dashboard'));

    $forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';



    if ($method === 'POST') {

        $body = admin_auth_request_json();

        if ($view === '' && isset($body['view'])) {

            $view = trim((string) $body['view']);

        }



        if ($view === 'transition') {

            $chequeId = (int) ($body['cheque_id'] ?? 0);

            if ($chequeId <= 0) {

                api_error('شناسه چک الزامی است', 400);

            }

            $result = cheque_workflow_apply_transition($pdo, $chequeId, $body, $adminUser);

            api_json(['ok' => true] + $result);

        }



        if ($view === 'bulk') {

            $action = trim((string) ($body['action'] ?? ''));

            $chequeIds = $body['cheque_ids'] ?? [];

            if (!is_array($chequeIds) || $chequeIds === []) {

                api_error('لیست چک‌ها الزامی است', 400);

            }

            if ($action === '') {

                api_error('عملیات الزامی است', 400);

            }

            $note = trim((string) ($body['note'] ?? ''));

            $result = cheque_workflow_bulk_transition($pdo, array_map('intval', $chequeIds), $action, $note, $adminUser);

            api_json(['ok' => true] + $result);

        }



        if ($view === 'credit') {

            $result = customer_credit_set_limit($pdo, $body, $adminUser);

            api_json(['ok' => true] + $result);

        }



        if ($view === 'follow_ups') {

            $result = cheque_follow_ups_create($pdo, $body, $adminUser);

            api_json(['ok' => true] + $result);

        }



        api_error('نمای نامعتبر', 400);

    }



    if ($method !== 'GET') {

        api_error('Method not allowed', 405);

    }



    if ($view === 'dashboard') {

        api_json([

            'ok' => true,

            'dashboard' => cheque_control_dashboard_extended($pdo, $forceRefresh),

        ]);

    }



    if ($view === 'list') {

        $filter = trim((string) ($_GET['filter'] ?? 'all'));

        $query = trim((string) ($_GET['q'] ?? ''));

        $page = max(1, (int) ($_GET['page'] ?? 1));

        $perPage = max(1, min(50, (int) ($_GET['per_page'] ?? 20)));

        $result = cheque_control_list($pdo, $filter, $query, $page, $perPage, $forceRefresh);

        api_json([

            'ok' => true,

            'cheques' => $result['cheques'],

            'total' => $result['total'],

            'page' => $result['page'],

            'total_pages' => $result['total_pages'],

        ]);

    }



    if ($view === 'detail') {

        $chequeId = (int) ($_GET['id'] ?? 0);

        if ($chequeId <= 0) {

            api_error('شناسه چک الزامی است', 400);

        }

        $detail = cheque_control_detail($pdo, $chequeId, $forceRefresh);

        if ($detail === null) {

            api_error('چک یافت نشد', 404);

        }

        api_json([

            'ok' => true,

            'detail' => $detail,

        ]);

    }



    if ($view === 'customers') {

        $query = trim((string) ($_GET['q'] ?? ''));

        $page = max(1, (int) ($_GET['page'] ?? 1));

        $perPage = max(1, min(50, (int) ($_GET['per_page'] ?? 20)));

        $result = cheque_control_customers($pdo, $query, $page, $perPage);

        api_json([

            'ok' => true,

            'customers' => $result['customers'],

            'total' => $result['total'],

            'page' => $result['page'],

            'total_pages' => $result['total_pages'],

        ]);

    }



    if ($view === 'customer') {

        $phone = trim((string) ($_GET['id'] ?? ''));

        if ($phone === '') {

            api_error('شماره مشتری الزامی است', 400);

        }

        $detail = cheque_control_customer_detail($pdo, $phone);

        if ($detail === null) {

            api_error('مشتری یافت نشد', 404);

        }

        api_json([

            'ok' => true,

            'customer' => $detail,

        ]);

    }



    if ($view === 'representatives') {

        api_json([

            'ok' => true,

            'representatives' => cheque_control_representatives($pdo),

        ]);

    }



    if ($view === 'representative') {

        $repId = (int) ($_GET['id'] ?? 0);

        if ($repId <= 0) {

            api_error('شناسه نماینده الزامی است', 400);

        }

        $detail = cheque_control_representative_detail($pdo, $repId);

        if ($detail === null) {

            api_error('نماینده یافت نشد', 404);

        }

        api_json([

            'ok' => true,

            'representative' => $detail,

        ]);

    }



    if ($view === 'bank_summary') {

        api_json([

            'ok' => true,

            'banks' => cheque_control_bank_summary($pdo),

        ]);

    }



    if ($view === 'follow_ups') {

        $chequeId = isset($_GET['cheque_id']) ? (int) $_GET['cheque_id'] : null;

        $phone = isset($_GET['customer_phone']) ? trim((string) $_GET['customer_phone']) : null;

        api_json([

            'ok' => true,

            'follow_ups' => cheque_follow_ups_fetch($pdo, $chequeId, $phone),

        ]);

    }



    if ($view === 'reports') {

        api_json([

            'ok' => true,

            'reports' => cheque_control_reports($pdo),

        ]);

    }



    if ($view === 'audit') {

        $chequeId = (int) ($_GET['cheque_id'] ?? 0);

        if ($chequeId <= 0) {

            api_error('شناسه چک الزامی است', 400);

        }

        api_json([

            'ok' => true,

            'audit' => cheque_control_audit($pdo, $chequeId),

        ]);

    }



    api_error('نمای نامعتبر', 400);

} catch (Throwable $e) {

    api_error($e->getMessage(), 500);

}


