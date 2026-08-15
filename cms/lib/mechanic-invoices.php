<?php
declare(strict_types=1);

require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/jalali.php';

function mechanic_invoice_new_token(): string
{
    return bin2hex(random_bytes(16));
}

function mechanic_invoice_site_base(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($script), '/');
    $tail = substr($dir, -4);
    if ($tail === '/api' || $tail === '/cms') {
        $dir = rtrim(dirname($dir), '/');
    }
    if ($dir === '/' || $dir === '.' || $dir === '') {
        return '';
    }
    return $dir;
}

function mechanic_invoice_public_url(string $token): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = mechanic_invoice_site_base();
    return $scheme . '://' . $host . $base . '/i/' . $token;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function mechanic_invoice_public_payload(array $row): array
{
    $token = (string) ($row['public_token'] ?? '');
    return [
        'id' => (int) ($row['id'] ?? 0),
        'token' => $token,
        'public_url' => $token !== '' ? mechanic_invoice_public_url($token) : '',
        'performed_at' => $row['performed_at'] ?? null,
        'km_at_service' => $row['km_at_service'] !== null ? (int) $row['km_at_service'] : null,
        'total' => (int) ($row['total'] ?? 0),
        'sms_sent_at' => $row['sms_sent_at'] ?? null,
    ];
}

function mechanic_invoice_parse_price($raw): int
{
    $s = trim((string) $raw);
    if ($s === '') {
        return 0;
    }
    $s = strtr($s, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ',' => '', '٬' => '', ' ' => '',
    ]);
    if (!preg_match('/^\d+$/', $s)) {
        return 0;
    }
    if (strlen($s) > 12) {
        $s = substr($s, 0, 12);
    }
    return max(0, (int) $s);
}

/**
 * @return array<string, mixed>|null
 */
function mechanic_invoice_find_by_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-fA-F0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM mechanic_invoices WHERE public_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ? $row : null;
}

/**
 * @return list<array<string, mixed>>
 */
function mechanic_invoice_lines(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM mechanic_invoice_lines WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * @param array<string, mixed> $invoice
 * @param list<array<string, mixed>> $lines
 * @param array<string, mixed> $mechanic
 * @param array<string, mixed> $customer
 * @param array<string, mixed> $vehicle
 */
function mechanic_invoice_pdf_stream(
    array $invoice,
    array $lines,
    array $mechanic,
    array $customer,
    array $vehicle
): void {
    $tcpdf = __DIR__ . '/tcpdf/tcpdf.php';
    if (!is_file($tcpdf)) {
        throw new RuntimeException('کتابخانه PDF نصب نشده است');
    }
    require_once $tcpdf;

    $workshop = trim((string) ($mechanic['workshop_name'] ?? ''));
    $city = trim((string) ($mechanic['city'] ?? ''));
    $owner = trim((string) ($mechanic['owner_name'] ?? ''));
    $phone = trim((string) ($mechanic['phone'] ?? ''));
    $custLabel = mechanic_customer_label(
        isset($customer['name']) ? (string) $customer['name'] : '',
        isset($customer['phone']) ? (string) $customer['phone'] : ''
    );
    $custPhone = trim((string) ($customer['phone'] ?? ''));
    $vehicleLabel = trim((string) ($vehicle['brand'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''));
    $plate = trim((string) ($vehicle['plate'] ?? ''));
    $km = $invoice['km_at_service'] !== null ? (int) $invoice['km_at_service'] : null;
    $performedAt = (string) ($invoice['performed_at'] ?? '');
    $token = (string) ($invoice['public_token'] ?? '');

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('StarTech');
    $pdf->SetAuthor($workshop !== '' ? $workshop : 'StarTech');
    $pdf->SetTitle('فاکتور خدمات');
    $pdf->SetMargins(14, 14, 14);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->setRTL(true);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 11);

    $logoPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logo.png';
    if (is_file($logoPath)) {
        $pdf->Image($logoPath, 164, 12, 28, 0, 'PNG', '', '', false, 300, '', false, false, 0);
    }

    $pdf->SetY(16);
    $pdf->SetFont('dejavusans', 'B', 18);
    $pdf->Cell(0, 10, 'فاکتور خدمات تعمیرگاه', 0, 1, 'C');
    $pdf->SetFont('dejavusans', '', 11);
    $pdf->Ln(2);

    $meta = [];
    if ($workshop !== '') {
        $meta[] = 'تعمیرگاه: ' . $workshop . ($city !== '' ? ' — ' . $city : '');
    }
    if ($owner !== '') {
        $meta[] = 'مسئول: ' . $owner;
    }
    if ($phone !== '') {
        $meta[] = 'تماس تعمیرگاه: ' . cms_to_persian_digits($phone);
    }
    $meta[] = 'مشتری: ' . $custLabel;
    if ($custPhone !== '' && $custPhone !== $custLabel) {
        $meta[] = 'موبایل مشتری: ' . cms_to_persian_digits($custPhone);
    }
    if ($vehicleLabel !== '') {
        $meta[] = 'خودرو: ' . $vehicleLabel;
    }
    if ($plate !== '') {
        $meta[] = 'پلاک: ' . $plate;
    }
    if ($km !== null) {
        $meta[] = 'کیلومتر: ' . cms_to_persian_digits((string) $km);
    }
    $meta[] = 'تاریخ سرویس: ' . cms_to_persian_digits(cms_jalali_format_date($performedAt));
    $meta[] = 'تاریخ صدور: ' . cms_to_persian_digits(cms_jalali_format_from_timestamp(date('Y-m-d H:i:s')));

    foreach ($meta as $line) {
        $pdf->Cell(0, 7, $line, 0, 1, 'R');
    }
    $pdf->Ln(4);

    $wKind = 22;
    $wName = 62;
    $wQty = 18;
    $wUnit = 40;
    $wLine = 40;
    $tableW = $wKind + $wName + $wQty + $wUnit + $wLine;

    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetFillColor(227, 6, 19);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($wKind, 9, 'نوع', 1, 0, 'C', true);
    $pdf->Cell($wName, 9, 'شرح', 1, 0, 'C', true);
    $pdf->Cell($wQty, 9, 'تعداد', 1, 0, 'C', true);
    $pdf->Cell($wUnit, 9, 'قیمت واحد', 1, 0, 'C', true);
    $pdf->Cell($wLine, 9, 'مبلغ', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('dejavusans', '', 8);
    if ($lines === []) {
        $pdf->Cell($tableW, 9, 'قلم ثبت نشده', 1, 1, 'C');
    } else {
        foreach ($lines as $row) {
            $kind = (string) ($row['kind'] ?? '');
            $kindLabel = $kind === 'part' ? 'قطعه' : 'خدمت';
            $name = (string) ($row['label'] ?? '—');
            $brand = trim((string) ($row['brand'] ?? ''));
            if ($brand !== '') {
                $name .= ' — ' . $brand;
            }
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            $unit = (int) ($row['unit_price'] ?? 0);
            $line = (int) ($row['line_total'] ?? 0);
            $nameLines = max(1, (int) ceil(mb_strlen($name) / 24));
            $h = max(9, $nameLines * 6);

            $pdf->MultiCell($wKind, $h, $kindLabel, 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
            $pdf->MultiCell($wName, $h, $name, 1, 'R', false, 0, '', '', true, 0, false, true, $h, 'M');
            $pdf->MultiCell($wQty, $h, cms_to_persian_digits((string) $qty), 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
            $pdf->MultiCell($wUnit, $h, invoices_format_toman($unit), 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
            $pdf->MultiCell($wLine, $h, invoices_format_toman($line), 1, 'C', false, 1, '', '', true, 0, false, true, $h, 'M');
        }
    }

    $servicesTotal = (int) ($invoice['services_total'] ?? 0);
    $partsTotal = (int) ($invoice['parts_total'] ?? 0);
    $total = (int) ($invoice['total'] ?? 0);

    $pdf->SetFont('dejavusans', '', 10);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell($tableW - $wLine, 8, 'جمع خدمات', 1, 0, 'R', true);
    $pdf->Cell($wLine, 8, invoices_format_toman($servicesTotal), 1, 1, 'C', true);
    $pdf->Cell($tableW - $wLine, 8, 'جمع قطعات', 1, 0, 'R', true);
    $pdf->Cell($wLine, 8, invoices_format_toman($partsTotal), 1, 1, 'C', true);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->Cell($tableW - $wLine, 10, 'جمع کل', 1, 0, 'R', true);
    $pdf->Cell($wLine, 10, invoices_format_toman($total), 1, 1, 'C', true);

    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->MultiCell(0, 5, 'مبالغ به تومان است. این فاکتور توسط باشگاه مشتریان استارتک صادر شده است.', 0, 'R');

    $fileName = 'invoice-' . ($token !== '' ? substr($token, 0, 8) : 'visit') . '.pdf';
    $pdf->Output($fileName, 'D');
    exit;
}
