<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Export der Ausgangsrechnungen für den Steuerberater: ZIP (Rechnungs-PDFs, optional XRechnung-XML, CSV-Übersicht)
 * im Ordner storage/exports/, abrufbar über einen geheimen Link (export.php?t=…) mit Ablaufdatum.
 */
final class Exports
{
    public const MAX_INVOICES = 2000;
    public const MAX_DAYS_SPAN = 1100;
    public const VALID_DAYS = [7, 14, 30, 90];
    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public static function dir(): string
    {
        $d = dirname(__DIR__) . '/storage/exports';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
            // Geprüft: feste Dateinamen im eigenen Export-Ordner
            // nosemgrep
            @file_put_contents($d . '/index.html', '');
            // nosemgrep
            @file_put_contents($d . '/.htaccess', "Require all denied\n");
        }
        return $d;
    }

    public static function available(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    private static function parseDate(string $d, string $what): \DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
            throw new \RuntimeException($what . ': ungültiges Datum.');
        }
        return new \DateTimeImmutable($d);
    }

    /**
     * Wandelt die Auswahl in einen Zeitraum um.
     * @return array{0:string,1:string,2:string} von, bis (Y-m-d), Bezeichnung
     */
    public static function period(string $mode, string $from, string $to, \DateTimeImmutable $today): array
    {
        switch ($mode) {
            case 'prev_month':
                $a = $today->modify('first day of last month');
                return [$a->format('Y-m-01'), $a->format('Y-m-t'), self::MONTHS[(int)$a->format('n') - 1] . ' ' . $a->format('Y')];
            case 'prev_quarter':
                $q = (int)ceil((int)$today->format('n') / 3) - 1;
                $y = (int)$today->format('Y');
                if ($q === 0) {
                    $q = 4;
                    $y--;
                }
                $m1 = ($q - 1) * 3 + 1;
                $a = new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m1));
                return [$a->format('Y-m-d'), $a->modify('+2 months')->format('Y-m-t'), 'Quartal ' . $q . '/' . $y];
            case 'prev_year':
                $y = (int)$today->format('Y') - 1;
                return [$y . '-01-01', $y . '-12-31', (string)$y];
            case 'year':
                $y = $today->format('Y');
                return [$y . '-01-01', $today->format('Y-m-d'), $y . ' (bis heute)'];
            case 'custom':
                $a = self::parseDate($from, 'Von');
                $b = self::parseDate($to, 'Bis');
                if ($b < $a) {
                    throw new \RuntimeException('Das Enddatum liegt vor dem Anfangsdatum.');
                }
                if ($a->diff($b)->days > self::MAX_DAYS_SPAN) {
                    throw new \RuntimeException('Der Zeitraum ist zu groß (höchstens ' . self::MAX_DAYS_SPAN . ' Tage).');
                }
                return [$a->format('Y-m-d'), $b->format('Y-m-d'), $a->format('d.m.Y') . ' – ' . $b->format('d.m.Y')];
        }
        throw new \RuntimeException('Bitte einen Zeitraum wählen.');
    }

    /** Schützt Textzellen vor Formelinterpretation in Tabellenkalkulationen. */
    public static function csvText(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $s) ?? '';
        $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);
        return preg_match('/^[=+\-@\t]/', $s) ? "'" . $s : $s;
    }

    private static function num(float $v): string
    {
        return number_format($v, 2, ',', '');
    }

    private static function field(string $s): string
    {
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /** CSV-Übersicht (Rechnungsausgangsbuch): Semikolon, UTF-8 mit BOM, Dezimalkomma. */
    public static function csv(array $invoices): string
    {
        $head = ['Rechnungsnummer', 'Rechnungsdatum', 'Leistungsdatum', 'Fällig am', 'Art', 'Kunde', 'Land', 'USt-IdNr. Kunde', 'Netto 0 %', 'Netto 7 %', 'Netto 19 %', 'Netto sonstige Sätze',
            'USt 7 %', 'USt 19 %', 'USt sonstige Sätze', 'Netto gesamt', 'USt gesamt', 'Brutto', 'Status', 'Bezahlt am', 'Storno zu Rechnung'];
        $out = [implode(';', array_map([self::class, 'field'], $head))];
        foreach ($invoices as $i) {
            $net = ['0' => 0.0, '7' => 0.0, '19' => 0.0, 'x' => 0.0];
            $tax = ['7' => 0.0, '19' => 0.0, 'x' => 0.0];
            foreach ($i['totals']['taxes'] as $g) {
                $k = in_array((string)(float)$g['rate'], ['0', '7', '19'], true) ? (string)(float)$g['rate'] : 'x';
                $net[$k] += $g['basis'];
                if ($k !== '0') {
                    $tax[$k] += $g['tax'];
                }
            }
            $credit = !empty($i['cancels_id']);
            $status = $credit ? 'Storno' : (!empty($i['cancelled_by']) ? 'storniert' : ($i['status'] === 'paid' ? 'bezahlt' : 'offen'));
            $txt = fn(string $v) => self::field(self::csvText($v));
            $out[] = implode(';', [
                $txt((string)$i['number']), self::field(fdate($i['issue_date'])), self::field($i['delivery_date'] ? fdate($i['delivery_date']) : ''), self::field($credit ? '' : fdate($i['due_date'])),
                self::field($credit ? 'Stornorechnung' : 'Rechnung'), $txt((string)$i['c_name']), self::field((string)$i['c_country']), $txt((string)($i['c_vat_id'] ?? '')),
                self::num($net['0']), self::num($net['7']), self::num($net['19']), self::num($net['x']),
                self::num($tax['7']), self::num($tax['19']), self::num($tax['x']),
                self::num($i['totals']['net']), self::num($i['totals']['tax']), self::num($i['totals']['gross']),
                self::field($status), self::field($i['paid_at'] ? fdate($i['paid_at']) : ''), $txt((string)($i['cancels']['number'] ?? '')),
            ]);
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $out) . "\r\n";
    }

    private static function safeName(string $n): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $n) ?: 'rechnung';
    }

    /** Erstellt den Export und gibt die ID zurück. */
    public static function create(string $from, string $to, string $label, bool $withXml, int $days): int
    {
        if (!self::available()) {
            throw new \RuntimeException('Die PHP-Erweiterung „zip“ fehlt – der Export kann nicht erstellt werden.');
        }
        if (!in_array($days, self::VALID_DAYS, true)) {
            $days = 14;
        }
        $st = db()->prepare("SELECT id FROM invoices WHERE status IN ('issued','paid') AND issue_date BETWEEN ? AND ? ORDER BY issue_date, id LIMIT " . (self::MAX_INVOICES + 1));
        $st->execute([$from, $to]);
        $ids = $st->fetchAll(\PDO::FETCH_COLUMN);
        if (!$ids) {
            throw new \RuntimeException('Im Zeitraum gibt es keine ausgestellten Rechnungen.');
        }
        if (count($ids) > self::MAX_INVOICES) {
            throw new \RuntimeException('Zu viele Rechnungen im Zeitraum (höchstens ' . self::MAX_INVOICES . '). Bitte den Zeitraum verkleinern.');
        }
        @set_time_limit(300);
        $dir = self::dir();
        $name = 'export-' . bin2hex(random_bytes(12)) . '.zip';
        $tmp = $dir . '/' . $name . '.part';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Die ZIP-Datei konnte nicht angelegt werden (Schreibrechte auf storage/?).');
        }
        try {
            $invs = [];
            $skipped = [];
            $sumNet = $sumGross = 0.0;
            foreach ($ids as $id) {
                $inv = Invoices::find((int)$id);
                if (!$inv || !$inv['number']) {
                    continue;
                }
                $invs[] = $inv;
                $sumNet += $inv['totals']['net'];
                $sumGross += $inv['totals']['gross'];
                $fn = self::safeName((string)$inv['number']);
                $zip->addFromString('rechnungen/' . $fn . '.pdf', Invoices::pdfBytes($inv));
                if ($withXml) {
                    if (Einvoice::xrechnungProblems($inv)) {
                        $skipped[] = $inv['number'];
                    } else {
                        $zip->addFromString('xrechnung/' . $fn . '_xrechnung.xml', Einvoice::xml($inv, true));
                    }
                }
            }
            $zip->addFromString('rechnungsausgangsbuch.csv', self::csv($invs));
            $co = company();
            $readme = "Rechnungsexport – " . $co['name'] . "\r\n"
                . "Zeitraum (Rechnungsdatum): " . fdate($from) . ' bis ' . fdate($to) . "\r\n"
                . "Erstellt am: " . date('d.m.Y H:i') . "\r\n"
                . "Anzahl Belege: " . count($invs) . "\r\n"
                . "Summe netto: " . money($sumNet) . "  |  Summe brutto: " . money($sumGross) . "\r\n\r\n"
                . "Inhalt:\r\n"
                . "- rechnungsausgangsbuch.csv  Übersicht aller Belege (Semikolon, UTF-8, Dezimalkomma; Stornorechnungen mit negativen Beträgen)\r\n"
                . "- rechnungen/  Rechnungen als PDF (ZUGFeRD, enthalten das XML bereits eingebettet)\r\n"
                . ($withXml ? "- xrechnung/  Rechnungen als XRechnung-XML (CII)\r\n" : '')
                . ($skipped ? "\r\nHinweis: Für folgende Belege konnte kein XRechnung-XML erzeugt werden (Pflichtangaben fehlen): " . implode(', ', $skipped) . "\r\n" : '')
                . "\r\nHinweis: Der Umsatz zählt nach Rechnungsdatum. Bezahlt-Daten entsprechen dem in der App erfassten Zahlungseingang.\r\n";
            $zip->addFromString('LIESMICH.txt', $readme);
            if (!$zip->close()) {
                throw new \RuntimeException('Die ZIP-Datei konnte nicht geschrieben werden (Speicherplatz?).');
            }
        } catch (\Throwable $e) {
            @$zip->close();
            // Geprüft: Pfad aus festem Ordner + zufälligem Namen (kein Benutzerpfad)
            // nosemgrep
            @unlink($tmp);
            throw $e;
        }
        $final = $dir . '/' . $name;
        if (!@rename($tmp, $final)) {
            // Geprüft: Pfad aus festem Ordner + zufälligem Namen (kein Benutzerpfad)
            // nosemgrep
            @unlink($tmp);
            throw new \RuntimeException('Die ZIP-Datei konnte nicht abgelegt werden.');
        }
        db()->prepare('INSERT INTO exports (token, file, label, period_from, period_to, invoice_count, size_bytes, with_xml, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))')
            ->execute([bin2hex(random_bytes(16)), $name, mb_substr($label, 0, 100), $from, $to, count($invs), (int)filesize($final), $withXml ? 1 : 0, $days]);
        return (int)db()->lastInsertId();
    }

    /** Löscht abgelaufene Exporte samt Datei. */
    public static function purgeExpired(): int
    {
        $n = 0;
        foreach (db()->query('SELECT id, file FROM exports WHERE expires_at < NOW()')->fetchAll() as $r) {
            self::removeFile((string)$r['file']);
            db()->prepare('DELETE FROM exports WHERE id = ?')->execute([$r['id']]);
            $n++;
        }
        return $n;
    }

    private static function removeFile(string $file): void
    {
        if (preg_match('/^export-[0-9a-f]{24}\.zip$/D', $file)) {
            // Geprüft: Dateiname nur nach Muster export-<24 hex>.zip, fester Ordner (kein Benutzerpfad)
            // nosemgrep
            @unlink(self::dir() . '/' . $file);
        }
    }

    public static function all(): array
    {
        return db()->query('SELECT * FROM exports ORDER BY id DESC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM exports WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Nur gültige (nicht abgelaufene) Exporte mit vorhandener Datei. */
    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/D', $token)) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM exports WHERE token = ? AND expires_at >= NOW()');
        $st->execute([$token]);
        $r = $st->fetch();
        return $r && self::path($r) !== null ? $r : null;
    }

    public static function path(array $row): ?string
    {
        if (!preg_match('/^export-[0-9a-f]{24}\.zip$/D', (string)$row['file'])) {
            return null;
        }
        $p = self::dir() . '/' . $row['file'];
        return is_file($p) ? $p : null;
    }

    public static function delete(int $id): void
    {
        if ($r = self::find($id)) {
            self::removeFile((string)$r['file']);
            db()->prepare('DELETE FROM exports WHERE id = ?')->execute([$id]);
        }
    }

    public static function url(array $row): string
    {
        return app_url() . 'export.php?t=' . $row['token'];
    }

    public static function downloadName(array $row): string
    {
        return 'metaRechnung-Export-' . $row['period_from'] . '_' . $row['period_to'] . '.zip';
    }

    public static function recordDownload(int $id): void
    {
        db()->prepare('UPDATE exports SET downloads = downloads + 1, last_download = NOW() WHERE id = ?')->execute([$id]);
    }

    /** Versendet den Zugangslink per E-Mail (ohne Anhang). */
    public static function send(int $id, string $to, string $message): void
    {
        $r = self::find($id);
        if (!$r) {
            throw new \RuntimeException('Export nicht gefunden.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Ungültige E-Mail-Adresse.');
        }
        if (env('APP_URL') === '') {
            throw new \RuntimeException('APP_URL ist nicht gesetzt (Einstellungen → Adresse der App) – ohne sie kann kein gültiger Link versendet werden.');
        }
        if ($r['expires_at'] < date('Y-m-d H:i:s')) {
            throw new \RuntimeException('Der Export ist abgelaufen.');
        }
        Mailer::sendExportLink($r, $to, $message, self::url($r));
        db()->prepare('UPDATE exports SET sent_to = ?, sent_at = NOW() WHERE id = ?')->execute([mb_substr($to, 0, 190), $id]);
    }

    public static function defaultMessage(string $label = ''): string
    {
        $co = company();
        return Templates::render('mail_export_body', [
            'FIRMA' => $co['name'], 'ABSENDER' => $co['owner'] ?: $co['name'], 'ZEITRAUM' => $label,
        ]);
    }
}
