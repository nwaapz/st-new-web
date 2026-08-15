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
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $mechanic = mechanic_api_require($pdo);
    $mechanicId = $mechanic['id'];

    if ($method === 'GET') {
        $vehicleId = isset($_GET['vehicle_id']) ? (int) $_GET['vehicle_id'] : 0;
        if ($vehicleId <= 0) {
            api_error('خودرو نامعتبر است', 400);
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM mechanic_service_records
             WHERE vehicle_id = ? AND mechanic_id = ?
             ORDER BY performed_at DESC, id DESC'
        );
        $stmt->execute([$vehicleId, $mechanicId]);
        $items = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'service_key' => (string) $row['service_key'],
                'service_label' => (string) $row['service_label'],
                'performed_at' => $row['performed_at'],
                'km_at_service' => $row['km_at_service'] !== null ? (int) $row['km_at_service'] : null,
                'next_due_at' => $row['next_due_at'],
                'next_due_km' => $row['next_due_km'] !== null ? (int) $row['next_due_km'] : null,
            ];
        }
        api_json(['ok' => true, 'items' => $items]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = mechanic_api_request_json();
    $action = trim((string) ($body['action'] ?? 'create'));

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        $pdo->prepare('DELETE FROM mechanic_service_records WHERE id = ? AND mechanic_id = ?')->execute([$id, $mechanicId]);
        $pdo->prepare(
            'DELETE p FROM mechanic_service_parts p
             LEFT JOIN mechanic_service_records r ON r.id = p.service_record_id
             WHERE r.id IS NULL'
        )->execute();
        api_json(['ok' => true]);
    }

    if ($action !== 'create') {
        api_error('عملیات نامعتبر', 400);
    }

    $vehicleId = (int) ($body['vehicle_id'] ?? 0);
    $vehStmt = $pdo->prepare('SELECT * FROM mechanic_vehicles WHERE id = ? AND mechanic_id = ? LIMIT 1');
    $vehStmt->execute([$vehicleId, $mechanicId]);
    $vehicle = $vehStmt->fetch();
    if (!$vehicle) {
        api_error('خودرو یافت نشد', 404);
    }

    $serviceKey = trim((string) ($body['service_key'] ?? ''));
    $customLabel = trim((string) ($body['service_label'] ?? ''));
    $catalogService = mechanic_catalog_service($serviceKey);
    $isCustom = mechanic_is_custom_service_key($serviceKey);
    if ($catalogService === null && !$isCustom) {
        api_error('نوع خدمت نامعتبر است', 400);
    }
    if ($isCustom) {
        if ($customLabel === '') {
            api_error('نام خدمت را وارد کنید', 400);
        }
        if (function_exists('mb_strlen') && mb_strlen($customLabel) > 80) {
            $customLabel = mb_substr($customLabel, 0, 80);
        } elseif (strlen($customLabel) > 240) {
            $customLabel = substr($customLabel, 0, 240);
        }
        $serviceKey = mechanic_custom_service_key($customLabel);
    }
    $serviceLabel = $catalogService !== null ? (string) $catalogService['label'] : $customLabel;

    $performedAt = trim((string) ($body['performed_at'] ?? ''));
    if ($performedAt === '') {
        $performedAt = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $performedAt)) {
        api_error('تاریخ سرویس نامعتبر است', 400);
    }

    $kmRaw = isset($body['km_at_service']) ? trim((string) $body['km_at_service']) : '';
    if ($kmRaw === '' || !preg_match('/^\d+$/', $kmRaw)) {
        api_error('کیلومتر خودرو را وارد کنید', 400);
    }
    $km = max(0, (int) $kmRaw);
    if ($km > 3000000) {
        $km = 3000000;
    }
    mechanic_km_assert_not_lower($pdo, $vehicleId, $km, $vehicle);

    $laborCost = isset($body['labor_cost']) && $body['labor_cost'] !== '' ? max(0, (int) $body['labor_cost']) : null;
    $partsCost = isset($body['parts_cost']) && $body['parts_cost'] !== '' ? max(0, (int) $body['parts_cost']) : null;
    $notes = trim((string) ($body['notes'] ?? ''));

    if ($isCustom) {
        $intervalRaw = isset($body['custom_interval_km']) ? trim((string) $body['custom_interval_km']) : '';
        $intervalKm = null;
        if ($intervalRaw !== '' && preg_match('/^\d+$/', $intervalRaw)) {
            $intervalKm = min(500000, max(0, (int) $intervalRaw));
            if ($intervalKm === 0) {
                $intervalKm = null;
            }
        }
        $suggestion = mechanic_custom_suggest_next_due($performedAt, $km, $intervalKm);
    } else {
        $suggestion = mechanic_catalog_suggest_next_due($serviceKey, $performedAt, $km);
    }
    $nextDueAt = isset($body['next_due_at']) && trim((string) $body['next_due_at']) !== ''
        ? trim((string) $body['next_due_at'])
        : $suggestion['next_due_at'];
    $nextDueKm = isset($body['next_due_km']) && $body['next_due_km'] !== ''
        ? max(0, (int) $body['next_due_km'])
        : $suggestion['next_due_km'];

    $parts = is_array($body['parts'] ?? null) ? $body['parts'] : [];

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO mechanic_service_records
             (mechanic_id, vehicle_id, customer_id, service_key, service_label, performed_at,
              km_at_service, labor_cost, parts_cost, notes, next_due_at, next_due_km)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $mechanicId,
            $vehicleId,
            (int) $vehicle['customer_id'],
            $serviceKey,
            $serviceLabel,
            $performedAt,
            $km,
            $laborCost,
            $partsCost,
            $notes !== '' ? $notes : null,
            $nextDueAt,
            $nextDueKm,
        ]);
        $recordId = (int) $pdo->lastInsertId();

        if ($parts !== []) {
            $partIns = $pdo->prepare(
                'INSERT INTO mechanic_service_parts (service_record_id, part_name, part_brand, quantity)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($parts as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $partName = trim((string) ($part['part_name'] ?? ''));
                if ($partName === '') {
                    continue;
                }
                $partBrand = trim((string) ($part['part_brand'] ?? ''));
                $quantity = isset($part['quantity']) ? max(1, (int) $part['quantity']) : 1;
                $partIns->execute([$recordId, $partName, $partBrand !== '' ? $partBrand : null, $quantity]);
            }
        }

        // Bump vehicle km/last-visit if this service is newer/higher.
        $vehCurrentKm = $vehicle['current_km'] !== null ? (int) $vehicle['current_km'] : null;
        $newKm = ($vehCurrentKm === null || $km >= $vehCurrentKm) ? $km : $vehCurrentKm;
        $pdo->prepare('UPDATE mechanic_vehicles SET current_km = ?, last_visit_at = ? WHERE id = ?')
            ->execute([$newKm, $performedAt, $vehicleId]);
        mechanic_km_record_reading($pdo, $vehicleId, $km, 'service', $performedAt . ' 12:00:00');

        $custStmt = $pdo->prepare('SELECT first_visit_at, last_visit_at FROM mechanic_customers WHERE id = ? LIMIT 1');
        $custStmt->execute([(int) $vehicle['customer_id']]);
        $cust = $custStmt->fetch();
        $firstVisit = ($cust && $cust['first_visit_at']) ? $cust['first_visit_at'] : $performedAt;
        $lastVisit = ($cust && $cust['last_visit_at']) ? (string) $cust['last_visit_at'] : '';
        if ($lastVisit !== $performedAt) {
            $pdo->prepare(
                'UPDATE mechanic_customers
                 SET visit_count = visit_count + 1, last_visit_at = ?, first_visit_at = COALESCE(first_visit_at, ?)
                 WHERE id = ?'
            )->execute([$performedAt, $firstVisit, (int) $vehicle['customer_id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    api_json([
        'ok' => true,
        'id' => $recordId,
        'next_due_at' => $nextDueAt,
        'next_due_km' => $nextDueKm,
    ]);
} catch (Throwable $e) {
    error_log('[mechanic-service-records] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (strpos($msg, 'نامعتبر') !== false || strpos($msg, 'کیلومتر') !== false) {
        api_error($msg, 400);
    }
    api_error('خطای سرور', 500);
}
