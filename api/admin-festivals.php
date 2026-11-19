<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_admin_auth.php';
require_once dirname(__DIR__) . '/cms/lib/festivals.php';

admin_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    festivals_ensure_schema($pdo);
    $admin = admin_auth_require_user($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $campaign = festivals_get($pdo, $id);
            if ($campaign === null) {
                api_error('جشنواره یافت نشد', 404);
            }
            $participantList = festivals_admin_participants($pdo, $id, [
                'page' => max(1, (int) ($_GET['participant_page'] ?? 1)),
                'page_size' => min(50, max(1, (int) ($_GET['participant_page_size'] ?? 50))),
                'q' => trim((string) ($_GET['participant_q'] ?? '')),
            ]);
            $campaign['participants'] = $participantList['items'];
            $campaign['participants_total'] = $participantList['total'];
            $campaign['participants_page'] = $participantList['page'];
            $campaign['participants_total_pages'] = $participantList['total_pages'];
            api_json(['ok' => true, 'campaign' => $campaign]);
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = festivals_list($pdo, [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
        ], $page);
        api_json([
            'ok' => true,
            'campaigns' => $list['items'],
            'total' => $list['total'],
            'page' => $list['page'],
            'total_pages' => $list['total_pages'],
        ]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = admin_auth_request_json();
    if ($body === [] && $_POST !== []) {
        $body = $_POST;
        if (isset($body['tiers_json']) && is_string($body['tiers_json'])) {
            $decoded = json_decode($body['tiers_json'], true);
            if (is_array($decoded)) {
                $body['tiers'] = $decoded;
            }
        }
    }
    $action = trim((string) ($body['action'] ?? 'save'));
    if (in_array($action, ['mark_gift', 'unmark_gift'], true)) {
        $participant = festivals_mark_tier_gift(
            $pdo,
            (int) ($body['id'] ?? $body['festival_id'] ?? 0),
            (int) ($body['sales_user_id'] ?? 0),
            (int) ($body['tier_id'] ?? 0),
            $action === 'mark_gift',
            (int) ($admin['id'] ?? 0)
        );
        api_json(['ok' => true, 'participant' => $participant]);
    }
    if (in_array($action, ['pause', 'resume', 'end', 'archive', 'duplicate', 'delete'], true)) {
        $result = festivals_action(
            $pdo,
            (int) ($body['id'] ?? 0),
            $action,
            (int) ($body['version'] ?? 0)
        );
        api_json(['ok' => true, 'campaign' => $result]);
    }

    $saved = festivals_save($pdo, $body);
    api_json(['ok' => true, 'campaign' => $saved], ((int) ($body['id'] ?? 0) > 0) ? 200 : 201);
} catch (FestivalException $e) {
    festivals_emit_api_error($e);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[admin-festivals] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
