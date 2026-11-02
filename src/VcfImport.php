<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Liest vCard-Dateien (.vcf, Version 2.1/3.0/4.0, mehrere Kontakte je Datei) und wandelt sie in Tabellenzeilen,
 * die anschließend denselben Weg gehen wie ein CSV-Import (Spaltenzuordnung, Vorschau, Duplikaterkennung).
 */
final class VcfImport
{
    public const MAX_BYTES = 10 * 1024 * 1024;   // Kontaktfotos machen vCards groß

    public const HEADER = ['Name', 'Ansprechpartner', 'E-Mail', 'Straße', 'PLZ', 'Ort', 'Land'];

    public static function looksLikeVcard(string $text): bool
    {
        return (bool)preg_match('/^\s*BEGIN:VCARD/i', $text);
    }

    /** Rückgabe: Überschriftenzeile + eine Zeile je Kontakt. @return list<list<string>> */
    public static function toRows(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);   // Zeichensatz wird je Wert behandelt (CHARSET=…)
        if (str_contains($text, "\0")) {
            throw new \RuntimeException('Das ist keine vCard-Datei.');
        }
        $rows = [self::HEADER];
        foreach (self::cards($text) as $props) {
            $rows[] = self::row($props);
            if (count($rows) > CustomerImport::MAX_ROWS + 1) {
                throw new \RuntimeException('Zu viele Kontakte (höchstens ' . CustomerImport::MAX_ROWS . ' je Import). Bitte die Datei aufteilen.');
            }
        }
        if (count($rows) === 1) {
            throw new \RuntimeException('In der Datei wurden keine Kontakte (BEGIN:VCARD) gefunden.');
        }
        return $rows;
    }

    /** Als CSV-Text (Semikolon, UTF-8) für die Zwischenspeicherung. */
    public static function toCsv(array $rows): string
    {
        $fh = fopen('php://memory', 'r+');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ';', '"', '');
        }
        rewind($fh);
        return (string)stream_get_contents($fh);
    }

    /** Zerlegt in Karten; jede Karte ist eine Liste von [NAME, [Parameter], Wert]. @return list<list<array{0:string,1:list<string>,2:string}>> */
    private static function cards(string $text): array
    {
        // Quoted-printable (vCard 2.1): Zeile endet mit "=" und wird in der nächsten fortgesetzt (nur bei solchen Zeilen)
        $joined = [];
        $qp = false;
        foreach (explode("\n", $text) as $line) {
            if ($qp) {
                $joined[count($joined) - 1] .= $line;
            } else {
                $joined[] = $line;
            }
            $last = $joined[count($joined) - 1];
            $qp = stripos(strtok($last, ':') ?: '', 'QUOTED-PRINTABLE') !== false && str_ends_with($last, '=');
            if ($qp) {
                $joined[count($joined) - 1] = substr($last, 0, -1);
            }
        }
        $text = implode("\n", $joined);
        // Zeilenfaltung: Fortsetzungszeile beginnt mit Leerzeichen/Tab
        $text = (string)preg_replace('/\n[ \t]/', '', $text);
        $cards = [];
        $cur = null;
        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (strcasecmp($line, 'BEGIN:VCARD') === 0) {
                $cur = [];
                continue;
            }
            if (strcasecmp($line, 'END:VCARD') === 0) {
                if ($cur !== null) {
                    $cards[] = $cur;
                }
                $cur = null;
                continue;
            }
            if ($cur === null || !str_contains($line, ':')) {
                continue;
            }
            [$left, $value] = explode(':', $line, 2);
            $parts = explode(';', $left);
            $name = strtoupper((string)array_shift($parts));
            if (str_contains($name, '.')) {
                $name = substr($name, strrpos($name, '.') + 1);   // Gruppe "item1.EMAIL"
            }
            if (!in_array($name, ['N', 'FN', 'ORG', 'EMAIL', 'ADR'], true)) {
                continue;   // Fotos, Telefonnummern usw. werden nicht benötigt
            }
            $params = array_map('strtoupper', $parts);
            $cur[] = [$name, $params, self::decodeValue($value, $params)];
        }
        return $cards;
    }

    private static function decodeValue(string $v, array $params): string
    {
        $qp = false;
        $charset = 'UTF-8';
        foreach ($params as $p) {
            if ($p === 'ENCODING=QUOTED-PRINTABLE' || $p === 'QUOTED-PRINTABLE') {
                $qp = true;
            }
            if (str_starts_with($p, 'CHARSET=')) {
                $charset = substr($p, 8);
            }
        }
        if ($qp) {
            $v = quoted_printable_decode($v);
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            $cs = strtoupper($charset);
            $from = in_array($cs, ['ISO-8859-1', 'LATIN1', 'ISO-8859-15'], true) ? ($cs === 'ISO-8859-15' ? 'ISO-8859-15' : 'ISO-8859-1') : 'Windows-1252';
            $v = (string)mb_convert_encoding($v, 'UTF-8', $from);
        }
        return $v;
    }

    /** Teilt an unmaskierten Semikola/Kommas und löst \n, \, \; \\ auf. @return list<string> */
    private static function split(string $v, string $sep): array
    {
        $out = [];
        foreach (preg_split('/(?<!\\\\)' . preg_quote($sep, '/') . '/', $v) ?: [] as $part) {
            $out[] = trim(strtr($part, ['\\n' => ' ', '\\N' => ' ', '\\,' => ',', '\;' => ';', '\\\\' => '\\']));
        }
        return $out;
    }

    private static function pref(array $list, string $prefer = 'WORK'): ?array
    {
        foreach ($list as $e) {
            foreach ($e[1] as $p) {
                if (str_contains($p, $prefer)) {
                    return $e;
                }
            }
        }
        return $list[0] ?? null;
    }

    /** @param list<array{0:string,1:list<string>,2:string}> $props @return list<string> */
    private static function row(array $props): array
    {
        $by = ['N' => [], 'FN' => [], 'ORG' => [], 'EMAIL' => [], 'ADR' => []];
        foreach ($props as $p) {
            $by[$p[0]][] = $p;
        }
        $person = '';
        if ($by['N']) {
            $n = self::split($by['N'][0][2], ';');   // Nachname;Vorname;weitere;Titel;Zusatz
            $person = trim(implode(' ', array_filter([$n[3] ?? '', $n[1] ?? '', $n[2] ?? '', $n[0] ?? '', $n[4] ?? ''], static fn($x) => $x !== '')));
        }
        if ($by['FN']) {
            $fn = self::split($by['FN'][0][2], ';')[0] ?? '';
            if ($fn !== '') {
                $person = $fn;
            }
        }
        $org = $by['ORG'] ? trim(self::split($by['ORG'][0][2], ';')[0] ?? '') : '';
        // Firma vorhanden: Firma = Kunde, Person = Ansprechpartner. Sonst ist die Person selbst der Kunde.
        $name = $org !== '' ? $org : $person;
        $contact = $org !== '' ? $person : '';
        $mail = $by['EMAIL'] ? trim(self::split((self::pref($by['EMAIL']) ?? ['', [], ''])[2], ';')[0] ?? '') : '';
        $street = $zip = $city = $country = '';
        if ($by['ADR']) {
            $a = self::split((self::pref($by['ADR']) ?? ['', [], ''])[2], ';');   // Postfach;Zusatz;Straße;Ort;Region;PLZ;Land
            $street = trim(($a[2] ?? '') . (($a[1] ?? '') !== '' && ($a[2] ?? '') !== '' ? ', ' . $a[1] : ($a[1] ?? '')));
            $city = $a[3] ?? '';
            $zip = $a[5] ?? '';
            $country = $a[6] ?? '';
        }
        return [$name, $contact, $mail, $street, $zip, $city, $country];
    }
}
