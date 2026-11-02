<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Nummernkreise für Rechnungen, Stornorechnungen und Angebote.
 * Das Format ist frei gestaltbar, z. B. „RE{JAHR}-{NR}“, „{JJ}{MONAT}-{NR}“ oder „RE-{NR}“ (dann läuft die Zählung ohne Jahreswechsel durch).
 * Es gibt keinen separaten Zähler: Die nächste Nummer ist die höchste vorhandene Nummer desselben Kreises + 1.
 * Ein Kreis wird durch die konkreten Werte der Datumsvariablen bestimmt – mit {JAHR} im Format beginnt die Zählung jedes Jahr neu.
 * „Nächste Nummer“ (z. B. 341) hebt den Zähler einmalig an, etwa beim Umstieg aus einem laufenden Betrieb.
 */
final class Numbering
{
    /** Typ => [Konfigurations-Präfix, Bezeichnung, Standard-Präfix, Tabelle] */
    public const TYPES = [
        'invoice' => ['INVOICE', 'Rechnungen', 'RE', 'invoices'],
        'cancel' => ['CANCEL', 'Stornorechnungen', 'ST', 'invoices'],
        'offer' => ['OFFER', 'Angebote', 'AN', 'offers'],
    ];
    public const VARS = [
        'NR' => 'laufende Nummer (Pflicht, genau einmal)',
        'JAHR' => 'Jahr vierstellig, z. B. 2026',
        'JJ' => 'Jahr zweistellig, z. B. 26',
        'MONAT' => 'Monat zweistellig, z. B. 03',
        'TAG' => 'Tag zweistellig, z. B. 07',
    ];
    public const MAX_LEN = 30;   // Spaltenbreite der Belegnummer
    public const MAX_NR = 99999999;

    public static function format(string $type): string
    {
        [$p, , $def] = self::TYPES[$type];
        $f = env($p . '_FORMAT');
        return $f !== '' ? $f : env($p . '_PREFIX', $def) . '-{JAHR}-{NR}';
    }

    public static function pad(string $type): int
    {
        $v = env(self::TYPES[$type][0] . '_PAD', '4');
        return ctype_digit($v) ? max(1, min(10, (int)$v)) : 4;
    }

    /** @return array<string,string> */
    private static function tokens(string $date): array
    {
        $t = strtotime($date) ?: time();
        return ['JAHR' => date('Y', $t), 'JJ' => date('y', $t), 'MONAT' => date('m', $t), 'TAG' => date('d', $t)];
    }

    /** Teilt das Format bei {NR} und füllt die Datumsvariablen. @return array{0:string,1:string} Text vor und nach der Nummer */
    private static function parts(string $format, string $date): array
    {
        $tok = self::tokens($date);
        $fill = static fn(string $s): string => (string)preg_replace_callback('/\{([A-Za-z]+)\}/', static fn($m) => $tok[strtoupper($m[1])] ?? $m[0], $s);
        [$a, $b] = explode('{NR}', (string)preg_replace('/\{nr\}/i', '{NR}', $format), 2) + ['', ''];
        return [$fill($a), $fill($b)];
    }

    public static function render(string $format, int $pad, string $date, int $nr): string
    {
        [$a, $b] = self::parts($format, $date);
        return $a . str_pad((string)$nr, $pad, '0', STR_PAD_LEFT) . $b;
    }

    /** Prüft ein Format; gibt eine Fehlermeldung oder null zurück. */
    public static function validate(string $format, int $pad, string $label): ?string
    {
        if ($format === '' || strlen($format) > 60) {
            return $label . ': Format fehlt oder ist zu lang (höchstens 60 Zeichen).';
        }
        if (preg_match_all('/\{nr\}/i', $format) !== 1) {
            return $label . ': Das Format muss {NR} genau einmal enthalten.';
        }
        preg_match_all('/\{([^{}]*)\}/', $format, $m);
        foreach ($m[1] as $name) {
            if (!isset(self::VARS[strtoupper($name)])) {
                return $label . ': unbekannte Variable {' . $name . '}. Erlaubt: ' . implode(', ', array_map(static fn($v) => '{' . $v . '}', array_keys(self::VARS))) . '.';
            }
        }
        $literal = (string)preg_replace('/\{[^{}]*\}/', '', $format);
        if (!preg_match('/^[A-Za-z0-9._-]*$/D', $literal)) {
            return $label . ': Außerhalb der Variablen sind nur Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich erlaubt.';
        }
        if (preg_match('/\{nr\}[0-9]|[0-9]\{nr\}|\{nr\}\{(jahr|jj|monat|tag)\}|\{(jahr|jj|monat|tag)\}\{nr\}/i', $format)) {
            return $label . ': Zwischen {NR} und Ziffern bzw. Datumsvariablen muss ein Trenner stehen (z. B. Bindestrich), sonst ist die Nummer nicht eindeutig lesbar.';
        }
        if (strlen(self::render($format, $pad, '2026-12-31', self::MAX_NR)) > self::MAX_LEN) {
            return $label . ': Die Nummer wäre länger als ' . self::MAX_LEN . ' Zeichen.';
        }
        return null;
    }

    /** Höchste vorhandene Nummer im Kreis des Belegdatums (0 = keine). */
    private static function highest(string $type, string $date, \PDO $pdo, bool $lock): int
    {
        [$a, $b] = self::parts(self::format($type), $date);
        $table = self::TYPES[$type][3];   // feste Liste
        $esc = static fn(string $s): string => addcslashes($s, '%_\\');
        $st = $pdo->prepare("SELECT number FROM $table WHERE number LIKE ?" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$esc($a) . '%' . $esc($b)]);
        $re = '/^' . preg_quote($a, '/') . '(\d{1,10})' . preg_quote($b, '/') . '$/D';
        $max = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $n) {
            if (preg_match($re, (string)$n, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $max;
    }

    public static function start(string $type): int
    {
        $v = env(self::TYPES[$type][0] . '_NEXT');
        return ctype_digit($v) ? min(self::MAX_NR, (int)$v) : 0;
    }

    /** Vergibt die nächste Nummer (innerhalb der Transaktion des Aufrufers, sperrt die Zeilen). @return array{0:string,1:int} Nummer und laufender Anteil */
    public static function next(string $type, string $date, \PDO $pdo): array
    {
        $nr = max(self::highest($type, $date, $pdo, true) + 1, self::start($type), 1);
        return [self::render(self::format($type), self::pad($type), $date, $nr), $nr];
    }

    /** Nach erfolgreichem Ausstellen: die einmalige „Nächste Nummer“ ist verbraucht. */
    public static function consumed(string $type, int $nr): void
    {
        $key = self::TYPES[$type][0] . '_NEXT';
        if (self::start($type) > 0 && $nr >= self::start($type)) {
            Config::save([$key => '']);
        }
    }

    /** Vorschau (ohne Sperre) für die Einstellungen: so lautet die nächste Nummer, wenn heute ausgestellt wird. */
    public static function preview(string $type): string
    {
        $d = date('Y-m-d');
        $nr = max(self::highest($type, $d, db(), false) + 1, self::start($type), 1);
        return self::render(self::format($type), self::pad($type), $d, $nr);
    }
}
