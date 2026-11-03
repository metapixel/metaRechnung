<?php
// Logik-Tests für die Auswahl der Zahlungs-QR-Codes.  Aufruf: php tests/unit/payqr_test.php
declare(strict_types=1);
namespace App { function env(string $k, string $d = ''): string { return $GLOBALS['ENV'][$k] ?? $d; } }
namespace {
require __DIR__ . '/../../src/PayQr.php';
use App\PayQr as Q;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . json_encode($got) . ", erwartet " . json_encode($want) . "\n"; } }

// PDF-Modi (hasGiro, hasPayPal)
eq('pdf giro_link beide', Q::pdf(true, true, 'giro_link'), ['giro' => true, 'pp' => 'link']);
eq('pdf giro beide', Q::pdf(true, true, 'giro'), ['giro' => true, 'pp' => null]);
eq('pdf paypal beide', Q::pdf(true, true, 'paypal'), ['giro' => false, 'pp' => 'qr']);
eq('pdf both beide', Q::pdf(true, true, 'both'), ['giro' => true, 'pp' => 'qr']);
eq('pdf none beide', Q::pdf(true, true, 'none'), ['giro' => false, 'pp' => 'link']);
eq('pdf giro_link ohne PayPal', Q::pdf(true, false, 'giro_link'), ['giro' => true, 'pp' => null]);
eq('pdf paypal ohne PayPal: Ersatz GiroCode', Q::pdf(true, false, 'paypal'), ['giro' => true, 'pp' => null]);
eq('pdf paypal ohne beides', Q::pdf(false, false, 'paypal'), ['giro' => false, 'pp' => null]);
eq('pdf giro ohne GiroCode (aus): kein QR', Q::pdf(false, true, 'giro'), ['giro' => false, 'pp' => null]);
eq('pdf both nur PayPal', Q::pdf(false, true, 'both'), ['giro' => false, 'pp' => 'qr']);
eq('pdf giro_link nur PayPal: Link', Q::pdf(false, true, 'giro_link'), ['giro' => false, 'pp' => 'link']);

// Statusseite
$g = fn(array $r) => $r['giro'] ? ($r['paypal'] ? 'GP' : 'G') : ($r['paypal'] ? 'P' : '-');
eq('status choice Standard giro', $g(Q::status(true, true, '', 'choice')), 'G');
eq('status choice pay=paypal', $g(Q::status(true, true, 'paypal', 'choice')), 'P');
eq('status choice pay=giro', $g(Q::status(true, true, 'giro', 'choice')), 'G');
eq('status choice Unsinn → giro', $g(Q::status(true, true, 'x<script>', 'choice')), 'G');
eq('status choice tabs', Q::status(true, true, '', 'choice')['tabs'], true);
eq('status giro ignoriert Wunsch', $g(Q::status(true, true, 'paypal', 'giro')), 'G');
eq('status paypal ignoriert Wunsch', $g(Q::status(true, true, 'giro', 'paypal')), 'P');
eq('status both', $g(Q::status(true, true, '', 'both')), 'GP');
eq('status both ohne Tabs', Q::status(true, true, '', 'both')['tabs'], false);
eq('status nur giro vorhanden, Wunsch paypal', $g(Q::status(true, false, 'paypal', 'choice')), 'G');
eq('status nur PayPal vorhanden', $g(Q::status(false, true, '', 'choice')), 'P');
eq('status nur eins: keine Tabs', Q::status(true, false, '', 'choice')['tabs'], false);
eq('status nichts', $g(Q::status(false, false, '', 'choice')), '-');
eq('status paypal-Modus ohne PayPal: giro', $g(Q::status(true, false, '', 'paypal')), 'G');

// Einstellungen lesen
$GLOBALS['ENV'] = [];
eq('Standard pdf', Q::pdfMode(), 'giro_link');
eq('Standard status', Q::statusMode(), 'choice');
$GLOBALS['ENV'] = ['PAY_QR_PDF' => 'both', 'PAY_QR_STATUS' => 'paypal'];
eq('gesetzt pdf', Q::pdfMode(), 'both');
eq('gesetzt status', Q::statusMode(), 'paypal');
$GLOBALS['ENV'] = ['PAY_QR_PDF' => 'boese', 'PAY_QR_STATUS' => ''];
eq('ungültig pdf → Standard', Q::pdfMode(), 'giro_link');
eq('leer status → Standard', Q::statusMode(), 'choice');
echo $fail ? "$fail von $n Tests FEHLGESCHLAGEN\n" : "Alle $n Tests bestanden\n";
exit($fail ? 1 : 0);
}
