<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/** Eigenes CSS für die Oberfläche (storage/custom.css). Wird als <style> nach dem Standard-Design eingebunden. */
final class Theme
{
    public const MAX_BYTES = 50000;

    public const TEMPLATE = <<<'CSS'
/* metaRechnung – eigene Gestaltung
   Entferne die Kommentarzeichen vor einer Zeile und ändere den Wert. */

:root {
  /* --pri: #7a4589;       Hauptfarbe (Buttons, Links)        */
  /* --pri2: #8a52a0;      zweite Farbe des Verlaufs          */
  /* --pri-soft: #f1e8f4;  helle Fläche zur Hauptfarbe        */
  /* --bg: #f4e9db;        Seitenhintergrund                  */
  /* --card: #ffffff;      Karten und Tabellen                */
  /* --fg: #1f1520;        Textfarbe                          */
  /* --mut: #6b6270;       gedämpfter Text                    */
  /* --line: #e8dccb;      Linien                             */
  /* --r: 14px;            Rundung der Karten                 */
}

/* Dunkler Modus (wenn das Gerät auf „dunkel“ steht) */
@media (prefers-color-scheme: dark) {
  :root {
    /* --pri: #c99bd9; */
    /* --bg: #170d15;  */
    /* --card: #211520; */
  }
}

/* Beispiel: größere Schrift
body { font-size: 17px; } */
CSS;

    public static function path(): string
    {
        return dirname(__DIR__) . '/storage/custom.css';
    }

    /** Gespeichertes CSS ('' wenn keines). */
    public static function get(): string
    {
        $f = self::path();
        return is_file($f) ? (string)file_get_contents($f) : '';
    }

    /** Wird für die Seite ausgegeben? (Notausgang: ?nocss=1 schaltet das eigene CSS ab.) */
    public static function active(): bool
    {
        return !isset($_GET['nocss']);
    }

    /** Prüft das CSS und gibt die bereinigte Fassung zurück; wirft RuntimeException mit verständlicher Meldung. */
    public static function validate(mixed $in): string
    {
        if (!is_string($in)) {
            throw new \RuntimeException('Ungültige Eingabe.');
        }
        $css = str_replace(["\r\n", "\r"], "\n", $in);
        $css = clean($css, true);
        if (strlen($css) > self::MAX_BYTES) {
            throw new \RuntimeException('Das CSS ist zu lang (höchstens ' . (self::MAX_BYTES / 1000) . ' KB).');
        }
        if (str_contains($css, '<')) {
            throw new \RuntimeException('Das Zeichen „<“ ist im CSS nicht erlaubt (Schutz vor HTML-Einschleusung).');
        }
        if (preg_match('/@import|expression\s*\(|javascript\s*:|-moz-binding|behavior\s*:/i', $css, $m)) {
            throw new \RuntimeException('„' . trim($m[0]) . '“ ist nicht erlaubt. Schriften und Bilder nur lokal oder als data:-URL einbinden (Sicherheitsrichtlinie der App).');
        }
        // Klammern prüfen (Kommentare und Zeichenketten ausgenommen), damit ein Tippfehler nicht das ganze Design zerlegt
        $plain = (string)preg_replace('~/\*.*?\*/|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'~s', '', $css);
        $depth = 0;
        foreach (str_split($plain) as $ch) {
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}' && --$depth < 0) {
                throw new \RuntimeException('Zu viele schließende Klammern „}“.');
            }
        }
        if ($depth !== 0) {
            throw new \RuntimeException('Es fehlen ' . $depth . ' schließende Klammer(n) „}“.');
        }
        if (substr_count($css, '/*') > substr_count($css, '*/')) {
            throw new \RuntimeException('Ein Kommentar ist nicht geschlossen („*/“ fehlt).');
        }
        return trim($css);
    }

    public static function save(string $css): void
    {
        if ($css === '') {
            self::reset();
            return;
        }
        if (@file_put_contents(self::path(), $css . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Die Datei storage/custom.css konnte nicht geschrieben werden (Schreibrechte auf storage/?).');
        }
    }

    public static function reset(): void
    {
        $f = self::path();
        if (is_file($f)) {
            // Geprüft: Pfad wird serverseitig gebildet (fester Ordner, kein Benutzerpfad)
            // nosemgrep
            @unlink($f);
        }
    }
}
