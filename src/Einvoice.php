<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use Mpdf\Mpdf;

final class Einvoice
{
    private const EXEMPT_REASON = 'Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG.';

    /** Was für eine gültige XRechnung noch fehlt (leer = alles da). */
    public static function xrechnungProblems(array $inv): array
    {
        $co = $inv['co'];
        $p = [];
        if (trim((string)$co['phone']) === '') {
            $p[] = 'Telefonnummer des Rechnungsstellers (Einstellungen)';
        }
        if (trim((string)$co['email']) === '') {
            $p[] = 'E-Mail-Adresse des Rechnungsstellers (Einstellungen)';
        }
        if (trim((string)$co['iban']) === '') {
            $p[] = 'IBAN (Einstellungen)';
        }
        if (trim((string)$co['vat_id']) === '' && trim((string)$co['tax_number']) === '') {
            $p[] = 'Steuernummer oder USt-IdNr. (Einstellungen)';
        }
        if (empty($inv['c_email'])) {
            $p[] = 'E-Mail-Adresse des Kunden';
        }
        return $p;
    }

    /** Factur-X/ZUGFeRD-XML (EN 16931) oder – mit $xrechnung – XRechnung 3.0 (CII). */
    public static function xml(array $inv, bool $xrechnung = false): string
    {
        $co = $inv['co'];
        $kl = $inv['kl'];
        $t = $inv['totals'];

        if ($xrechnung && ($missing = self::xrechnungProblems($inv))) {
            throw new \RuntimeException('XRechnung nicht möglich, es fehlt: ' . implode(', ', $missing));
        }

        $d = ZugferdDocumentBuilder::CreateNew($xrechnung ? ZugferdProfiles::PROFILE_XRECHNUNG_3 : ZugferdProfiles::PROFILE_EN16931);
        $d->setDocumentInformation($inv['number'], ZugferdInvoiceType::INVOICE, new \DateTime($inv['issue_date']), 'EUR');
        if ($xrechnung) {
            $d->setDocumentBuyerReference($inv['c_buyer_ref'] ?: 'n/a');
        }
        if (!empty($inv['notes'])) {
            $d->addDocumentNote($inv['notes']);
        }
        $d->setDocumentSupplyChainEvent(new \DateTime($inv['delivery_date'] ?: $inv['issue_date']));

        // Verkäufer
        // BR-CO-26: Verkäufer muss identifizierbar sein (BT-29/BT-30/BT-31) – ohne USt-IdNr. dient die Steuernummer als Kennung
        $d->setDocumentSeller($co['name'], $co['tax_number'] !== '' ? $co['tax_number'] : null);
        $d->setDocumentSellerAddress($co['street'], null, null, $co['zip'], $co['city'], $co['country']);
        if ($co['vat_id'] !== '') {
            $d->addDocumentSellerTaxRegistration('VA', $co['vat_id']);
        }
        if ($co['tax_number'] !== '') {
            $d->addDocumentSellerTaxRegistration('FC', $co['tax_number']);
        }
        $d->setDocumentSellerContact($co['owner'] ?: $co['name'], null, $co['phone'] ?: null, null, $co['email'] ?: null);
        if ($co['email'] !== '') {
            $d->setDocumentSellerCommunication('EM', $co['email']);
        }

        // Käufer
        $d->setDocumentBuyer($inv['c_name']);
        $d->setDocumentBuyerAddress($inv['c_street'], null, null, $inv['c_zip'], $inv['c_city'], $inv['c_country']);
        if (!empty($inv['c_vat_id'])) {
            $d->addDocumentBuyerTaxRegistration('VA', $inv['c_vat_id']);
        }
        if (!empty($inv['c_email'])) {
            $d->setDocumentBuyerCommunication('EM', $inv['c_email']);
        }

        // Positionen
        foreach ($inv['items'] as $n => $it) {
            $rate = $kl ? 0.0 : (float)$it['vat_rate'];
            $d->addNewPosition((string)($n + 1));
            $d->setDocumentPositionProductDetails($it['description'], !empty($it['note']) ? (string)$it['note'] : null);
            $d->setDocumentPositionNetPrice((float)$it['unit_price']);
            $d->setDocumentPositionQuantity((float)$it['quantity'], $it['unit']);
            $d->addDocumentPositionTax($kl ? 'E' : ($rate === 0.0 ? 'Z' : 'S'), 'VAT', $rate);
            $d->setDocumentPositionLineSummation(round((float)$it['quantity'] * (float)$it['unit_price'], 2));
        }

        // Steuer je Satz
        foreach ($t['taxes'] as $x) {
            if ($kl) {
                $d->addDocumentTax('E', 'VAT', $x['basis'], 0.0, 0.0, self::EXEMPT_REASON);
            } elseif ($x['rate'] === 0.0) {
                $d->addDocumentTax('Z', 'VAT', $x['basis'], 0.0, 0.0);
            } else {
                $d->addDocumentTax('S', 'VAT', $x['basis'], $x['tax'], $x['rate']);
            }
        }

        $d->setDocumentSummation($t['gross'], $t['gross'], $t['net'], 0.0, 0.0, $t['net'], $t['tax'], null, 0.0);

        if ($co['iban'] !== '') {
            $d->addDocumentPaymentMean('58', null, null, null, null, null, $co['iban'], $co['name'], null, $co['bic'] ?: null);
        }
        $d->addDocumentPaymentTerm('Zahlbar bis ' . fdate($inv['due_date']) . ' ohne Abzug.', new \DateTime($inv['due_date']));

        return $d->getContent();
    }

    /** PDF/A-3 mit eingebettetem factur-x.xml. */
    public static function pdf(array $inv): string
    {
        $co = $inv['co'];
        $kl = $inv['kl'];
        $tmp = dirname(__DIR__) . '/storage/tmp';
        if (!is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $xmlFile = $tmp . '/' . bin2hex(random_bytes(6)) . '-factur-x.xml';
        file_put_contents($xmlFile, self::xml($inv));

        $lh = Letterhead::info();
        $hideSender = $lh !== null && $lh['hideSender'];
        $hideFooter = $lh !== null && $lh['hideFooter'];

        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8', 'format' => 'A4',
                'margin_left' => $lh['ml'] ?? 20, 'margin_right' => $lh['mr'] ?? 20,
                'margin_top' => $lh['mt'] ?? 18, 'margin_bottom' => $lh['mb'] ?? 18,
                'tempDir' => $tmp,
                'default_font' => 'dejavusanscondensed', 'default_font_size' => 9.5,
                'PDFA' => true, 'PDFAauto' => true, 'PDFAversion' => '3-B',
            ]);
            $mpdf->SetTitle('Rechnung ' . $inv['number']);
            $mpdf->SetAuthor($co['name']);
            $mpdf->SetAssociatedFiles([[
                'name' => 'factur-x.xml',
                'mime' => 'text/xml',
                'description' => 'ZUGFeRD/Factur-X Rechnungsdaten (EN 16931)',
                'AFRelationship' => 'Alternative',
                'path' => $xmlFile,
            ]]);
            $mpdf->SetAdditionalXmpRdf(self::xmpRdf());
            if ($lh !== null) {
                if ($lh['type'] === 'pdf') {
                    $mpdf->SetDocTemplate($lh['path'], true);   // letzte Briefpapier-Seite wird für Folgeseiten wiederholt
                } else {
                    $mpdf->SetDefaultBodyCSS('background', "url('" . $lh['path'] . "')");
                    $mpdf->SetDefaultBodyCSS('background-image-resize', 6);
                }
            }

            ob_start();
            require __DIR__ . '/views/pdf.php';
            $html = (string)ob_get_clean();
            $mpdf->WriteHTML($html);
            return $mpdf->Output('', 'S');
        } finally {
            // Geprüft: Pfad wird serverseitig gebildet (feste Ordner, kein Benutzerpfad)
            // nosemgrep
            @unlink($xmlFile);
        }
    }

    private static function xmpRdf(): string
    {
        $prop = static fn(string $name, string $desc): string =>
            '<rdf:li rdf:parseType="Resource"><pdfaProperty:name>' . $name . '</pdfaProperty:name>'
            . '<pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category>'
            . '<pdfaProperty:description>' . $desc . '</pdfaProperty:description></rdf:li>';

        return '<rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">'
            . '<fx:DocumentType>INVOICE</fx:DocumentType><fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>'
            . '<fx:Version>1.0</fx:Version><fx:ConformanceLevel>EN 16931</fx:ConformanceLevel></rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/"'
            . ' xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'
            . '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
            . '<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'
            . '<pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI>'
            . '<pdfaSchema:prefix>fx</pdfaSchema:prefix><pdfaSchema:property><rdf:Seq>'
            . $prop('DocumentFileName', 'name of the embedded XML invoice file')
            . $prop('DocumentType', 'INVOICE')
            . $prop('Version', 'The actual version of the Factur-X XML schema')
            . $prop('ConformanceLevel', 'The conformance level of the embedded Factur-X data')
            . '</rdf:Seq></pdfaSchema:property></rdf:li></rdf:Bag></pdfaExtension:schemas></rdf:Description>';
    }
}
