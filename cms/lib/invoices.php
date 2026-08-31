<?php
declare(strict_types=1);

require_once __DIR__ . '/jalali.php';

/**
 * Generate Farsi pre-invoice / final invoice PDFs for orders (TCPDF + DejaVu).
 */

function invoices_unit_label(array $item): string
{
    $qty = (int) ($item['quantity'] ?? 1);
    $unit = isset($item['unit_type']) && (string) $item['unit_type'] === 'pack' ? 'pack' : 'piece';
    $pack = isset($item['pack_size']) && $item['pack_size'] !== null ? (int) $item['pack_size'] : 0;
    $qtyFa = cms_to_persian_digits((string) $qty);
    if ($unit === 'pack') {
        if ($pack > 0) {
            return $qtyFa . ' بسته × ' . cms_to_persian_digits((string) $pack) . ' عدد';
        }
        return $qtyFa . ' بسته';
    }
    return $qtyFa . ' عدد';
}

/**
 * Parse a toman amount from CMS/order price text.
 * Accepts Persian/Arabic/Latin digits, thousand separators, optional «تومان».
 * Returns null for empty input. Throws on invalid / non-toman free text.
 */
function invoices_parse_toman_amount(?string $raw): ?int
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    $normalized = strtr($raw, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٬' => '', '،' => '', ',' => '', ' ' => '', "\u{00A0}" => '',
    ]);
    // Strip optional currency word (toman only — rial rejected below).
    $normalized = preg_replace('/تومان$/u', '', $normalized) ?? $normalized;
    $normalized = trim($normalized);

    if ($normalized === '') {
        return null;
    }

    if (preg_match('/ریال/u', $raw)) {
        throw new InvalidArgumentException('قیمت را به تومان وارد کنید (نه ریال)');
    }

    if (!preg_match('/^\d+$/', $normalized)) {
        throw new InvalidArgumentException('قیمت باید عدد تومان باشد (مثلاً ۱۲۵۰۰۰)');
    }

    if (strlen($normalized) > 12) {
        throw new InvalidArgumentException('مبلغ تومان بیش از حد بزرگ است');
    }

    return (int) $normalized;
}

function invoices_format_toman(int $amount): string
{
    $amount = max(0, $amount);
    $grouped = number_format($amount, 0, '.', '٬');
    return cms_to_persian_digits($grouped) . ' تومان';
}

/**
 * Normalize CMS price input to stored price_text, or null if empty.
 *
 * @throws InvalidArgumentException
 */
function invoices_normalize_price_text(string $raw): ?string
{
    $amount = invoices_parse_toman_amount($raw);
    if ($amount === null) {
        return null;
    }
    return invoices_format_toman($amount);
}

/**
 * @param list<array<string, mixed>> $items
 * @return array{total:int, lines:list<array{unit:int|null, line:int|null, unit_label:string, line_label:string}>}
 */
function invoices_totals_from_items(array $items): array
{
    $total = 0;
    $lines = [];
    foreach ($items as $item) {
        $qty = max(1, (int) ($item['quantity'] ?? 1));
        $unitType = isset($item['unit_type']) && (string) $item['unit_type'] === 'pack'
            ? 'pack'
            : 'piece';
        $packSize = isset($item['pack_size']) && $item['pack_size'] !== null
            ? (int) $item['pack_size']
            : 0;
        $piecePrice = null;
        try {
            $piecePrice = invoices_parse_toman_amount(
                isset($item['price_text']) ? (string) $item['price_text'] : null
            );
        } catch (Throwable $e) {
            $piecePrice = null;
        }

        $unitPrice = null;
        $line = null;
        if ($piecePrice !== null) {
            if ($unitType === 'pack' && $packSize > 0) {
                $unitPrice = $piecePrice * $packSize;
                $line = $unitPrice * $qty;
            } else {
                $unitPrice = $piecePrice;
                $line = $piecePrice * $qty;
            }
        }
        if ($line !== null) {
            $total += $line;
        }
        $lines[] = [
            'unit' => $unitPrice,
            'line' => $line,
            'unit_label' => $unitPrice !== null ? invoices_format_toman($unitPrice) : '—',
            'line_label' => $line !== null ? invoices_format_toman($line) : '—',
        ];
    }
    return ['total' => $total, 'lines' => $lines];
}

function invoices_uploads_dir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'invoices';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 * @return string Public path like /uploads/invoices/....pdf
 */
function invoices_generate_pdf(
    array $order,
    array $items,
    string $kind,
    ?string $dueAt = null
): string {
    if (!in_array($kind, ['pre', 'final'], true)) {
        throw new InvalidArgumentException('Invalid invoice kind');
    }

    $tcpdf = __DIR__ . '/tcpdf/tcpdf.php';
    if (!is_file($tcpdf)) {
        throw new RuntimeException('کتابخانه PDF نصب نشده است');
    }
    require_once $tcpdf;

    $title = $kind === 'pre' ? 'پیش‌فاکتور' : 'فاکتور';
    $publicCode = (string) ($order['public_code'] ?? '');
    $phone = (string) (($order['branch_phone'] ?? '') ?: ($order['phone'] ?? ''));
    $createdAt = (string) ($order['created_at'] ?? date('Y-m-d H:i:s'));
    $issuedAt = date('Y-m-d H:i:s');
    if ($kind === 'pre' && ($dueAt === null || trim($dueAt) === '')) {
        $dueAt = isset($order['pre_invoice_due_at']) ? (string) $order['pre_invoice_due_at'] : null;
    }

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('StarTech');
    $pdf->SetAuthor('StarTech');
    $pdf->SetTitle($title . ' ' . $publicCode);
    $pdf->SetMargins(14, 14, 14);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->setRTL(true);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 11);

    $logoPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logo.png';
    if (is_file($logoPath)) {
        // Page width 210mm, margin 14 → right edge ≈ 196. Place logo near top-right.
        $pdf->Image($logoPath, 164, 12, 28, 0, 'PNG', '', '', false, 300, '', false, false, 0);
    }

    $pdf->SetY(16);
    $pdf->SetFont('dejavusans', 'B', 18);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->SetFont('dejavusans', '', 11);
    $pdf->Ln(2);

    $lines = [
        'شماره سفارش: ' . $publicCode,
        'تاریخ صدور: ' . cms_to_persian_digits(cms_jalali_format_from_timestamp($issuedAt)),
        'تاریخ ایجاد سفارش: ' . cms_to_persian_digits(cms_jalali_format_from_timestamp($createdAt)),
    ];
    if ($kind === 'pre') {
        $lines[] = 'سررسید: ' . cms_to_persian_digits(cms_jalali_format_date($dueAt));
    }
    if ($phone !== '') {
        $lines[] = 'موبایل: ' . cms_to_persian_digits($phone);
    }
    if (!empty($order['branch_name'])) {
        $branchLine = 'نماینده: ' . (string) $order['branch_name'];
        if (!empty($order['branch_city'])) {
            $branchLine .= ' — ' . (string) $order['branch_city'];
        }
        $lines[] = $branchLine;
    }

    foreach ($lines as $line) {
        $pdf->Cell(0, 7, $line, 0, 1, 'R');
    }
    $pdf->Ln(5);

    $totals = invoices_totals_from_items($items);
    $wName = 58;
    $wUnit = 36;
    $wQty = 18;
    $wUnitPrice = 36;
    $wLine = 34;
    $tableW = $wName + $wUnit + $wQty + $wUnitPrice + $wLine;

    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetFillColor(235, 235, 235);
    $pdf->Cell($wName, 9, 'محصول', 1, 0, 'C', true);
    $pdf->Cell($wUnit, 9, 'واحد', 1, 0, 'C', true);
    $pdf->Cell($wQty, 9, 'تعداد', 1, 0, 'C', true);
    $pdf->Cell($wUnitPrice, 9, 'قیمت واحد', 1, 0, 'C', true);
    $pdf->Cell($wLine, 9, 'مبلغ', 1, 1, 'C', true);

    $pdf->SetFont('dejavusans', '', 8);
    if ($items === []) {
        $pdf->Cell($tableW, 9, 'قلم ثبت نشده', 1, 1, 'C');
    } else {
        foreach ($items as $idx => $item) {
            $name = (string) ($item['name'] ?? '—');
            $unitLabel = invoices_unit_label($item);
            $qty = cms_to_persian_digits((string) (int) ($item['quantity'] ?? 1));
            $unitPrice = $totals['lines'][$idx]['unit_label'] ?? '—';
            $linePrice = $totals['lines'][$idx]['line_label'] ?? '—';

            $nameLines = max(1, (int) ceil(mb_strlen($name) / 22));
            $h = max(9, $nameLines * 6);

            $pdf->MultiCell($wName, $h, $name, 1, 'R', false, 0, '', '', true, 0, false, true, $h, 'M');
            $pdf->MultiCell($wUnit, $h, $unitLabel, 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
            $pdf->MultiCell($wQty, $h, $qty, 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
            $pdf->MultiCell($wUnitPrice, $h, $unitPrice, 1, 'C', false, 0, '', '', true, 0, false, true, $h, 'M');
            $pdf->MultiCell($wLine, $h, $linePrice, 1, 'C', false, 1, '', '', true, 0, false, true, $h, 'M');
        }
    }

    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell($tableW - $wLine, 10, 'جمع کل (تومان)', 1, 0, 'R', true);
    $pdf->Cell($wLine, 10, invoices_format_toman($totals['total']), 1, 1, 'C', true);

    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', '', 9);
    $note = $kind === 'pre'
        ? 'این پیش‌فاکتور تا تاریخ سررسید معتبر است. مبالغ به تومان است. پس از پرداخت، فاکتور نهایی صادر می‌شود.'
        : 'پرداخت این سفارش تأیید شده است. مبالغ به تومان است. این فاکتور نهایی سفارش است.';
    $pdf->MultiCell(0, 6, $note, 0, 'R');

    $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '_', $publicCode) ?: 'order';
    $fileName = $kind . '-' . $safeCode . '-' . date('YmdHis') . '.pdf';
    $abs = invoices_uploads_dir() . DIRECTORY_SEPARATOR . $fileName;
    $pdf->Output($abs, 'F');

    return '/uploads/invoices/' . $fileName;
}

/**
 * Issue or re-issue pre-invoice for an order. Overwrites latest pre-invoice fields.
 *
 * @return string Public file path
 */
function invoices_issue_pre(PDO $pdo, int $orderId, string $dueAt): string
{
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        throw new RuntimeException('سفارش یافت نشد');
    }
    $dueAt = trim($dueAt);
    if ($dueAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueAt)) {
        throw new RuntimeException('تاریخ سررسید نامعتبر است');
    }
    $items = orders_fetch_items($pdo, $orderId);
    if ($items === []) {
        throw new RuntimeException('سفارش بدون قلم است');
    }
    foreach ($items as $item) {
        $parsed = invoices_parse_toman_amount(
            isset($item['price_text']) ? (string) $item['price_text'] : null
        );
        if ($parsed === null) {
            throw new RuntimeException('برای صدور پیش‌فاکتور، قیمت تومان همه اقلام را وارد کنید');
        }
    }
    $path = invoices_generate_pdf($order, $items, 'pre', $dueAt);

    $old = isset($order['pre_invoice_file']) ? trim((string) $order['pre_invoice_file']) : '';
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET pre_invoice_file = ?, pre_invoice_created_at = NOW(), pre_invoice_due_at = ?
         WHERE id = ?'
    );
    $stmt->execute([$path, $dueAt, $orderId]);

    if ($old !== '' && $old !== $path) {
        invoices_try_unlink_public($old);
    }

    orders_add_event(
        $pdo,
        $orderId,
        (string) $order['status'],
        (string) $order['status'],
        'admin',
        'صدور پیش‌فاکتور — سررسید ' . cms_jalali_format_date($dueAt)
    );

    return $path;
}

/**
 * Generate final invoice after payment confirmed.
 *
 * @return string Public file path
 */
function invoices_issue_final(PDO $pdo, int $orderId): string
{
    $order = orders_get_by_id($pdo, $orderId);
    if ($order === null) {
        throw new RuntimeException('سفارش یافت نشد');
    }
    $items = orders_fetch_items($pdo, $orderId);
    if ($items === []) {
        throw new RuntimeException('سفارش بدون قلم است');
    }
    foreach ($items as $item) {
        $parsed = invoices_parse_toman_amount(
            isset($item['price_text']) ? (string) $item['price_text'] : null
        );
        if ($parsed === null) {
            throw new RuntimeException('برای صدور فاکتور، قیمت تومان همه اقلام لازم است');
        }
    }
    $path = invoices_generate_pdf($order, $items, 'final', null);

    $old = isset($order['final_invoice_file']) ? trim((string) $order['final_invoice_file']) : '';
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET final_invoice_file = ?, final_invoice_created_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$path, $orderId]);

    if ($old !== '' && $old !== $path) {
        invoices_try_unlink_public($old);
    }

    return $path;
}

function invoices_try_unlink_public(string $publicPath): void
{
    $publicPath = str_replace('\\', '/', $publicPath);
    if (!preg_match('#^/uploads/invoices/[A-Za-z0-9._-]+$#', $publicPath)) {
        return;
    }
    $abs = dirname(__DIR__, 2) . $publicPath;
    if (is_file($abs)) {
        @unlink($abs);
    }
}
