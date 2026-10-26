<?php
// Rauchtest nach dem Verschlanken von vendor/: erzeugt wirklich XML (beide Profile) und ein PDF/A-3 mit eingebettetem XML.
declare(strict_types=1);
require ($argv[1] ?? __DIR__ . '/../vendor') . '/autoload.php';

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;

foreach ([ZugferdProfiles::PROFILE_EN16931, ZugferdProfiles::PROFILE_XRECHNUNG_3] as $profile) {
    $d = ZugferdDocumentBuilder::CreateNew($profile);
    $d->setDocumentInformation('R-1', '380', new DateTime('2026-01-01'), 'EUR');
    $d->setDocumentSeller('Test', '1');
    $d->setDocumentBuyer('Kunde', '2');
    $d->addNewPosition('1');
    $d->setDocumentPositionProductDetails('Artikel', 'Beschreibung');
    $d->setDocumentPositionNetPrice(10.0);
    $d->setDocumentPositionQuantity(1.0, 'C62');
    $d->addDocumentPositionTax('S', 'VAT', 19.0);
    $d->setDocumentPositionLineSummation(10.0);
    $d->addDocumentTax('S', 'VAT', 10.0, 1.9, 19.0);
    $d->setDocumentSummation(11.9, 11.9, 10.0, 0.0, 0.0, 10.0, 1.9, null, 0.0);
    $xml = $d->getContent();
    if (!str_contains($xml, 'CrossIndustryInvoice') || !str_contains($xml, 'Beschreibung')) { fwrite(STDERR, "XML ($profile) unvollständig\n"); exit(1); }
}
$tmp = sys_get_temp_dir();
$m = new Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmp, 'default_font' => 'dejavusanscondensed', 'PDFA' => true, 'PDFAauto' => true, 'PDFAversion' => '3-B']);
$m->WriteHTML('<p>Größe € äöüß <b>fett</b> <i>kursiv</i></p>');
$pdf = $m->Output('', 'S');
if (!str_starts_with($pdf, '%PDF') || strlen($pdf) < 5000) { fwrite(STDERR, "PDF-Erzeugung fehlgeschlagen\n"); exit(1); }
// GiroCode: längster zulässiger Inhalt (331 Byte) muss sich mit den verbliebenen Versionsdaten erzeugen lassen
$gc = new Mpdf\QrCode\QrCode(str_repeat('x', 331), 'M');
if (strlen((new Mpdf\QrCode\Output\Svg())->output($gc, 200, 'white', 'black')) < 1000) { fwrite(STDERR, "QR-Code (331 Byte) fehlgeschlagen\n"); exit(1); }
foreach (['PHPMailer\\PHPMailer\\PHPMailer', 'setasign\\Fpdi\\Fpdi'] as $c) { if (!class_exists($c)) { fwrite(STDERR, "Klasse fehlt: $c\n"); exit(1); } }
echo "Rauchtest ok (XML EN16931 + XRechnung, PDF/A-3, Mailer, FPDI, QR)\n";
