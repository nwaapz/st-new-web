<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_mechanic.php';
require_once dirname(__DIR__) . '/cms/lib/mechanic-invoices.php';

site_auth_prepare_cors();

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);
    mechanics_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $mechanic = mechanic_api_require($pdo);
    $mechanicId = (int) $mechanic['id'];

    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $body = mechanic_api_request_json();
    $vehicleId = (int) ($body['vehicle_id'] ?? 0);
    $vehStmt = $pdo->prepare('SELECT * FROM mechanic_vehicles WHERE id = ? AND mechanic_id = ? LIMIT 1');
    $vehStmt->execute([$vehicleId, $mechanicId]);
    $vehicle = $vehStmt->fetch();
    if (!$vehicle) {
        api_error('خودرو یافت نشد', 404);
    }

    $performedAt = trim((string) ($body['performed_at'] ?? ''));
    if ($performedAt === '') {
        $performedAt = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $performedAt)) {
        api_error('تاریخ سرویس نامعتبر است', 400);
    }

    $kmRaw = isset($body['km_at_service']) ? trim((string) $body['km_at_service']) : '';
    $km = null;
    if ($kmRaw !== '' && preg_match('/^\d+$/', $kmRaw)) {
        $km = min(3000000, max(0, (int) $kmRaw));
    }

    $rawLines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
    $cleanLines = [];
    $sort = 0;
    foreach ($rawLines as $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = trim((string) ($row['kind'] ?? ''));
        if ($kind !== 'service' && $kind !== 'part') {
            continue;
        }
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        if (function_exists('mb_strlen') && mb_strlen($label) > 80) {
            $label = mb_substr($label, 0, 80);
        } elseif (strlen($label) > 240) {
            $label = substr($label, 0, 240);
        }
        $brand = trim((string) ($row['brand'] ?? ''));
        if ($brand !== '' && function_exists('mb_strlen') && mb_strlen($brand) > 80) {
            $brand = mb_substr($brand, 0, 80);
        }
        $qty = isset($row['quantity']) ? max(1, min(99, (int) $row['quantity'])) : 1;
        $unit = mechanic_invoice_parse_price($row['unit_price'] ?? 0);
        $cleanLines[] = [
            'kind' => $kind,
            'label' => $label,
            'brand' => $brand,
            'quantity' => $qty,
            'unit_price' => $unit,
            'line_total' => $unit * $qty,
            'sort_order' => $sort,
        ];
        $sort++;
    }

    if ($cleanLines === []) {
        api_error('حداقل یک خدمت یا قطعه برای فاکتور لازم است', 400);
    }

    $servicesTotal = 0;
    $partsTotal = 0;
    foreach ($cleanLines as $line) {
        if ($line['kind'] === 'part') {
            $partsTotal += $line['line_total'];
        } else {
            $servicesTotal += $line['line_total'];
        }
    }
    $total = $servicesTotal + $partsTotal;

    $token = mechanic_invoice_new_token();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO mechanic_invoices
             (mechanic_id, customer_id, vehicle_id, public_token, km_at_service, performed_at,
              services_total, parts_total, total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $mechanicId,
            (int) $vehicle['customer_id'],
            $vehicleId,
            $token,
            $km,
            $performedAt,
            $servicesTotal,
            $partsTotal,
            $total,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $lineIns = $pdo->prepare(
            'INSERT INTO mechanic_invoice_lines
             (invoice_id, kind, label, brand, quantity, unit_price, line_total, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($cleanLines as $line) {
            $lineIns->execute([
                $invoiceId,
                $line['kind'],
                $line['label'],
                $line['brand'] !== '' ? $line['brand'] : null,
                $line['quantity'],
                $line['unit_price'],
                $line['line_total'],
                $line['sort_order'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    api_json([
        'ok' => true,
        'id' => $invoiceId,
        'token' => $token,
        'public_url' => mechanic_invoice_public_url($token),
        'total' => $total,
        'services_total' => $servicesTotal,
        'parts_total' => $partsTotal,
    ]);
} catch (Throwable $e) {
    error_log('[mechanic-invoices] ' . $e->getMessage());
    $msg = $e->getMessage();
    if (strpos($msg, 'نامعتبر') !== false || strpos($msg, 'لازم است') !== false) {
        api_error($msg, 400);
    }
    api_error('خطای سرور', 500);
}
