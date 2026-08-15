<?php
declare(strict_types=1);

require_once __DIR__ . '/cms/bootstrap.php';
require_once __DIR__ . '/cms/lib/mechanics.php';
require_once __DIR__ . '/cms/lib/mechanic-invoices.php';

$token = strtolower(trim((string) ($_GET['t'] ?? '')));
$base = mechanic_invoice_site_base();
$path = $base . '/k/' . rawurlencode($token !== '' ? $token : 'invalid') . '/';
header('Location: ' . $path, true, 302);
exit;
