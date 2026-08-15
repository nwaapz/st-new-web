<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/cms/lib/mechanics.php';
require_once dirname(__DIR__) . '/cms/lib/melipayamak.php';
require_once dirname(__DIR__) . '/cms/lib/seller-credit.php';
require_once dirname(__DIR__) . '/cms/lib/jalali.php';

/**
 * Daily cron: send KM-update SMS for cars due this month.
 * GET/POST /api/mechanic-km-cron.php?key=SECRET
 */

try {
    $pdo = cms_pdo();
    mechanics_ensure_schema($pdo);
    seller_credit_ensure_schema($pdo);

    $given = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
    $secret = mechanic_km_cron_secret();
    if ($given === '' || strlen($given) !== strlen($secret) || !hash_equals($secret, $given)) {
        api_error('Forbidden', 403);
    }

    $window = mechanic_sms_send_window();
    if (!$window['ok']) {
        api_json([
            'ok' => true,
            'skipped' => true,
            'reason' => $window['error'],
            'sent' => 0,
            'failed' => 0,
        ]);
    }

    $stmt = $pdo->query(
        "SELECT v.id, v.mechanic_id, v.brand, v.model, v.current_km, v.public_km_token, v.km_sms_sent_at,
                c.id AS customer_id, c.name AS customer_name, c.phone AS customer_phone,
                m.workshop_name, m.city, m.phone AS mechanic_phone
         FROM mechanic_vehicles v
         INNER JOIN mechanic_customers c ON c.id = v.customer_id
         INNER JOIN mechanics m ON m.id = v.mechanic_id
         WHERE c.phone IS NOT NULL AND c.phone <> ''
           AND (v.km_sms_sent_at IS NULL OR v.km_sms_sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
           AND NOT EXISTS (
             SELECT 1 FROM mechanic_km_readings r
             WHERE r.vehicle_id = v.id AND r.source = 'owner'
               AND r.created_at >= DATE_SUB(NOW(), INTERVAL 25 DAY)
           )
         ORDER BY v.id ASC
         LIMIT 50"
    );
    $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];

    $sent = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $vehicleId = (int) $row['id'];
        $mechanicId = (int) $row['mechanic_id'];
        $mechanicPhone = (string) ($row['mechanic_phone'] ?? '');
        $token = mechanic_vehicle_ensure_km_token($pdo, $row);
        if ($token === '') {
            $skipped++;
            continue;
        }
        $lastKm = mechanic_vehicle_last_km($pdo, $vehicleId, $row);
        $lastKmLabel = $lastKm !== null ? cms_to_persian_digits((string) $lastKm) : '';
        $owner = mechanic_customer_label(
            (string) ($row['customer_name'] ?? ''),
            (string) ($row['customer_phone'] ?? '')
        );
        $vehicleLabel = trim((string) $row['brand'] . ' ' . (string) $row['model']);
        $text = mechanic_sms_template('km_update', [
            'owner' => $owner !== '' ? $owner : 'مشتری گرامی',
            'workshop' => (string) ($row['workshop_name'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'vehicle' => $vehicleLabel !== '' ? $vehicleLabel : 'خودروی شما',
            'url' => mechanic_km_public_url($token),
            'phone' => $mechanicPhone,
            'last_km' => $lastKmLabel,
        ]);
        $segments = seller_credit_sms_segments($text);
        if ($segments < 1) {
            $skipped++;
            continue;
        }
        $canSend = seller_credit_can_send_sms($pdo, $mechanicId, $mechanicPhone, $segments);
        if (!$canSend['ok']) {
            $skipped++;
            continue;
        }
        $result = cms_sms_send((string) $row['customer_phone'], $text);
        $pdo->prepare(
            'INSERT INTO mechanic_sms_log (mechanic_id, vehicle_id, customer_id, phone, template_key, body, status, error)
             VALUES (?, ?, ?, ?, \'km_update\', ?, ?, ?)'
        )->execute([
            $mechanicId,
            $vehicleId,
            (int) $row['customer_id'],
            (string) $row['customer_phone'],
            $text,
            $result['ok'] ? 'sent' : 'failed',
            $result['ok'] ? null : ($result['error'] ?? null),
        ]);
        $smsLogId = (int) $pdo->lastInsertId();
        if (!$result['ok']) {
            $failed++;
            continue;
        }
        $smsCost = (int) ($canSend['total_cost'] ?? 0);
        if ($smsCost > 0) {
            seller_credit_debit_sms($pdo, $mechanicId, $mechanicPhone, $smsCost, $smsLogId > 0 ? $smsLogId : null);
        }
        $pdo->prepare('UPDATE mechanic_vehicles SET km_sms_sent_at = NOW() WHERE id = ?')->execute([$vehicleId]);
        $sent++;
    }

    api_json([
        'ok' => true,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'candidates' => count($rows),
    ]);
} catch (Throwable $e) {
    error_log('[mechanic-km-cron] ' . $e->getMessage());
    api_error('خطای سرور', 500);
}
