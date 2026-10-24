<?php
/*
 * Rechnungs-App – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

// Öffentliche Statusseite für Kunden (Zugriff nur mit geheimem Link-Token, kein Login).
define('NO_SESSION', true);
require __DIR__ . '/src/bootstrap.php';

use App\Einvoice;
use App\Invoices;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

header('Referrer-Policy: no-referrer');

$t = is_string($_GET['t'] ?? '') ? (string)$_GET['t'] : '';
$rlKey = 'token:' . App\RateLimit::ip();
if (App\RateLimit::blocked($rlKey, 30, 600)) {
    http_response_code(429);
    header('Retry-After: 600');
    exit('Zu viele Anfragen. Bitte später erneut versuchen.');
}
$inv = preg_match('/^[0-9a-f]{32}$/', $t) ? Invoices::findByToken($t) : null;
if (!$inv) {
    App\RateLimit::fail($rlKey, 600);
    http_response_code(404);
    render('status', ['inv' => null, 'public' => true, 'bare' => true]);
    exit;
}

$problems = Einvoice::xrechnungProblems($inv);
$dl = is_string($_GET['dl'] ?? '') ? (string)$_GET['dl'] : '';
try {
    if ($dl === 'pdf') {
        $bytes = Invoices::pdfBytes($inv);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $inv['number'] . '.pdf"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
    if ($dl === 'xrechnung' && !$problems) {
        $xml = Einvoice::xml($inv, true);
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $inv['number'] . '_xrechnung.xml"');
        header('Content-Length: ' . strlen($xml));
        echo $xml;
        exit;
    }
} catch (Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
    exit('Der Download ist derzeit nicht verfügbar.');
}

render('status', ['inv' => $inv, 'token' => $t, 'xrOk' => !$problems, 'public' => true, 'bare' => true]);
