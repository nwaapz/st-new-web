<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_mechanic.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }
    if ($method !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $mechanic = mechanic_api_require($pdo);
    $mechanicId = $mechanic['id'];

    $customerCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM mechanic_customers WHERE mechanic_id = ' . (int) $mechanicId
    )->fetchColumn();

    $vehicleCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM mechanic_vehicles WHERE mechanic_id = ' . (int) $mechanicId
    )->fetchColumn();

    $todayStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM mechanic_service_records WHERE mechanic_id = ? AND performed_at = CURDATE()'
    );
    $todayStmt->execute([$mechanicId]);
    $todayCount = (int) $todayStmt->fetchColumn();

    $inactiveStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM mechanic_customers
         WHERE mechanic_id = ? AND (last_visit_at IS NULL OR last_visit_at < DATE_SUB(CURDATE(), INTERVAL 6 MONTH))'
    );
    $inactiveStmt->execute([$mechanicId]);
    $inactiveCount = (int) $inactiveStmt->fetchColumn();

    $reminders = mechanic_active_reminders($pdo, $mechanicId);
    $redCount = 0;
    $yellowCount = 0;
    foreach ($reminders as $r) {
        if ($r['status'] === 'red') {
            $redCount++;
        } elseif ($r['status'] === 'yellow') {
            $yellowCount++;
        }
    }

    $recallList = array_values(array_filter($reminders, fn($r) => $r['status'] !== 'green'));
    $recallList = array_slice($recallList, 0, 20);

    api_json([
        'ok' => true,
        'mechanic' => [
            'workshop_name' => $mechanic['workshop_name'],
            'city' => $mechanic['city'],
        ],
        'cards' => [
            'customers' => $customerCount,
            'vehicles' => $vehicleCount,
            'today_visits' => $todayCount,
            'near_due' => $yellowCount,
            'overdue' => $redCount,
            'inactive_customers' => $inactiveCount,
        ],
        'recall_list' => $recallList,
    ]);
} catch (Throwable $e) {
    error_log('[mechanic-dashboard] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
