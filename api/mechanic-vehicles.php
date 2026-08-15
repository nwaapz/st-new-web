<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_mechanic.php';
require_once dirname(__DIR__) . '/cms/lib/mechanic-invoices.php';

site_auth_prepare_cors();

function mechanic_vehicles_serialize(PDO $pdo, array $row): array
{
    $kmExtra = mechanic_vehicle_km_payload($pdo, $row);
    return array_merge([
        'id' => (int) $row['id'],
        'customer_id' => (int) $row['customer_id'],
        'brand' => (string) $row['brand'],
        'model' => (string) $row['model'],
        'trim' => $row['trim'] !== null ? (string) $row['trim'] : '',
        'year' => $row['year'] !== null ? (string) $row['year'] : '',
        'plate' => $row['plate'] !== null ? (string) $row['plate'] : '',
        'vin' => $row['vin'] !== null ? (string) $row['vin'] : '',
        'current_km' => $row['current_km'] !== null ? (int) $row['current_km'] : null,
        'last_visit_at' => $row['last_visit_at'],
        'notes' => $row['notes'] !== null ? (string) $row['notes'] : '',
    ], $kmExtra);
}

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
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare(
                'SELECT v.*, c.name AS customer_name, c.phone AS customer_phone
                 FROM mechanic_vehicles v
                 INNER JOIN mechanic_customers c ON c.id = v.customer_id
                 WHERE v.id = ? AND v.mechanic_id = ? LIMIT 1'
            );
            $stmt->execute([$id, $mechanicId]);
            $row = $stmt->fetch();
            if (!$row) {
                api_error('خودرو یافت نشد', 404);
            }

            $recStmt = $pdo->prepare(
                'SELECT * FROM mechanic_service_records WHERE vehicle_id = ? AND mechanic_id = ?
                 ORDER BY performed_at DESC, id DESC'
            );
            $recStmt->execute([$id, $mechanicId]);
            $records = $recStmt->fetchAll() ?: [];

            $recordIds = array_map(fn($r) => (int) $r['id'], $records);
            $partsByRecord = [];
            if ($recordIds !== []) {
                $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
                $partsStmt = $pdo->prepare(
                    "SELECT * FROM mechanic_service_parts WHERE service_record_id IN ({$placeholders})"
                );
                $partsStmt->execute($recordIds);
                foreach ($partsStmt->fetchAll() ?: [] as $p) {
                    $rid = (int) $p['service_record_id'];
                    $partsByRecord[$rid] ??= [];
                    $partsByRecord[$rid][] = [
                        'part_name' => (string) $p['part_name'],
                        'part_brand' => $p['part_brand'] !== null ? (string) $p['part_brand'] : '',
                        'quantity' => (int) $p['quantity'],
                    ];
                }
            }

            $history = [];
            $stats = mechanic_vehicle_mileage_stats($pdo, $id);
            $avgDay = $stats['ready'] ? $stats['avg_km_per_day'] : null;
            foreach ($records as $r) {
                $status = mechanic_reminder_status(
                    $r['next_due_km'] !== null ? (int) $r['next_due_km'] : null,
                    $row['current_km'] !== null ? (int) $row['current_km'] : null,
                    $r['next_due_at'],
                    $avgDay
                );
                $history[] = [
                    'id' => (int) $r['id'],
                    'service_key' => (string) $r['service_key'],
                    'service_label' => (string) $r['service_label'],
                    'performed_at' => $r['performed_at'],
                    'km_at_service' => $r['km_at_service'] !== null ? (int) $r['km_at_service'] : null,
                    'labor_cost' => $r['labor_cost'] !== null ? (int) $r['labor_cost'] : null,
                    'parts_cost' => $r['parts_cost'] !== null ? (int) $r['parts_cost'] : null,
                    'notes' => $r['notes'] !== null ? (string) $r['notes'] : '',
                    'next_due_at' => $r['next_due_at'],
                    'next_due_km' => $r['next_due_km'] !== null ? (int) $r['next_due_km'] : null,
                    'predicted_due_at' => $status['predicted_due_at'],
                    'parts' => $partsByRecord[(int) $r['id']] ?? [],
                    'status' => $status['status'],
                ];
            }

            $invStmt = $pdo->prepare(
                'SELECT * FROM mechanic_invoices WHERE vehicle_id = ? AND mechanic_id = ?
                 ORDER BY performed_at DESC, id DESC'
            );
            $invStmt->execute([$id, $mechanicId]);
            $invoices = [];
            foreach ($invStmt->fetchAll() ?: [] as $inv) {
                $invoices[] = mechanic_invoice_public_payload($inv);
            }

            api_json([
                'ok' => true,
                'vehicle' => mechanic_vehicles_serialize($pdo, $row),
                'customer' => ['id' => (int) $row['customer_id'], 'name' => (string) $row['customer_name'], 'phone' => $row['customer_phone'] !== null ? (string) $row['customer_phone'] : ''],
                'history' => $history,
                'invoices' => $invoices,
            ]);
        }

        $customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
        $params = [$mechanicId];
        $where = 'v.mechanic_id = ?';
        if ($customerId > 0) {
            $where .= ' AND v.customer_id = ?';
            $params[] = $customerId;
        }
        $stmt = $pdo->prepare(
            "SELECT v.* FROM mechanic_vehicles v WHERE {$where} ORDER BY v.updated_at DESC, v.id DESC LIMIT 300"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $items[] = mechanic_vehicles_serialize($pdo, $row);
        }
        api_json(['ok' => true, 'items' => $items]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = mechanic_api_request_json();
    $action = trim((string) ($body['action'] ?? 'create'));

    if ($action === 'create') {
        $customerId = (int) ($body['customer_id'] ?? 0);
        $check = $pdo->prepare('SELECT id FROM mechanic_customers WHERE id = ? AND mechanic_id = ? LIMIT 1');
        $check->execute([$customerId, $mechanicId]);
        if (!$check->fetch()) {
            api_error('مشتری یافت نشد', 404);
        }

        $brand = trim((string) ($body['brand'] ?? ''));
        $model = trim((string) ($body['model'] ?? ''));
        if ($brand === '' || $model === '') {
            api_error('برند و مدل خودرو را وارد کنید', 400);
        }
        $trimVal = trim((string) ($body['trim'] ?? ''));
        $year = trim((string) ($body['year'] ?? ''));
        $plate = trim((string) ($body['plate'] ?? ''));
        $vin = trim((string) ($body['vin'] ?? ''));
        $currentKm = isset($body['current_km']) && $body['current_km'] !== '' ? max(0, (int) $body['current_km']) : null;
        if ($currentKm !== null && $currentKm > 3000000) {
            $currentKm = 3000000;
        }
        $notes = trim((string) ($body['notes'] ?? ''));
        $token = mechanic_km_new_token();

        $ins = $pdo->prepare(
            'INSERT INTO mechanic_vehicles
             (mechanic_id, customer_id, brand, model, trim, year, plate, vin, current_km, notes, public_km_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $mechanicId, $customerId, $brand, $model,
            $trimVal !== '' ? $trimVal : null,
            $year !== '' ? $year : null,
            $plate !== '' ? $plate : null,
            $vin !== '' ? $vin : null,
            $currentKm,
            $notes !== '' ? $notes : null,
            $token,
        ]);
        $newId = (int) $pdo->lastInsertId();
        if ($currentKm !== null) {
            mechanic_km_record_reading($pdo, $newId, $currentKm, 'mechanic');
        }
        api_json(['ok' => true, 'id' => $newId]);
    }

    if ($action === 'update') {
        $id = (int) ($body['id'] ?? 0);
        $check = $pdo->prepare('SELECT * FROM mechanic_vehicles WHERE id = ? AND mechanic_id = ? LIMIT 1');
        $check->execute([$id, $mechanicId]);
        $existing = $check->fetch();
        if (!$existing) {
            api_error('خودرو یافت نشد', 404);
        }

        $brand = trim((string) ($body['brand'] ?? ''));
        $model = trim((string) ($body['model'] ?? ''));
        if ($brand === '' || $model === '') {
            api_error('برند و مدل خودرو را وارد کنید', 400);
        }
        $trimVal = trim((string) ($body['trim'] ?? ''));
        $year = trim((string) ($body['year'] ?? ''));
        $plate = trim((string) ($body['plate'] ?? ''));
        $vin = trim((string) ($body['vin'] ?? ''));
        $currentKm = isset($body['current_km']) && $body['current_km'] !== '' ? max(0, (int) $body['current_km']) : null;
        if ($currentKm !== null && $currentKm > 3000000) {
            $currentKm = 3000000;
        }
        $notes = trim((string) ($body['notes'] ?? ''));
        if ($currentKm !== null) {
            mechanic_km_assert_not_lower($pdo, $id, $currentKm, $existing);
        }

        $pdo->prepare(
            'UPDATE mechanic_vehicles SET brand = ?, model = ?, trim = ?, year = ?, plate = ?, vin = ?, current_km = ?, notes = ?
             WHERE id = ? AND mechanic_id = ?'
        )->execute([
            $brand, $model,
            $trimVal !== '' ? $trimVal : null,
            $year !== '' ? $year : null,
            $plate !== '' ? $plate : null,
            $vin !== '' ? $vin : null,
            $currentKm,
            $notes !== '' ? $notes : null,
            $id, $mechanicId,
        ]);
        if ($currentKm !== null) {
            mechanic_km_record_reading($pdo, $id, $currentKm, 'mechanic');
        }
        api_json(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        $pdo->prepare('DELETE FROM mechanic_vehicles WHERE id = ? AND mechanic_id = ?')->execute([$id, $mechanicId]);
        api_json(['ok' => true]);
    }

    api_error('عملیات نامعتبر', 400);
} catch (Throwable $e) {
    error_log('[mechanic-vehicles] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (strpos($msg, 'وارد کنید') !== false || strpos($msg, 'کیلومتر') !== false) {
        api_error($msg, 400);
    }
    api_error('خطای سرور', 500);
}
