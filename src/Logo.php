<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/** Firmenlogo für den Briefkopf der Rechnungs-PDFs (Alternative zum Briefpapier): PNG oder JPG. */
final class Logo
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_PX = 3000;

    public static function dir(): string
    {
        return dirname(__DIR__) . '/storage/logo';
    }

    public static function info(): ?array
    {
        $f = env('LOGO_FILE');
        if ($f === '') {
            return null;
        }
        $path = self::dir() . '/' . basename($f);
        if (!is_file($path)) {
            return null;
        }
        $align = env('LOGO_ALIGN', 'right');
        return [
            'path' => $path,
            'file' => basename($f),
            'width' => self::width(env('LOGO_WIDTH'), 50),
            'align' => in_array($align, ['left', 'right'], true) ? $align : 'right',
        ];
    }

    public static function width(mixed $v, float $default): float
    {
        $s = str_replace(',', '.', trim((string)$v));
        if ($s === '' || !is_numeric($s)) {
            return $default;
        }
        return max(10.0, min(120.0, (float)$s));
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
            throw new \RuntimeException('Das Logo darf höchstens 2 MB groß sein.');
        }
        $head = (string)file_get_contents($tmp, false, null, 0, 12);
        $jpeg = str_starts_with($head, "\xFF\xD8\xFF");
        if (!$jpeg && !str_starts_with($head, "\x89PNG") && !str_starts_with($head, 'GIF8')
            && !(str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')) {
            throw new \RuntimeException('Erlaubt sind PNG- und JPG-Dateien (kein SVG).');
        }
        $info = @getimagesize($tmp);
        if (!$info) {
            throw new \RuntimeException('Die Bilddatei ist nicht lesbar.');
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > self::MAX_PX || $h > self::MAX_PX) {
            throw new \RuntimeException('Das Logo ist zu groß (' . $w . ' × ' . $h . ' px). Maximal ' . self::MAX_PX . ' × ' . self::MAX_PX . ' px.');
        }
        $src = @imagecreatefromstring((string)file_get_contents($tmp));
        if (!$src) {
            throw new \RuntimeException('Das Bildformat wird vom Server nicht unterstützt.');
        }
        // Auf Weiß legen (keine Transparenz – wichtig für PDF/A) und neu kodieren; entfernt auch Metadaten.
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, (int)imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);
        ob_start();
        $jpeg ? imagejpeg($dst, null, 92) : imagepng($dst, null, 6);
        $bytes = (string)ob_get_clean();
        imagedestroy($dst);

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Ordner storage/logo konnte nicht angelegt werden.');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.html', '');
        }
        self::remove();
        $name = 'logo.' . ($jpeg ? 'jpg' : 'png');
        if (file_put_contents($dir . '/' . $name, $bytes) === false) {
            throw new \RuntimeException('Die Datei konnte nicht gespeichert werden (Schreibrechte auf storage/?).');
        }
        return $name;
    }

    public static function remove(): void
    {
        foreach (glob(self::dir() . '/logo.*') ?: [] as $f) {
            // Geprüft: Pfad wird serverseitig gebildet (feste Ordner, kein Benutzerpfad)
            // nosemgrep
            @unlink($f);
        }
    }
}
