<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Zahlung per PayPal.me-Link: kein API-Zugriff, nur ein Link mit vorausgefülltem Betrag (und QR-Code dazu).
 * Der Zahlungseingang wird weiterhin von Hand verbucht.
 */
final class PayPal
{
    /** Bereinigt die Eingabe (Name oder paypal.me-Link) zum reinen Namen; null = ungültig, '' = leer. */
    public static function normalize(string $in): ?string
    {
        $in = trim($in);
        if ($in === '') {
            return '';
        }
        if (preg_match('~^(?:https?://)?(?:www\.)?paypal\.me/([A-Za-z0-9]+)(?:/.*)?$~i', $in, $m)) {
            $in = $m[1];
        }
        return preg_match('/^[A-Za-z0-9]{3,20}$/D', $in) ? $in : null;
    }

    /** Zahlungslink für diese Rechnung oder null (nicht eingerichtet, Storno, Betrag ≤ 0). */
    public static function url(array $inv): ?string
    {
        $name = self::normalize((string)($inv['co']['paypal'] ?? ''));
        $gross = isset($inv['totals']['gross']) ? round((float)$inv['totals']['gross'], 2) : 0.0;
        if ($name === null || $name === '' || $gross < 0.01 || $gross > 999999.99 || !empty($inv['cancels_id'])) {
            return null;
        }
        return 'https://www.paypal.me/' . $name . '/' . number_format($gross, 2, '.', '') . 'EUR';
    }

    public static function qrDataUri(string $url, int $px = 180): string
    {
        return GiroCode::svgDataUri($url, $px);
    }
}
