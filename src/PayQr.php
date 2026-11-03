<?php
declare(strict_types=1);

namespace App;

/**
 * Steuert, welche Zahlungs-QR-Codes (GiroCode, PayPal.me) auf PDF und Statusseite erscheinen.
 * Rein darstellend: Die Rechnungsdaten und der eingefrorene PayPal-Name bleiben unverändert.
 */
final class PayQr
{
    /** PDF: Einstellung PAY_QR_PDF */
    public const PDF = [
        'giro_link' => 'GiroCode-QR, PayPal nur als Link (empfohlen)',
        'giro'      => 'nur GiroCode-QR',
        'paypal'    => 'nur PayPal-QR',
        'both'      => 'beide QR-Codes',
        'none'      => 'kein QR-Code (nur Text und PayPal-Link)',
    ];
    /** Statusseite: Einstellung PAY_QR_STATUS */
    public const STATUS = [
        'choice' => 'Auswahl für den Besucher (immer nur ein QR-Code sichtbar)',
        'giro'   => 'nur GiroCode',
        'paypal' => 'nur PayPal',
        'both'   => 'beide untereinander',
    ];

    public static function pdfMode(): string
    {
        $m = env('PAY_QR_PDF', 'giro_link');
        return isset(self::PDF[$m]) ? $m : 'giro_link';
    }

    public static function statusMode(): string
    {
        $m = env('PAY_QR_STATUS', 'choice');
        return isset(self::STATUS[$m]) ? $m : 'choice';
    }

    /**
     * @return array{giro:bool, pp:?string} pp = 'qr' | 'link' | null
     */
    public static function pdf(bool $hasGiro, bool $hasPayPal, ?string $mode = null): array
    {
        $mode ??= self::pdfMode();
        $giro = $hasGiro && in_array($mode, ['giro', 'giro_link', 'both'], true);
        $pp = null;
        if ($hasPayPal) {
            $pp = match ($mode) {
                'paypal', 'both' => 'qr',
                'giro_link', 'none' => 'link',
                default => null,
            };
        }
        // Gewünschter Code nicht verfügbar (z. B. nur PayPal gewählt, aber kein PayPal eingerichtet): GiroCode als Ersatz
        if ($mode === 'paypal' && !$hasPayPal && $hasGiro) {
            $giro = true;
        }
        return ['giro' => $giro, 'pp' => $pp];
    }

    /**
     * @param string $req Wunsch des Besuchers (?pay=giro|paypal), nur im Modus "choice" wirksam
     * @return array{giro:bool, paypal:bool, tabs:bool, active:string}
     */
    public static function status(bool $hasGiro, bool $hasPayPal, string $req = '', ?string $mode = null): array
    {
        $mode ??= self::statusMode();
        $both = $hasGiro && $hasPayPal;
        if (!$both) {
            return ['giro' => $hasGiro, 'paypal' => $hasPayPal, 'tabs' => false, 'active' => $hasGiro ? 'giro' : 'paypal'];
        }
        return match ($mode) {
            'giro' => ['giro' => true, 'paypal' => false, 'tabs' => false, 'active' => 'giro'],
            'paypal' => ['giro' => false, 'paypal' => true, 'tabs' => false, 'active' => 'paypal'],
            'both' => ['giro' => true, 'paypal' => true, 'tabs' => false, 'active' => 'giro'],
            default => (static function () use ($req): array {
                $a = $req === 'paypal' ? 'paypal' : 'giro';
                return ['giro' => $a === 'giro', 'paypal' => $a === 'paypal', 'tabs' => true, 'active' => $a];
            })(),
        };
    }
}
