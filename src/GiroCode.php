<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

use Mpdf\QrCode\Output\Svg;
use Mpdf\QrCode\QrCode;

/**
 * GiroCode (EPC-QR-Code, EPC069-12 Version 002): Banking-Apps lesen Empfänger, IBAN, Betrag und Verwendungszweck aus
 * und füllen die SEPA-Überweisung vor. Nur für Rechnungen in Euro mit gültiger IBAN und positivem Betrag.
 */
final class GiroCode
{
    public const MAX_BYTES = 331;   // Grenze laut Standard

    public static function enabled(): bool
    {
        return env('GIROCODE', '1') !== '0';
    }

    /** Prüft die IBAN-Prüfsumme (ISO 7064 mod 97-10). */
    public static function validIban(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $num = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $c) {
            $num .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c;
        }
        $rest = 0;
        foreach (str_split($num, 7) as $chunk) {
            $rest = (int)($rest . $chunk) % 97;
        }
        return $rest === 1;
    }

    /** Einzeiliger Text ohne Steuerzeichen, auf Länge (Zeichen) gekürzt. */
    private static function field(string $s, int $max): string
    {
        // Backslash raus: mPDF wertet die Zeichenfolge \n im Barcode-Attribut als Zeilenumbruch (Feld-Injektion)
        $s = trim((string)preg_replace('/[\x00-\x1F\x7F\\\\]+/u', ' ', $s));
        return mb_substr($s, 0, $max);
    }

    /** Inhalt des QR-Codes oder null, wenn für diese Rechnung kein GiroCode sinnvoll ist. */
    public static function payload(array $inv): ?string
    {
        $co = $inv['co'] ?? [];
        $iban = strtoupper((string)preg_replace('/\s+/', '', (string)($co['iban'] ?? '')));
        $gross = isset($inv['totals']['gross']) ? round((float)$inv['totals']['gross'], 2) : 0.0;
        if (!self::validIban($iban) || $gross < 0.01 || $gross > 999999999.99 || empty($inv['number'])) {
            return null;
        }
        $bic = strtoupper((string)preg_replace('/\s+/', '', (string)($co['bic'] ?? '')));
        if (!preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            $bic = '';   // in Version 002 darf die BIC fehlen (SEPA-Raum)
        }
        $name = self::field((string)($co['name'] ?? ''), 70);
        if ($name === '') {
            return null;
        }
        $lines = [
            'BCD', '002', '1', 'SCT', $bic, $name, $iban,
            'EUR' . number_format($gross, 2, '.', ''),
            '', '',                                              // Purpose, strukturierte Referenz (leer)
            self::field('Rechnung ' . $inv['number'], 140),      // Verwendungszweck
        ];
        $payload = implode("\n", $lines);
        return strlen($payload) <= self::MAX_BYTES ? $payload : null;
    }

    /** Zeilenumbrüche für das mPDF-<barcode>-Attribut (HTML-Parser normalisieren echte Umbrüche zu Leerzeichen; mPDF erwartet die Zeichenfolge \n). */
    public static function forMpdf(string $payload): string
    {
        return str_replace("\n", '\\n', $payload);
    }

    /** SVG als data:-URL (für <img>; erlaubt durch die CSP: img-src data:). */
    public static function svgDataUri(string $payload, int $px = 220): string
    {
        $qr = new QrCode($payload, 'M');
        $svg = (new Svg())->output($qr, $px, 'white', 'black');
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
