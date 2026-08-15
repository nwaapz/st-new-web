<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/mechanics.php';
require_once dirname(__DIR__) . '/cms/lib/mechanic-invoices.php';
require_once dirname(__DIR__) . '/cms/lib/jalali.php';

try {
    $pdo = cms_pdo();
    mechanics_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }
    if ($method !== 'GET') {
        api_error('روش نامعتبر است', 405);
    }

    $token = trim((string) ($_GET['t'] ?? ''));
    $invoice = mechanic_invoice_find_by_token($pdo, $token);
    if ($invoice === null) {
        api_error('این لینک معتبر نیست یا حذف شده است.', 404);
    }

    $mechanicStmt = $pdo->prepare('SELECT workshop_name, city FROM mechanics WHERE id = ? LIMIT 1');
    $mechanicStmt->execute([(int) $invoice['mechanic_id']]);
    $mechanic = $mechanicStmt->fetch() ?: [];

    $custStmt = $pdo->prepare('SELECT phone FROM mechanic_customers WHERE id = ? LIMIT 1');
    $custStmt->execute([(int) $invoice['customer_id']]);
    $customer = $custStmt->fetch() ?: [];

    $vehStmt = $pdo->prepare('SELECT brand, model FROM mechanic_vehicles WHERE id = ? LIMIT 1');
    $vehStmt->execute([(int) $invoice['vehicle_id']]);
    $vehicle = $vehStmt->fetch() ?: [];

    $workshop = trim((string) ($mechanic['workshop_name'] ?? ''));
    $city = trim((string) ($mechanic['city'] ?? ''));
    $vehicleLabel = trim((string) ($vehicle['brand'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''));
    $phone = trim((string) ($customer['phone'] ?? ''));
    $total = (int) ($invoice['total'] ?? 0);

    api_json([
        'ok' => true,
        'workshop' => $workshop,
        'city' => $city,
        'vehicle_label' => $vehicleLabel,
        'customer_phone' => $phone,
        'customer_phone_label' => $phone !== '' ? cms_to_persian_digits($phone) : '',
        'total' => $total,
        'total_label' => invoices_format_toman($total),
        'performed_at' => $invoice['performed_at'] ?? null,
        'performed_at_label' => cms_jalali_format_from_timestamp(
            $invoice['performed_at'] !== null ? (string) $invoice['performed_at'] : null
        ),
    ]);
} catch (Throwable $e) {
    error_log('[invoice-public] ' . $e->getMessage());
    api_error('امکان نمایش فاکتور نیست. بعداً دوباره تلاش کنید.', 500);
}
