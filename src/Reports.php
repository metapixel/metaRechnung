<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Auswertungen: Umsatz je Monat/Jahr und Zahlungsverhalten je Kunde.
 * Grundlage sind ausgestellte und bezahlte Rechnungen (keine Entwürfe). Der Umsatz zählt im Monat des Rechnungsdatums;
 * Stornorechnungen (negativer Betrag) mindern ihn im Monat ihres Rechnungsdatums.
 */
final class Reports
{
    /** @return list<array<string,mixed>> Ausgestellte/bezahlte Rechnungen mit Summen */
    public static function invoices(): array
    {
        $rows = db()->query(
            "SELECT i.id, i.number, i.status, i.cancels_id, i.customer_id, i.issue_date, i.due_date, i.paid_at, i.snapshot,
                    c.name AS c_name,
                    (SELECT COUNT(*) FROM invoices s WHERE s.cancels_id = i.id AND s.status <> 'draft') AS cancelled
             FROM invoices i JOIN customers c ON c.id = i.customer_id
             WHERE i.status IN ('issued','paid') ORDER BY i.issue_date, i.id"
        )->fetchAll();
        $items = [];
        foreach (db()->query('SELECT invoice_id, quantity, unit_price, vat_rate FROM invoice_items') as $r) {
            $items[$r['invoice_id']][] = $r;
        }
        foreach ($rows as &$r) {
            $snap = $r['snapshot'] ? json_decode((string)$r['snapshot'], true) : null;
            $r['totals'] = Invoices::totals($items[$r['id']] ?? [], isset($snap['kl']) ? (bool)$snap['kl'] : null);
            unset($r['snapshot']);
        }
        unset($r);
        return $rows;
    }

    /** Jahre, in denen es Rechnungen gibt (absteigend). @return list<int> */
    public static function years(array $inv): array
    {
        $y = [];
        foreach ($inv as $r) {
            $y[(int)substr((string)$r['issue_date'], 0, 4)] = true;
        }
        krsort($y);
        return array_keys($y);
    }

    /** Umsatz je Jahr. @return array<int,array{count:int,net:float,gross:float,open:float}> */
    public static function perYear(array $inv): array
    {
        $out = [];
        foreach ($inv as $r) {
            $y = (int)substr((string)$r['issue_date'], 0, 4);
            $out[$y] ??= ['count' => 0, 'net' => 0.0, 'gross' => 0.0, 'open' => 0.0];
            $out[$y]['count']++;
            $out[$y]['net'] += $r['totals']['net'];
            $out[$y]['gross'] += $r['totals']['gross'];
            if (self::isOpenClaim($r)) {
                $out[$y]['open'] += $r['totals']['gross'];
            }
        }
        krsort($out);
        foreach ($out as &$v) {
            $v = array_map(static fn($x) => is_float($x) ? round($x, 2) : $x, $v);
        }
        return $out;
    }

    /** Umsatz je Monat eines Jahres (1–12). @return array<int,array{count:int,net:float,gross:float}> */
    public static function perMonth(array $inv, int $year): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['count' => 0, 'net' => 0.0, 'gross' => 0.0];
        }
        foreach ($inv as $r) {
            if ((int)substr((string)$r['issue_date'], 0, 4) !== $year) {
                continue;
            }
            $m = (int)substr((string)$r['issue_date'], 5, 2);
            $out[$m]['count']++;
            $out[$m]['net'] += $r['totals']['net'];
            $out[$m]['gross'] += $r['totals']['gross'];
        }
        foreach ($out as &$v) {
            $v['net'] = round($v['net'], 2);
            $v['gross'] = round($v['gross'], 2);
        }
        return $out;
    }

    /** Offene Forderung: ausgestellt, keine Stornorechnung, nicht storniert. */
    private static function isOpenClaim(array $r): bool
    {
        return $r['status'] === 'issued' && empty($r['cancels_id']) && !(int)$r['cancelled'];
    }

    /** Wertet eine bezahlte Rechnung für die Pünktlichkeit aus? (keine Storno, nicht storniert, Datum vorhanden) */
    private static function isTimed(array $r): bool
    {
        return $r['status'] === 'paid' && empty($r['cancels_id']) && !(int)$r['cancelled'] && !empty($r['paid_at']);
    }

    private static function days(string $from, string $to): int
    {
        return (int)round((strtotime($to . ' 12:00:00 UTC') - strtotime($from . ' 12:00:00 UTC')) / 86400);
    }

    /**
     * Zahlungsverhalten je Kunde (bezahlte Rechnungen) plus offene/überfällige Forderungen. Gesamtzeile unter Schlüssel 0.
     * @return array<int,array<string,mixed>>
     */
    public static function punctuality(array $inv, string $today): array
    {
        $blank = static fn(string $name) => ['name' => $name, 'paid' => 0, 'on_time' => 0, 'days_sum' => 0, 'late_sum' => 0, 'late_n' => 0,
            'max_late' => 0, 'open_n' => 0, 'open_sum' => 0.0, 'over_n' => 0, 'over_sum' => 0.0, 'revenue' => 0.0];
        $c = [0 => $blank('Alle Kunden')];
        foreach ($inv as $r) {
            $cid = (int)$r['customer_id'];
            $c[$cid] ??= $blank((string)$r['c_name']);
            $targets = [$cid, 0];
            foreach ($targets as $t) {
                $c[$t]['revenue'] += $r['totals']['net'];
                if (self::isTimed($r)) {
                    $late = self::days((string)$r['due_date'], (string)$r['paid_at']);
                    $c[$t]['paid']++;
                    $c[$t]['days_sum'] += self::days((string)$r['issue_date'], (string)$r['paid_at']);
                    if ($late <= 0) {
                        $c[$t]['on_time']++;
                    } else {
                        $c[$t]['late_n']++;
                        $c[$t]['late_sum'] += $late;
                        $c[$t]['max_late'] = max($c[$t]['max_late'], $late);
                    }
                } elseif (self::isOpenClaim($r)) {
                    $c[$t]['open_n']++;
                    $c[$t]['open_sum'] += $r['totals']['gross'];
                    if ((string)$r['due_date'] < $today) {
                        $c[$t]['over_n']++;
                        $c[$t]['over_sum'] += $r['totals']['gross'];
                    }
                }
            }
        }
        foreach ($c as &$v) {
            $v['revenue'] = round($v['revenue'], 2);
            $v['open_sum'] = round($v['open_sum'], 2);
            $v['over_sum'] = round($v['over_sum'], 2);
            $v['share'] = $v['paid'] ? $v['on_time'] / $v['paid'] * 1.0 : null;
            $v['avg_days'] = $v['paid'] ? $v['days_sum'] / $v['paid'] * 1.0 : null;
            $v['avg_late'] = $v['late_n'] ? $v['late_sum'] / $v['late_n'] * 1.0 : null;
            $v['rating'] = self::rating($v['paid'], $v['share']);
        }
        unset($v);
        $all = $c[0];
        unset($c[0]);
        uasort($c, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return [0 => $all] + $c;
    }

    /** Textuelle Einstufung (nur mit mindestens 3 bezahlten Rechnungen). */
    public static function rating(int $paid, ?float $share): string
    {
        if ($paid < 3 || $share === null) {
            return 'zu wenig Daten';
        }
        return $share >= 0.9 ? 'pünktlich' : ($share >= 0.6 ? 'meist pünktlich' : 'oft verspätet');
    }
}
