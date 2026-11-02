<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Eigene Schriften (TrueType): Upload in den Einstellungen, Einsatz im PDF-Design (mPDF, eingebettet) und in der Oberfläche
 * (@font-face von der eigenen Adresse, keine externen Dienste). Schriftfamilien stehen als JSON in config.php (CUSTOM_FONTS),
 * die Dateien in storage/fonts/ (nicht direkt abrufbar).
 */
final class Fonts
{
    public const MAX_BYTES = 4 * 1024 * 1024;
    public const MAX_FAMILIES = 6;
    public const STYLES = ['R' => 'Regular', 'B' => 'Fett', 'I' => 'Kursiv', 'BI' => 'Fett kursiv'];

    public static function dir(): string
    {
        return dirname(__DIR__) . '/storage/fonts';
    }

    /** @return array<string,array{name:string,files:array<string,string>}> Schlüssel (cf + 8 Hex) => Familie */
    public static function all(): array
    {
        $raw = json_decode(env('CUSTOM_FONTS', '[]'), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $key => $f) {
            if (!is_string($key) || !preg_match('/^cf[0-9a-f]{8}$/D', $key) || !is_array($f) || !is_string($f['name'] ?? null) || !is_array($f['files'] ?? null)) {
                continue;
            }
            $files = [];
            foreach (self::STYLES as $s => $_) {
                if (isset($f['files'][$s]) && is_string($f['files'][$s]) && preg_match('/^[0-9a-f]{16}\.ttf$/D', $f['files'][$s]) && is_file(self::dir() . '/' . $f['files'][$s])) {
                    $files[$s] = $f['files'][$s];
                }
            }
            if (isset($files['R'])) {
                $out[$key] = ['name' => $f['name'], 'files' => $files];
            }
        }
        return $out;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** Schriftname => Anzeigename für PDF-Design (eingebaute Schriften zuerst). */
    public static function pdfChoices(): array
    {
        $o = PdfDesign::FONTS;
        foreach (self::all() as $k => $f) {
            $o[$k] = $f['name'] . ' (eigene Schrift)';
        }
        return $o;
    }

    /** Aktuell für die Oberfläche gewählte eigene Schrift oder ''. */
    public static function uiKey(): string
    {
        $k = env('UI_FONT');
        return $k !== '' && self::exists($k) ? $k : '';
    }

    /** @return array{0:array<string,mixed>} zusätzliche mPDF-Optionen, wenn $fontKey eine eigene Schrift ist, sonst leer */
    public static function mpdfConfig(string $fontKey): array
    {
        $all = self::all();
        if (!isset($all[$fontKey])) {
            return [];
        }
        $map = ['R' => 'R', 'B' => 'B', 'I' => 'I', 'BI' => 'BI'];
        $data = [];
        foreach ($map as $s => $m) {
            if (isset($all[$fontKey]['files'][$s])) {
                $data[$m] = $all[$fontKey]['files'][$s];
            }
        }
        $dirs = (new ConfigVariables())->getDefaults()['fontDir'];
        $dirs[] = self::dir();
        $fonts = (new FontVariables())->getDefaults()['fontdata'];
        $fonts[$fontKey] = $data;
        return ['fontDir' => $dirs, 'fontdata' => $fonts];
    }

    /** CSS für die Oberfläche (@font-face + Schriftart) oder ''. Enthält nur geprüfte Werte. */
    public static function uiCss(): string
    {
        $k = self::uiKey();
        if ($k === '') {
            return '';
        }
        $css = '';
        foreach (self::all()[$k]['files'] as $s => $_) {
            $css .= '@font-face{font-family:"' . $k . '";src:url("' . BASE . '?p=font&k=' . $k . '&s=' . $s . '") format("truetype");font-weight:' . (str_contains($s, 'B') ? '700' : '400')
                . ';font-style:' . (str_contains($s, 'I') ? 'italic' : 'normal') . ';font-display:swap}';
        }
        return $css . 'body,button,input,select,textarea{font-family:"' . $k . '",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}';
    }

    /** Liefert die Datei einer Schrift (für die Oberfläche) oder null. */
    public static function path(string $key, string $style): ?string
    {
        $f = self::all()[$key]['files'][$style] ?? null;
        return $f !== null ? self::dir() . '/' . $f : null;
    }

    /**
     * Prüft eine TrueType-Datei (Inhalt, nicht Dateiname): sfnt-Kennung, Pflichttabellen, Einbettungsrecht (OS/2 fsType).
     * @return string Familienname aus der Datei (für Vorschlag) oder ''
     */
    public static function inspect(string $bytes): string
    {
        if (strlen($bytes) < 64) {
            throw new \RuntimeException('Die Datei ist zu klein für eine Schrift.');
        }
        $tag = substr($bytes, 0, 4);
        if ($tag === 'OTTO') {
            throw new \RuntimeException('Diese OpenType-Schrift (CFF/PostScript-Umrisse) wird für das PDF nicht unterstützt. Bitte die TrueType-Variante (.ttf) verwenden.');
        }
        if ($tag === 'ttcf') {
            throw new \RuntimeException('Schriftsammlungen (.ttc) werden nicht unterstützt. Bitte eine einzelne .ttf-Datei hochladen.');
        }
        if ($tag !== "\x00\x01\x00\x00" && $tag !== 'true') {
            throw new \RuntimeException('Das ist keine TrueType-Schrift (.ttf).');
        }
        $n = unpack('n', substr($bytes, 4, 2))[1];
        if ($n < 5 || $n > 64 || strlen($bytes) < 12 + 16 * $n) {
            throw new \RuntimeException('Die Schriftdatei ist beschädigt.');
        }
        $tables = [];
        for ($i = 0; $i < $n; $i++) {
            $rec = substr($bytes, 12 + 16 * $i, 16);
            $t = unpack('Noff/Nlen', substr($rec, 8, 8));
            if ($t['off'] + $t['len'] > strlen($bytes)) {
                throw new \RuntimeException('Die Schriftdatei ist beschädigt (Tabelle außerhalb der Datei).');
            }
            $tables[substr($rec, 0, 4)] = $t;
        }
        foreach (['cmap', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp'] as $need) {
            if (!isset($tables[$need])) {
                throw new \RuntimeException('Die Schrift ist unvollständig (Tabelle „' . $need . '“ fehlt) – keine nutzbare TrueType-Schrift.');
            }
        }
        if (isset($tables['OS/2']) && $tables['OS/2']['len'] >= 10) {
            $fsType = unpack('n', substr($bytes, $tables['OS/2']['off'] + 8, 2))[1];
            if (($fsType & 0x0002) !== 0) {
                throw new \RuntimeException('Die Lizenz dieser Schrift verbietet das Einbetten in Dokumente (fsType „Restricted“). Sie kann nicht für PDFs verwendet werden.');
            }
        }
        $name = '';
        if (isset($tables['name']) && $tables['name']['len'] > 6) {
            $nt = substr($bytes, $tables['name']['off'], min($tables['name']['len'], 65536));
            $cnt = unpack('n', substr($nt, 2, 2))[1];
            $so = unpack('n', substr($nt, 4, 2))[1];
            for ($i = 0; $i < min($cnt, 200); $i++) {
                $r = substr($nt, 6 + 12 * $i, 12);
                if (strlen($r) < 12) {
                    break;
                }
                $v = unpack('npid/neid/nlid/nnid/nlen/noff', $r);
                if ($v['nid'] === 1 && ($v['pid'] === 3 || $v['pid'] === 0)) {
                    $name = (string)mb_convert_encoding(substr($nt, $so + $v['off'], $v['len']), 'UTF-8', 'UTF-16BE');
                    break;
                }
            }
        }
        return preg_replace('/[^\p{L}\p{N} .\-]/u', '', $name) ?? '';
    }

    private static function validName(string $n): string
    {
        $n = trim((string)preg_replace('/\s+/u', ' ', clean($n)));
        if ($n === '' || mb_strlen($n) > 40 || !preg_match('/^[\p{L}\p{N} .\-]+$/u', $n)) {
            throw new \RuntimeException('Der Schriftname darf 1–40 Zeichen lang sein (Buchstaben, Ziffern, Leerzeichen, Punkt, Bindestrich).');
        }
        return $n;
    }

    /**
     * Nimmt eine neue Familie an. $uploads: Stil (R/B/I/BI) => $_FILES-Eintrag. Regular ist Pflicht.
     * Gibt den Schlüssel zurück. Alles oder nichts: bei einem Fehler bleiben keine Dateien liegen.
     */
    public static function add(string $name, array $uploads): string
    {
        $name = self::validName($name);
        $all = self::all();
        if (count($all) >= self::MAX_FAMILIES) {
            throw new \RuntimeException('Es sind höchstens ' . self::MAX_FAMILIES . ' eigene Schriften möglich. Bitte zuerst eine löschen.');
        }
        foreach ($all as $f) {
            if (mb_strtolower($f['name']) === mb_strtolower($name)) {
                throw new \RuntimeException('Eine Schrift mit diesem Namen gibt es schon.');
            }
        }
        $blobs = [];
        foreach (self::STYLES as $s => $label) {
            $up = $uploads[$s] ?? null;
            $err = (int)($up['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                if ($s === 'R') {
                    throw new \RuntimeException('Die Datei für „Regular“ fehlt.');
                }
                continue;
            }
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new \RuntimeException($label . ': Die Datei ist größer als das Upload-Limit des Servers (' . ini_get('upload_max_filesize') . ').');
            }
            $tmp = (string)($up['tmp_name'] ?? '');
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                throw new \RuntimeException($label . ': Der Upload ist fehlgeschlagen.');
            }
            if ((int)filesize($tmp) > self::MAX_BYTES) {
                throw new \RuntimeException($label . ': Die Datei darf höchstens ' . (int)(self::MAX_BYTES / 1048576) . ' MB groß sein.');
            }
            $bytes = (string)file_get_contents($tmp);
            try {
                self::inspect($bytes);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException($label . ': ' . $e->getMessage());
            }
            $blobs[$s] = $bytes;
        }
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Ordner storage/fonts konnte nicht angelegt werden.');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.html', '');
        }
        $key = 'cf' . bin2hex(random_bytes(4));
        $files = [];
        try {
            foreach ($blobs as $s => $bytes) {
                $fn = bin2hex(random_bytes(8)) . '.ttf';
                if (file_put_contents($dir . '/' . $fn, $bytes) === false) {
                    throw new \RuntimeException('Die Schrift konnte nicht gespeichert werden (Schreibrechte auf storage/?).');
                }
                $files[$s] = $fn;
            }
            self::trial($key, $files);
        } catch (\Throwable $e) {
            foreach ($files as $fn) {
                // Geprüft: Dateiname stammt aus bin2hex(random_bytes()) und liegt im festen Schriftordner
                // nosemgrep
                @unlink($dir . '/' . $fn);
            }
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException('Die Schrift lässt sich im PDF nicht verwenden: ' . $e->getMessage());
        }
        $all[$key] = ['name' => $name, 'files' => $files];
        Config::save(['CUSTOM_FONTS' => json_encode($all, JSON_UNESCAPED_UNICODE)]);
        return $key;
    }

    /** Probe: Die Schrift wird tatsächlich in ein (PDF/A-)Dokument eingebettet – so zeigt sich ein Problem schon beim Upload. */
    private static function trial(string $key, array $files): void
    {
        $tmp = dirname(__DIR__) . '/storage/tmp';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }
        $dirs = (new ConfigVariables())->getDefaults()['fontDir'];
        $dirs[] = self::dir();
        $fonts = (new FontVariables())->getDefaults()['fontdata'];
        $fonts[$key] = $files;
        $m = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tmp, 'fontDir' => $dirs, 'fontdata' => $fonts,
            'default_font' => $key, 'PDFA' => true, 'PDFAauto' => true, 'PDFAversion' => '3-B']);
        $m->WriteHTML('<p>Rechnung 0123456789 – äöüß € <b>fett</b> <i>kursiv</i></p>');
        if (strlen($m->Output('', 'S')) < 500) {
            throw new \RuntimeException('Die Schrift ließ sich nicht in ein PDF einbetten.');
        }
    }

    /** Verwendungen einer Schrift (aktuelles PDF-Design, Oberfläche, ausgestellte Belege). @return list<string> */
    public static function usage(string $key): array
    {
        $u = [];
        if (PdfDesign::current()['font'] === $key) {
            $u[] = 'aktuelles PDF-Design';
        }
        if (env('UI_FONT') === $key) {
            $u[] = 'Oberfläche';
        }
        foreach (['invoices' => 'Rechnung(en)', 'offers' => 'Angebot(e)'] as $t => $label) {
            $st = db()->prepare("SELECT COUNT(*) FROM $t WHERE snapshot LIKE ?");   // $t stammt aus der festen Liste oben
            $st->execute(['%"font":"' . $key . '"%']);
            if (($n = (int)$st->fetchColumn()) > 0) {
                $u[] = $n . ' ' . $label;
            }
        }
        return $u;
    }

    public static function remove(string $key): void
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new \RuntimeException('Schrift nicht gefunden.');
        }
        if ($u = self::usage($key)) {
            throw new \RuntimeException('„' . $all[$key]['name'] . '“ wird noch verwendet (' . implode(', ', $u) . ') und kann nicht gelöscht werden. Bereits ausgestellte Belege behalten ihre Schrift.');
        }
        foreach ($all[$key]['files'] as $fn) {
            // Geprüft: Dateiname aus der eigenen Konfiguration, auf [0-9a-f]{16}.ttf geprüft (all())
            // nosemgrep
            @unlink(self::dir() . '/' . $fn);
        }
        unset($all[$key]);
        Config::save(['CUSTOM_FONTS' => json_encode($all, JSON_UNESCAPED_UNICODE)]);
    }
}
