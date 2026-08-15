<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/cms/lib/warranty.php';
require_once dirname(__DIR__) . '/cms/lib/warranty-sms-store.php';
require_once dirname(__DIR__) . '/cms/lib/jalali.php';

site_auth_prepare_cors();

/**
 * @param array<string, mixed> $item
 */
function warranty_pdf_render_single(TCPDF $pdf, array $item): void
{
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->Cell(0, 10, 'جزئیات گارانتی', 0, 1, 'C');
    $pdf->Ln(4);
    $pdf->SetFont('dejavusans', '', 12);

    // Values that mix Latin digits with a Persian plate letter (e.g. "12-ب-345-67")
    // must be rendered with a forced LTR run — otherwise TCPDF's bidi algorithm
    // reorders the digit groups around the RTL letter and the plate comes out
    // in a different order than the user typed it.
    $ltrFields = ['کد سریال', 'پلاک خودرو'];

    $rows = [
        'کد سریال' => $item['serial'],
        'نوع' => warranty_kind_label((string) $item['kind']),
        'شهر' => $item['city'] !== '' ? $item['city'] : '—',
        'کیلومتراژ ثبت‌شده' => $item['km'] !== '' ? $item['km'] : '—',
        'پلاک خودرو' => $item['car_plate'] !== '' ? $item['car_plate'] : '—',
        'تاریخ ثبت' => $item['registered_date'],
    ];
    foreach ($rows as $label => $value) {
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(0, 7, $label, 0, 1, 'R');
        $pdf->SetFont('dejavusans', 'B', 13);
        if (in_array($label, $ltrFields, true)) {
            $pdf->setTempRTL('LTR');
        }
        $pdf->Cell(0, 9, (string) $value, 0, 1, 'R');
        $pdf->setTempRTL(false);
        $pdf->Ln(1);
    }

    $pdf->Ln(4);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->Cell(0, 6, 'استارتک — استعلام گارانتی', 0, 1, 'R');
}

/**
 * @param list<array<string, mixed>> $items
 */
function warranty_pdf_render_list(TCPDF $pdf, array $items): void
{
    $pdf->SetFont('dejavusans', 'B', 15);
    $pdf->Cell(0, 9, 'لیست گارانتی‌های ثبت‌شده', 0, 1, 'C');
    $pdf->Ln(2);

    $wRow = 12;
    $wSerial = 44;
    $wKind = 34;
    $wCity = 34;
    $wKm = 26;
    $wDate = 42;

    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetFillColor(235, 235, 235);
    $pdf->Cell($wRow, 9, 'ردیف', 1, 0, 'C', true);
    $pdf->Cell($wSerial, 9, 'کد سریال', 1, 0, 'C', true);
    $pdf->Cell($wKind, 9, 'نوع', 1, 0, 'C', true);
    $pdf->Cell($wCity, 9, 'شهر', 1, 0, 'C', true);
    $pdf->Cell($wKm, 9, 'کیلومتر', 1, 0, 'C', true);
    $pdf->Cell($wDate, 9, 'تاریخ ثبت', 1, 1, 'C', true);

    $pdf->SetFont('dejavusans', '', 8);
    $rowNum = 1;
    foreach ($items as $item) {
        $pdf->Cell($wRow, 8, (string) $rowNum, 1, 0, 'C');
        $pdf->Cell($wSerial, 8, (string) $item['serial'], 1, 0, 'C');
        $pdf->Cell($wKind, 8, warranty_kind_label((string) $item['kind']), 1, 0, 'C');
        $pdf->Cell($wCity, 8, $item['city'] !== '' ? (string) $item['city'] : '—', 1, 0, 'C');
        $pdf->Cell($wKm, 8, $item['km'] !== '' ? (string) $item['km'] : '—', 1, 0, 'C');
        $pdf->Cell($wDate, 8, (string) $item['registered_date'], 1, 1, 'C');
        $rowNum++;
    }

    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->Cell(0, 6, 'استارتک — استعلام گارانتی‌های ثبت‌شده', 0, 1, 'R');
}

try {
    $pdo = cms_pdo();
    site_auth_ensure_schema($pdo);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        api_json(['ok' => true]);
    }
    if ($method !== 'POST') {
        api_error('Method not allowed', 405);
    }

    $user = site_auth_current_user($pdo);
    if ($user === null) {
        api_error('لطفاً ابتدا وارد حساب کاربری شوید', 401);
    }

    $body = site_auth_request_json();
    $mode = trim((string) ($body['mode'] ?? ''));

    if (!in_array($mode, ['single', 'list'], true)) {
        api_error('حالت نامعتبر است', 400);
    }

    $phone = (string) $user['phone'];

    try {
        $smsPdo = warranty_sms_pdo();
    } catch (RuntimeException $e) {
        api_error($e->getMessage(), 503);
    }

    if ($mode === 'single') {
        $serial = strtoupper(warranty_normalize_serial((string) ($body['serial'] ?? '')));
        if ($serial === '') {
            api_error('شماره سریال وارد نشده است', 400);
        }
        $item = warranty_sms_find_registered_by_phone($smsPdo, $serial, $phone);
        if ($item === null) {
            api_error('گارانتی یافت نشد', 404);
        }
        $items = [$item];
    } else {
        $items = warranty_sms_list_by_phone($smsPdo, $phone);
        if ($items === []) {
            api_error('گارانتی ثبت‌شده‌ای یافت نشد', 404);
        }
    }

    $tcpdf = dirname(__DIR__) . '/cms/lib/tcpdf/tcpdf.php';
    if (!is_file($tcpdf)) {
        api_error('کتابخانه PDF نصب نشده است', 500);
    }
    require_once $tcpdf;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('StarTech');
    $pdf->SetAuthor('StarTech');
    $pdf->SetTitle($mode === 'single' ? 'گارانتی ' . $items[0]['serial'] : 'لیست گارانتی‌ها');
    $pdf->SetMargins(14, 14, 14);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->setRTL(true);
    $pdf->AddPage();

    $logoPath = dirname(__DIR__) . '/images/logo.png';
    if (is_file($logoPath)) {
        $pdf->Image($logoPath, 164, 12, 28, 0, 'PNG', '', '', false, 300, '', false, false, 0);
        $pdf->Ln(4);
    }

    if ($mode === 'single') {
        warranty_pdf_render_single($pdf, $items[0]);
        $safeSerial = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $items[0]['serial']) ?: 'warranty';
        $filename = 'warranty_' . $safeSerial . '.pdf';
    } else {
        warranty_pdf_render_list($pdf, $items);
        $safePhone = preg_replace('/\D/', '', $phone);
        $filename = 'warranty_list_' . $safePhone . '.pdf';
    }

    $pdf->Output($filename, 'D');
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 503);
} catch (Throwable $e) {
    error_log('[warranty-pdf] ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'خطا در ایجاد PDF'], JSON_UNESCAPED_UNICODE);
}
