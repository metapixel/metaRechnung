<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

// Öffentlicher Abruf eines Exports (z. B. für den Steuerberater): nur mit geheimem Link-Token, ohne Login, befristet.
define('NO_SESSION', true);
require __DIR__ . '/src/bootstrap.php';

use App\Audit;
use App\Exports;
use App\RateLimit;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$t = is_string($_GET['t'] ?? '') ? (string)$_GET['t'] : '';
$rlKey = 'extoken:' . RateLimit::ip();
if (RateLimit::blocked($rlKey, 30, 600)) {
    http_response_code(429);
    header('Retry-After: 600');
    exit('Zu viele Anfragen. Bitte später erneut versuchen.');
}
$exp = Exports::findByToken($t);
if (!$exp) {
    RateLimit::fail($rlKey, 600);
    http_response_code(404);
    render('export_public', ['exp' => null, 'public' => true, 'bare' => true]);
    exit;
}

if (($_GET['dl'] ?? '') === '1') {
    $dlKey = 'exdl:' . RateLimit::ip();
    if (RateLimit::blocked($dlKey, 20, 600)) {
        http_response_code(429);
        header('Retry-After: 600');
        exit('Zu viele Downloads. Bitte später erneut versuchen.');
    }
    RateLimit::fail($dlKey, 600);   // zählt jeden Abruf; begrenzt auch die Zahl der Protokolleinträge
    $path = Exports::path($exp);
    if ($path === null) {
        http_response_code(404);
        exit('Datei nicht gefunden.');
    }
    Audit::log('export.download', 'export', (int)$exp['id'], $exp['label'] . ' (' . (int)$exp['invoice_count'] . ' Belege)', 'Download-Link');
    Exports::recordDownload((int)$exp['id']);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . Exports::downloadName($exp) . '"');
    header('Content-Length: ' . (string)filesize($path));
    // Geprüft: feste, serverseitig erzeugte ZIP-Datei mit zufälligem Namen (kein Benutzerpfad)
    // nosemgrep
    readfile($path);
    exit;
}

render('export_public', ['exp' => $exp, 'public' => true, 'bare' => true, 't' => $t]);
