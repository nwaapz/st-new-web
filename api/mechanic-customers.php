<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_mechanic.php';

site_auth_prepare_cors();

/**
 * @return array<string, mixed>
 */
function mechanic_customers_serialize(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'phone' => $row['phone'] !== null ? (string) $row['phone'] : '',
        'notes' => $row['notes'] !== null ? (string) $row['notes'] : '',
        'visit_count' => (int) $row['visit_count'],
        'first_visit_at' => $row['first_visit_at'],
        'last_visit_at' => $row['last_visit_at'],
        'vehicle_count' => isset($row['vehicle_count']) ? (int) $row['vehicle_count'] : null,
    ];
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
                'SELECT c.*, (SELECT COUNT(*) FROM mechanic_vehicles v WHERE v.customer_id = c.id) AS vehicle_count
                 FROM mechanic_customers c WHERE c.id = ? AND c.mechanic_id = ? LIMIT 1'
            );
            $stmt->execute([$id, $mechanicId]);
            $row = $stmt->fetch();
            if (!$row) {
                api_error('مشتری یافت نشد', 404);
            }

            $vehStmt = $pdo->prepare(
                'SELECT id, brand, model, trim, year, plate, current_km, last_visit_at,
                        public_km_token, km_sms_sent_at
                 FROM mechanic_vehicles WHERE customer_id = ? AND mechanic_id = ?
                 ORDER BY id DESC'
            );
            $vehStmt->execute([$id, $mechanicId]);
            $vehicles = [];
            foreach ($vehStmt->fetchAll() ?: [] as $v) {
                $kmExtra = mechanic_vehicle_km_payload($pdo, $v);
                $vehicles[] = array_merge([
                    'id' => (int) $v['id'],
                    'brand' => (string) $v['brand'],
                    'model' => (string) $v['model'],
                    'trim' => $v['trim'] !== null ? (string) $v['trim'] : '',
                    'year' => $v['year'] !== null ? (string) $v['year'] : '',
                    'plate' => $v['plate'] !== null ? (string) $v['plate'] : '',
                    'current_km' => $v['current_km'] !== null ? (int) $v['current_km'] : null,
                    'last_visit_at' => $v['last_visit_at'],
                    'customer_id' => $id,
                    'vin' => '',
                    'notes' => '',
                ], $kmExtra);
            }

            api_json([
                'ok' => true,
                'customer' => mechanic_customers_serialize($row),
                'vehicles' => $vehicles,
            ]);
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        $params = [$mechanicId];
        $where = 'c.mechanic_id = ?';
        if ($search !== '') {
            $where .= ' AND (c.name LIKE ? OR c.phone LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $stmt = $pdo->prepare(
            "SELECT c.*, (SELECT COUNT(*) FROM mechanic_vehicles v WHERE v.customer_id = c.id) AS vehicle_count
             FROM mechanic_customers c
             WHERE {$where}
             ORDER BY c.updated_at DESC, c.id DESC
             LIMIT 300"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $items[] = mechanic_customers_serialize($row);
        }
        api_json(['ok' => true, 'items' => $items]);
    }

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = mechanic_api_request_json();
    $action = trim((string) ($body['action'] ?? 'create'));

    if ($action === 'create') {
        $name = trim((string) ($body['name'] ?? ''));
        $phone = seller_credit_normalize_phone((string) ($body['phone'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        if (!site_auth_is_valid_mobile($phone)) {
            api_error('شماره موبایل را وارد کنید', 400);
        }
        if (mb_strlen($name) > 191) {
            api_error('نام مشتری معتبر نیست', 400);
        }
        mechanic_customer_assert_phone_free($pdo, $mechanicId, $phone);
        $ins = $pdo->prepare(
            'INSERT INTO mechanic_customers (mechanic_id, name, phone, notes)
             VALUES (?, ?, ?, ?)'
        );
        try {
            $ins->execute([$mechanicId, $name, $phone, $notes !== '' ? $notes : null]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate') !== false || strpos($msg, '1062') !== false) {
                api_error('این شماره قبلاً برای مشتری دیگری ثبت شده است.', 400);
            }
            throw $e;
        }
        api_json(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }

    if ($action === 'update') {
        $id = (int) ($body['id'] ?? 0);
        $check = $pdo->prepare('SELECT id FROM mechanic_customers WHERE id = ? AND mechanic_id = ? LIMIT 1');
        $check->execute([$id, $mechanicId]);
        if (!$check->fetch()) {
            api_error('مشتری یافت نشد', 404);
        }
        $name = trim((string) ($body['name'] ?? ''));
        $phoneRaw = trim((string) ($body['phone'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191) {
            api_error('نام مشتری را وارد کنید', 400);
        }
        $phone = $phoneRaw !== '' ? seller_credit_normalize_phone($phoneRaw) : '';
        if ($phone !== '') {
            if (!site_auth_is_valid_mobile($phone)) {
                api_error('شماره موبایل را وارد کنید', 400);
            }
            mechanic_customer_assert_phone_free($pdo, $mechanicId, $phone, $id);
        }
        $pdo->prepare(
            'UPDATE mechanic_customers SET name = ?, phone = ?, notes = ? WHERE id = ? AND mechanic_id = ?'
        )->execute([$name, $phone !== '' ? $phone : null, $notes !== '' ? $notes : null, $id, $mechanicId]);
        api_json(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        $pdo->prepare('DELETE FROM mechanic_customers WHERE id = ? AND mechanic_id = ?')->execute([$id, $mechanicId]);
        api_json(['ok' => true]);
    }

    api_error('عملیات نامعتبر', 400);
} catch (Throwable $e) {
    error_log('[mechanic-customers] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (strpos($msg, 'وارد کنید') !== false || strpos($msg, 'قبلاً') !== false) {
        api_error($msg, 400);
    }
    api_error('خطای سرور', 500);
}
