<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/** Briefpapier (A4-Hintergrund für die Rechnungs-PDFs): PDF, PNG oder JPG. */
final class Letterhead
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_W = 2600;   // A4 bei 300 dpi = 2480 x 3508
    public const MAX_H = 3700;

    public static function dir(): string
    {
        return dirname(__DIR__) . '/storage/letterhead';
    }

    public static function info(): ?array
    {
        $f = env('LETTERHEAD_FILE');
        if ($f === '') {
            return null;
        }
        $path = self::dir() . '/' . basename($f);
        if (!is_file($path)) {
            return null;
        }
        return [
            'path' => $path,
            'file' => basename($f),
            'type' => strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf' ? 'pdf' : 'image',
            'mt' => self::margin(env('LETTERHEAD_MT'), 45),
            'mb' => self::margin(env('LETTERHEAD_MB'), 30),
            'ml' => self::margin(env('LETTERHEAD_ML'), 25),
            'mr' => self::margin(env('LETTERHEAD_MR'), 20),
            'hideSender' => env('LETTERHEAD_HIDE_SENDER', '1') === '1',
            'hideFooter' => env('LETTERHEAD_HIDE_FOOTER') === '1',
        ];
    }

    public static function margin(mixed $v, float $default): float
    {
        $s = str_replace(',', '.', trim((string)$v));
        if ($s === '' || !is_numeric($s)) {
            return $default;
        }
        return max(0.0, min(120.0, (float)$s));
    }

    /** Prüft und speichert den Upload; gibt den Dateinamen zurück. */
    public static function store(array $f): string
    {
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('Die Datei ist größer als das Upload-Limit des Servers (' . ini_get('upload_max_filesize') . ').');
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Der Upload ist fehlgeschlagen (Fehlercode ' . $err . ').');
        }
        $tmp = (string)($f['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw new \RuntimeException('Ungültiger Upload.');
        }
        $size = filesize($tmp);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Die Datei darf höchstens 5 MB groß sein.');
        }

        // Dateityp immer am Inhalt erkennen, nie am Dateinamen
        $head = (string)file_get_contents($tmp, false, null, 0, 12);
        $out = null;
        if (str_starts_with($head, '%PDF')) {
            $ext = 'pdf';
            self::checkPdf($tmp);
        } elseif (str_starts_with($head, "\x89PNG") || str_starts_with($head, "\xFF\xD8\xFF") || str_starts_with($head, 'GIF8')
            || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')) {
            $ext = str_starts_with($head, "\xFF\xD8\xFF") ? 'jpg' : 'png';
            $out = self::flattenImage($tmp, $ext === 'jpg');
        } else {
            throw new \RuntimeException('Erlaubt sind PDF-, PNG- und JPG-Dateien.');
        }

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Ordner storage/letterhead konnte nicht angelegt werden.');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.html', '');
        }
        self::remove();
        $name = 'briefpapier.' . $ext;
        $dest = $dir . '/' . $name;
        $ok = $out === null ? move_uploaded_file($tmp, $dest) : (file_put_contents($dest, $out) !== false);
        if (!$ok) {
            throw new \RuntimeException('Die Datei konnte nicht gespeichert werden (Schreibrechte auf storage/?).');
        }
        return $name;
    }

    public static function remove(): void
    {
        foreach (glob(self::dir() . '/briefpapier.*') ?: [] as $f) {
            // Geprüft: Pfad wird serverseitig gebildet (feste Ordner, kein Benutzerpfad)
            // nosemgrep
            @unlink($f);
        }
    }

    private static function checkPdf(string $path): void
    {
        try {
            $p = new \setasign\Fpdi\Fpdi();
            $p->setSourceFile($path);
            $s = $p->getTemplateSize($p->importPage(1));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Dieses PDF kann nicht als Briefpapier genutzt werden (häufig wegen moderner PDF-Kompression). '
                . 'Bitte als „PDF 1.4“ bzw. „kompatibel/Acrobat 5“ exportieren oder als PNG/JPG hochladen. Technische Meldung: ' . $e->getMessage());
        }
        if (abs($s['width'] - 210) > 3 || abs($s['height'] - 297) > 3) {
            throw new \RuntimeException(sprintf('Das PDF muss A4 hoch sein (210 × 297 mm), gefunden: %.0f × %.0f mm.', $s['width'], $s['height']));
        }
    }

    /** Dekodiert das Bild, legt es auf Weiß (keine Transparenz – wichtig für PDF/A) und kodiert neu. */
    private static function flattenImage(string $path, bool $jpeg): string
    {
        $info = @getimagesize($path);
        if (!$info) {
            throw new \RuntimeException('Die Bilddatei ist nicht lesbar.');
        }
        [$w, $h] = $info;
        if ($w > self::MAX_W || $h > self::MAX_H) {
            throw new \RuntimeException('Das Bild ist zu groß (' . $w . ' × ' . $h . ' px). Maximal ' . self::MAX_W . ' × ' . self::MAX_H . ' px, empfohlen 2480 × 3508 px (A4, 300 dpi).');
        }
        if ($h <= $w || $h / $w < 1.30 || $h / $w > 1.55) {
            throw new \RuntimeException('Das Bild muss ein A4-Hochformat sein (Seitenverhältnis 1 : 1,41), gefunden: ' . $w . ' × ' . $h . ' px.');
        }
        $src = @imagecreatefromstring((string)file_get_contents($path));
        if (!$src) {
            throw new \RuntimeException('Das Bildformat wird vom Server nicht unterstützt.');
        }
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, (int)imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);
        ob_start();
        $jpeg ? imagejpeg($dst, null, 92) : imagepng($dst, null, 6);
        $bytes = (string)ob_get_clean();
        imagedestroy($dst);
        return $bytes;
    }

    /** Beispielrechnung für die Vorschau. */
    public static function sampleInvoice(): array
    {
        $kl = Invoices::kleinunternehmer();
        $items = [
            ['pos' => 1, 'description' => 'Beispielleistung A', 'quantity' => '2', 'unit' => 'HUR', 'unit_price' => '85.00', 'vat_rate' => '19'],
            ['pos' => 2, 'description' => 'Beispielartikel B', 'quantity' => '1', 'unit' => 'C62', 'unit_price' => '49.90', 'vat_rate' => '19'],
        ];
        $inv = [
            'number' => 'VORSCHAU', 'issue_date' => date('Y-m-d'), 'delivery_date' => date('Y-m-d'),
            'due_date' => date('Y-m-d', strtotime('+14 days')), 'notes' => 'Dies ist eine Vorschau mit Beispieldaten.',
            'c_name' => 'Beispielkunde GmbH', 'c_contact' => 'Erika Mustermann', 'c_email' => 'kunde@example.com',
            'c_street' => 'Beispielweg 1', 'c_zip' => '12345', 'c_city' => 'Beispielstadt', 'c_country' => 'DE',
            'c_vat_id' => null, 'c_buyer_ref' => null, 'items' => $items, 'co' => company(), 'kl' => $kl,
        ];
        $inv['totals'] = Invoices::totals($items, $kl);
        return $inv;
    }
}
