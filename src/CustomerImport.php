<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/** CSV-Import von Kundendaten: Einlesen (Zeichensatz/Trennzeichen automatisch), Spaltenzuordnung, Prüfung, Duplikaterkennung. */
final class CustomerImport
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS = 5000;
    public const MAX_COLS = 40;

    /** Feld => [Bezeichnung, Maximallänge, Pflicht] */
    public const FIELDS = [
        'name' => ['Firma / Name', 190, true],
        'contact' => ['Ansprechpartner', 190, false],
        'email' => ['E-Mail', 190, false],
        'street' => ['Straße + Nr.', 190, true],
        'zip' => ['PLZ', 20, true],
        'city' => ['Ort', 120, true],
        'country' => ['Land', 2, false],
        'vat_id' => ['USt-IdNr.', 30, false],
        'buyer_reference' => ['Käuferreferenz / Leitweg-ID', 100, false],
    ];

    /** Spaltenüberschriften (kleingeschrieben, ohne Sonderzeichen) => Feld */
    private const SYNONYMS = [
        'name' => ['name', 'firma', 'firmenname', 'kunde', 'kundenname', 'company', 'companyname', 'organisation', 'unternehmen', 'bezeichnung', 'customer'],
        'contact' => ['ansprechpartner', 'kontakt', 'kontaktperson', 'contact', 'contactperson', 'zhd', 'zuhanden', 'ansprechperson'],
        'email' => ['email', 'emailadresse', 'mail', 'emailadr', 'e', 'emailaddress'],
        'street' => ['strasse', 'straße', 'strassenr', 'strassenummer', 'strassehausnummer', 'street', 'adresse', 'anschrift', 'address', 'strassemitnr', 'strassenr'],
        'zip' => ['plz', 'postleitzahl', 'zip', 'zipcode', 'postalcode', 'postcode'],
        'city' => ['ort', 'stadt', 'city', 'ortsname', 'wohnort', 'town'],
        'country' => ['land', 'country', 'laendercode', 'ländercode', 'countrycode', 'staat'],
        'vat_id' => ['ustidnr', 'ustid', 'ustidnummer', 'vatid', 'vat', 'umsatzsteuerid', 'umsatzsteueridnr', 'umsatzsteueridentifikationsnummer', 'ustnr'],
        'buyer_reference' => ['leitwegid', 'leitweg', 'kaeuferreferenz', 'käuferreferenz', 'buyerreference', 'referenz', 'bestellnummer', 'kundenreferenz'],
    ];

    private const COUNTRIES = [
        'deutschland' => 'DE', 'germany' => 'DE', 'oesterreich' => 'AT', 'österreich' => 'AT', 'austria' => 'AT', 'schweiz' => 'CH', 'switzerland' => 'CH',
        'niederlande' => 'NL', 'netherlands' => 'NL', 'holland' => 'NL', 'frankreich' => 'FR', 'france' => 'FR', 'italien' => 'IT', 'italy' => 'IT',
        'spanien' => 'ES', 'spain' => 'ES', 'belgien' => 'BE', 'belgium' => 'BE', 'luxemburg' => 'LU', 'luxembourg' => 'LU', 'daenemark' => 'DK', 'dänemark' => 'DK', 'denmark' => 'DK',
        'polen' => 'PL', 'poland' => 'PL', 'tschechien' => 'CZ', 'czechia' => 'CZ', 'portugal' => 'PT', 'irland' => 'IE', 'ireland' => 'IE',
        'schweden' => 'SE', 'sweden' => 'SE', 'norwegen' => 'NO', 'norway' => 'NO', 'finnland' => 'FI', 'finland' => 'FI',
        'grossbritannien' => 'GB', 'großbritannien' => 'GB', 'vereinigteskoenigreich' => 'GB', 'unitedkingdom' => 'GB', 'uk' => 'GB', 'usa' => 'US', 'vereinigtestaaten' => 'US', 'unitedstates' => 'US',
    ];

    public static function templateCsv(): string
    {
        $rows = [
            ['Name', 'Ansprechpartner', 'E-Mail', 'Straße', 'PLZ', 'Ort', 'Land', 'USt-IdNr.', 'Leitweg-ID'],
            ['Beispiel GmbH', 'Erika Mustermann', 'rechnung@beispiel.de', 'Musterstraße 1', '26871', 'Papenburg', 'DE', 'DE123456789', ''],
            ['Muster & Söhne', '', '', 'Hauptstr. 5', '12345', 'Musterstadt', 'DE', '', ''],
        ];
        $out = "\xEF\xBB\xBF";   // BOM, damit Excel UTF-8 erkennt
        foreach ($rows as $r) {
            $out .= implode(';', array_map(static fn($c) => preg_match('/[;"\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c, $r)) . "\r\n";
        }
        return $out;
    }

    /** Wandelt beliebigen Dateiinhalt in UTF-8 (BOM weg; UTF-8 oder – falls ungültig – Windows-1252). */
    public static function decode(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'UTF-16');   // von Excel „Unicode-Text“
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        return str_replace(["\r\n", "\r"], "\n", $raw);
    }

    public static function detectDelimiter(string $text): string
    {
        $first = strtok($text, "\n") ?: '';
        $best = ';';
        $bestN = -1;
        foreach ([';' => ';', ',' => ',', "\t" => "\t", '|' => '|'] as $d) {
            $n = substr_count(preg_replace('/"[^"]*"/', '', $first) ?? $first, $d);
            if ($n > $bestN) {
                $best = $d;
                $bestN = $n;
            }
        }
        return $best;
    }

    /**
     * Liest CSV-Text in Zeilen. Wirft RuntimeException bei leerer/ungeeigneter Datei oder zu vielen Zeilen/Spalten.
     * @return list<list<string>>
     */
    public static function parse(string $text, string $delim): array
    {
        if (str_contains($text, "\0")) {
            throw new \RuntimeException('Das ist keine Textdatei (CSV).');
        }
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) {
                continue;   // Leerzeile
            }
            if (count($r) > self::MAX_COLS) {
                fclose($fh);
                throw new \RuntimeException('Zu viele Spalten (höchstens ' . self::MAX_COLS . '). Ist das Trennzeichen richtig erkannt?');
            }
            $rows[] = array_map(static fn($c) => (string)$c, $r);
            if (count($rows) > self::MAX_ROWS + 1) {
                fclose($fh);
                throw new \RuntimeException('Zu viele Zeilen (höchstens ' . self::MAX_ROWS . ' Kunden je Import). Bitte die Datei aufteilen.');
            }
        }
        fclose($fh);
        if (!$rows) {
            throw new \RuntimeException('Die Datei enthält keine Daten.');
        }
        return $rows;
    }

    private static function norm(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = strtr($h, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        return (string)preg_replace('/[^a-z0-9]/', '', $h);
    }

    /**
     * Ordnet Spalten anhand der Überschriften den Feldern zu.
     * @param list<string> $header
     * @return array<int,string> Spaltenindex => Feld ('' = nicht importieren)
     */
    public static function autoMap(array $header): array
    {
        $map = [];
        $used = [];
        foreach ($header as $i => $h) {
            $n = self::norm($h);
            $map[$i] = '';
            foreach (self::SYNONYMS as $field => $syn) {
                $syn = array_map([self::class, 'norm'], $syn);
                if (!isset($used[$field]) && in_array($n, $syn, true)) {
                    $map[$i] = $field;
                    $used[$field] = true;
                    break;
                }
            }
        }
        return $map;
    }

    /**
     * Prüft eine vom Benutzer gesendete Zuordnung: nur bekannte Felder, jedes höchstens einmal, Pflichtfelder vorhanden.
     * @return array{0: array<int,string>, 1: list<string>}
     */
    public static function checkMap(mixed $posted, int $cols): array
    {
        $errors = [];
        $map = [];
        $seen = [];
        $posted = is_array($posted) ? $posted : [];
        for ($i = 0; $i < $cols; $i++) {
            $v = $posted[$i] ?? '';
            if (!is_string($v) || ($v !== '' && !isset(self::FIELDS[$v]))) {
                $errors[] = 'Ungültige Zuordnung für Spalte ' . ($i + 1) . '.';
                $v = '';
            }
            if ($v !== '') {
                if (isset($seen[$v])) {
                    $errors[] = 'Das Feld „' . self::FIELDS[$v][0] . '“ ist mehreren Spalten zugeordnet.';
                }
                $seen[$v] = true;
            }
            $map[$i] = $v;
        }
        foreach (self::FIELDS as $f => [$label, , $req]) {
            if ($req && !isset($seen[$f])) {
                $errors[] = 'Das Pflichtfeld „' . $label . '“ ist keiner Spalte zugeordnet.';
            }
        }
        return [$map, $errors];
    }

    /**
     * Bereinigt und prüft eine Zeile (gleiche Regeln wie das Kundenformular).
     * @param list<string> $row
     * @param array<int,string> $map
     * @return array{0: array<string,?string>, 1: list<string>}
     */
    public static function normalizeRow(array $row, array $map): array
    {
        $v = array_fill_keys(array_keys(self::FIELDS), null);
        $err = [];
        foreach ($map as $i => $field) {
            if ($field === '') {
                continue;
            }
            $x = clean($row[$i] ?? '');
            $x = (string)preg_replace('/\s+/u', ' ', $x);
            $v[$field] = $x === '' ? null : $x;
        }
        foreach (self::FIELDS as $f => [$label, $max, $req]) {
            if ($f === 'country') {
                continue;
            }
            if ($req && $v[$f] === null && isset(array_flip($map)[$f])) {
                $err[] = $label . ' fehlt';
            } elseif ($v[$f] !== null && mb_strlen($v[$f]) > $max) {
                $err[] = $label . ' zu lang (höchstens ' . $max . ' Zeichen)';
            }
        }
        // Land: ISO-Code oder gängiger Ländername; leer = DE
        $c = $v['country'];
        if ($c === null) {
            $v['country'] = 'DE';
        } elseif (preg_match('/^[A-Za-z]{2}$/D', $c)) {
            $v['country'] = strtoupper($c);
        } elseif (isset(self::COUNTRIES[self::norm($c)])) {
            $v['country'] = self::COUNTRIES[self::norm($c)];
        } else {
            $err[] = 'Land „' . mb_substr($c, 0, 30) . '“ unbekannt (bitte zweistelligen Ländercode verwenden)';
        }
        if ($v['email'] !== null && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $err[] = 'E-Mail-Adresse ungültig';
        }
        if ($v['vat_id'] !== null && !preg_match('/^[A-Za-z0-9 .\-\/]{1,30}$/D', $v['vat_id'])) {
            $err[] = 'USt-IdNr. enthält unzulässige Zeichen';
        }
        return [$v, $err];
    }

    /**
     * Erstellt den Importplan.
     * @param list<list<string>> $rows Datenzeilen (ohne Überschrift)
     * @param array<int,string> $map
     * @param string $mode 'skip' (Duplikate überspringen) oder 'update' (Duplikate aktualisieren)
     * @return array{items: list<array{line:int,status:string,data:array,msgs:list<string>,id:?int,blank:array}>, counts: array<string,int>}
     */
    public static function plan(array $rows, array $map, string $mode, int $firstLine = 2): array
    {
        $byEmail = [];
        $byNameZip = [];
        foreach (db()->query('SELECT id, name, email, zip FROM customers') as $c) {
            if ($c['email'] !== null && $c['email'] !== '') {
                $byEmail[mb_strtolower($c['email'])] ??= (int)$c['id'];
            }
            $byNameZip[mb_strtolower($c['name']) . '|' . mb_strtolower((string)$c['zip'])] ??= (int)$c['id'];
        }
        $seenFile = [];
        $items = [];
        $counts = ['new' => 0, 'update' => 0, 'duplicate' => 0, 'error' => 0];
        foreach ($rows as $n => $row) {
            [$d, $errs] = self::normalizeRow($row, $map);
            $blank = [];
            foreach ($map as $i => $f) {
                if ($f !== '' && trim((string)($row[$i] ?? '')) === '') {
                    $blank[$f] = true;
                }
            }
            $item = ['line' => $firstLine + $n, 'status' => 'new', 'data' => $d, 'msgs' => $errs, 'id' => null, 'blank' => $blank];
            if ($errs) {
                $item['status'] = 'error';
            } else {
                $kEmail = $d['email'] !== null ? mb_strtolower($d['email']) : null;
                $kNz = mb_strtolower((string)$d['name']) . '|' . mb_strtolower((string)$d['zip']);
                $existing = ($kEmail !== null ? ($byEmail[$kEmail] ?? null) : null) ?? ($byNameZip[$kNz] ?? null);
                if (isset($seenFile['n:' . $kNz]) || ($kEmail !== null && isset($seenFile['e:' . $kEmail]))) {
                    $item['status'] = 'duplicate';
                    $item['msgs'][] = 'kommt in der Datei mehrfach vor';
                } elseif ($existing !== null) {
                    $item['id'] = $existing;
                    if ($mode === 'update') {
                        $item['status'] = 'update';
                        $item['msgs'][] = 'bestehender Kunde wird aktualisiert';
                    } else {
                        $item['status'] = 'duplicate';
                        $item['msgs'][] = 'Kunde existiert bereits';
                    }
                }
                $seenFile['n:' . $kNz] = true;
                if ($kEmail !== null) {
                    $seenFile['e:' . $kEmail] = true;
                }
            }
            $counts[$item['status']]++;
            $items[] = $item;
        }
        return ['items' => $items, 'counts' => $counts];
    }

    /** Führt den Plan aus (eine Transaktion: entweder alles oder nichts). @return array<string,int> */
    public static function apply(array $plan, array $map): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO customers (name, contact, email, street, zip, city, country, vat_id, buyer_reference) VALUES (:name, :contact, :email, :street, :zip, :city, :country, :vat_id, :buyer_reference)');
            $mapped = array_flip(array_filter($map));
            $res = ['new' => 0, 'update' => 0];
            foreach ($plan['items'] as $it) {
                if ($it['status'] === 'new') {
                    $ins->execute($it['data']);
                    $res['new']++;
                } elseif ($it['status'] === 'update' && $it['id'] !== null) {
                    // nur zugeordnete Spalten mit nicht-leerem Wert überschreiben
                    $set = [];
                    $par = ['id' => $it['id']];
                    foreach (array_keys(self::FIELDS) as $f) {
                        if (isset($mapped[$f]) && $it['data'][$f] !== null && empty($it['blank'][$f])) {
                            $set[] = "$f = :$f";
                            $par[$f] = $it['data'][$f];
                        }
                    }
                    if ($set) {
                        $pdo->prepare('UPDATE customers SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($par);
                    }
                    $res['update']++;
                }
            }
            $pdo->commit();
            return $res;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
