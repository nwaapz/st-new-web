<?php
declare(strict_types=1);

require_once __DIR__ . '/cms/bootstrap.php';
require_once __DIR__ . '/cms/lib/mechanics.php';
require_once __DIR__ . '/cms/lib/mechanic-invoices.php';

$token = trim((string) ($_GET['t'] ?? ''));
$download = isset($_GET['download']) && (string) $_GET['download'] !== '' && (string) $_GET['download'] !== '0';

function mechanic_invoice_site_redirect(string $token): void
{
    $base = mechanic_invoice_site_base();
    $path = $base . '/i/' . rawurlencode($token) . '/';
    header('Location: ' . $path, true, 302);
    exit;
}

function mechanic_invoice_html_page(int $status, string $title, string $bodyHtml): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . cms_h($title) . '</title>';
    echo '<style>
      body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#111;color:#f4f4f4;}
      .wrap{max-width:32rem;margin:2.5rem auto;padding:0 1.1rem;}
      .card{background:#1a1a1a;border:1px solid rgba(255,255,255,.16);border-radius:1rem;padding:1.25rem 1.2rem;}
      h1{margin:0 0 .75rem;font-size:1.25rem;}
      p{margin:.4rem 0;line-height:1.7;color:rgba(255,255,255,.82);}
    </style></head><body><div class="wrap"><div class="card">';
    echo $bodyHtml;
    echo '</div></div></body></html>';
    exit;
}

if (!$download) {
    mechanic_invoice_site_redirect($token !== '' ? $token : 'invalid');
}

try {
    $pdo = cms_pdo();
    mechanics_ensure_schema($pdo);

    $invoice = mechanic_invoice_find_by_token($pdo, $token);
    if ($invoice === null) {
        mechanic_invoice_site_redirect($token !== '' ? $token : 'invalid');
    }

    $mechanicStmt = $pdo->prepare('SELECT * FROM mechanics WHERE id = ? LIMIT 1');
    $mechanicStmt->execute([(int) $invoice['mechanic_id']]);
    $mechanic = $mechanicStmt->fetch() ?: [];

    $custStmt = $pdo->prepare('SELECT * FROM mechanic_customers WHERE id = ? LIMIT 1');
    $custStmt->execute([(int) $invoice['customer_id']]);
    $customer = $custStmt->fetch() ?: [];

    $vehStmt = $pdo->prepare('SELECT * FROM mechanic_vehicles WHERE id = ? LIMIT 1');
    $vehStmt->execute([(int) $invoice['vehicle_id']]);
    $vehicle = $vehStmt->fetch() ?: [];

    $pdo->prepare('UPDATE mechanic_invoices SET pdf_downloaded_at = NOW() WHERE id = ?')
        ->execute([(int) $invoice['id']]);

    $lines = mechanic_invoice_lines($pdo, (int) $invoice['id']);
    mechanic_invoice_pdf_stream($invoice, $lines, $mechanic, $customer, $vehicle);
} catch (Throwable $e) {
    error_log('[mechanic-invoice-public] ' . $e->getMessage());
    mechanic_invoice_html_page(500, 'خطا', '<h1>خطای سرور</h1><p>امکان دانلود فاکتور نیست. بعداً دوباره تلاش کنید.</p>');
}
