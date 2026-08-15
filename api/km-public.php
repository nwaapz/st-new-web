<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/mechanics.php';
require_once dirname(__DIR__) . '/cms/lib/jalali.php';

/**
 * @return array<string, mixed>
 */
function km_public_params(): array
{
    $body = [];
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
    return array_merge($_GET, $_POST, $body);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function km_public_payload(PDO $pdo, array $row, ?int $lastKm, bool $saved = false, ?int $savedKm = null): array
{
    $workshop = trim((string) ($row['workshop_name'] ?? ''));
    $vehicleLabel = trim((string) ($row['brand'] ?? '') . ' ' . (string) ($row['model'] ?? ''));
    $plate = trim((string) ($row['plate'] ?? ''));
    $phone = trim((string) ($row['customer_phone'] ?? ''));
    $vehicleId = (int) ($row['id'] ?? 0);
    return [
        'ok' => true,
        'workshop' => $workshop,
        'city' => trim((string) ($row['city'] ?? '')),
        'vehicle_label' => $vehicleLabel,
        'plate' => $plate,
        'customer_phone' => $phone,
        'customer_phone_label' => $phone !== '' ? cms_to_persian_digits($phone) : '',
        'last_km' => $lastKm,
        'last_km_label' => $lastKm !== null ? cms_to_persian_digits((string) $lastKm) : '—',
        'saved' => $saved,
        'saved_km' => $savedKm,
        'saved_km_label' => $savedKm !== null ? cms_to_persian_digits((string) $savedKm) : null,
        'services' => $vehicleId > 0 ? mechanic_public_km_services($pdo, $vehicleId, $lastKm) : [],
    ];
}

try {
    $pdo = cms_pdo();
    mechanics_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }

    $params = km_public_params();
    $token = strtolower(trim((string) ($params['t'] ?? $params['token'] ?? '')));
    $row = mechanic_vehicle_find_by_km_token($pdo, $token);
    if ($row === null) {
        api_error('این لینک معتبر نیست یا حذف شده است.', 404);
    }

    $vehicleId = (int) $row['id'];
    $lastKm = mechanic_vehicle_last_km($pdo, $vehicleId, $row);

    if ($method === 'GET') {
        api_json(km_public_payload($pdo, $row, $lastKm));
    }

    if ($method !== 'POST') {
        api_error('روش نامعتبر است', 405);
    }

    $kmRaw = preg_replace('/\D/u', '', (string) ($params['km'] ?? '')) ?? '';
    if ($kmRaw === '') {
        api_error('کیلومتر فعلی را وارد کنید.', 400);
    }
    $km = max(0, (int) $kmRaw);
    if ($km > 3000000) {
        $km = 3000000;
    }

    try {
        mechanic_km_assert_not_lower($pdo, $vehicleId, $km, $row);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (strpos($message, 'کیلومتر') === false) {
            $message = 'ثبت کیلومتر ناموفق بود. دوباره تلاش کنید.';
        }
        api_error($message, 400);
    }

    $pdo->prepare('UPDATE mechanic_vehicles SET current_km = ? WHERE id = ?')->execute([$km, $vehicleId]);
    mechanic_km_record_reading($pdo, $vehicleId, $km, 'owner');
    $row['current_km'] = $km;

    api_json(km_public_payload($pdo, $row, $km, true, $km));
} catch (Throwable $e) {
    error_log('[km-public] ' . $e->getMessage());
    api_error('امکان ثبت کیلومتر نیست. بعداً دوباره تلاش کنید.', 500);
}
