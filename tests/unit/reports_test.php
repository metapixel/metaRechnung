<?php
// Logik-Tests für die Auswertung (Umsatz, Zahlungsverhalten).  Aufruf: php tests/unit/reports_test.php
declare(strict_types=1);
namespace {
require __DIR__ . '/../../src/Reports.php';
use App\Reports as R;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . var_export($got, true) . ", erwartet " . var_export($want, true) . "\n"; } }
function inv(int $id, int $cust, string $name, string $st, string $issue, string $due, ?string $paid, float $net, float $gross = null, ?int $cancels = null, int $cancelled = 0): array {
    return ['id' => $id, 'number' => "R$id", 'status' => $st, 'cancels_id' => $cancels, 'customer_id' => $cust, 'issue_date' => $issue, 'due_date' => $due, 'paid_at' => $paid,
            'c_name' => $name, 'cancelled' => $cancelled, 'totals' => ['net' => $net, 'gross' => $gross ?? round($net * 1.19, 2)]];
}
$inv = [
    inv(1, 1, 'Alpha', 'paid', '2026-01-10', '2026-01-24', '2026-01-20', 100),          // früh (−4)
    inv(2, 1, 'Alpha', 'paid', '2026-02-01', '2026-02-15', '2026-02-15', 200),          // am Fälligkeitstag = pünktlich
    inv(3, 1, 'Alpha', 'paid', '2026-03-01', '2026-03-15', '2026-03-25', 300),          // 10 Tage zu spät
    inv(4, 2, 'Beta', 'issued', '2026-03-05', '2026-03-19', null, 400),                 // offen, überfällig
    inv(5, 2, 'Beta', 'issued', '2026-12-30', '2027-01-13', null, 50),                  // offen, noch nicht fällig
    inv(6, 2, 'Beta', 'paid', '2025-12-31', '2026-01-14', '2026-01-10', 1000),          // storniert: zählt im Umsatz, nicht im Verhalten
    inv(7, 2, 'Beta', 'issued', '2026-01-02', '2026-01-02', null, -1000, -1190, 6),     // Storno zu 6
];
$inv[5]['cancelled'] = 1;
eq('Jahre', R::years($inv), [2026, 2025]);
$m = R::perMonth($inv, 2026);
eq('Januar netto (100 − 1000)', $m[1]['net'], -900.0);
eq('Januar Anzahl', $m[1]['count'], 2);
eq('Dezember', $m[12]['net'], 50.0);
eq('leerer Monat', $m[6], ['count' => 0, 'net' => 0.0, 'gross' => 0.0]);
$y = R::perYear($inv);
eq('Jahre absteigend', array_keys($y), [2026, 2025]);
eq('Umsatz 2026', $y[2026]['net'], 100 + 200 + 300 + 400 + 50 - 1000.0);
eq('Umsatz 2025', $y[2025]['net'], 1000.0);
eq('Offen 2026 = Brutto offener (ohne Storno/storniert)', $y[2026]['open'], round(400 * 1.19 + 50 * 1.19, 2));
eq('Offen 2025 (storniert → 0)', $y[2025]['open'], 0.0);
$p = R::punctuality($inv, '2026-10-28');
eq('Alpha bezahlt', $p[1]['paid'], 3);
eq('Alpha pünktlich', $p[1]['on_time'], 2);
eq('Alpha Anteil', round($p[1]['share'], 4), 0.6667);
eq('Alpha Ø Dauer', $p[1]['avg_days'], 16.0);
eq('Alpha Ø Verzug nur verspätete', $p[1]['avg_late'], 10.0);
eq('Alpha Einstufung (66 %)', $p[1]['rating'], 'meist pünktlich');
eq('Beta bezahlt (storniert zählt nicht)', $p[2]['paid'], 0);
eq('Beta Einstufung zu wenig Daten', $p[2]['rating'], 'zu wenig Daten');
eq('Beta offen', $p[2]['open_n'], 2);
eq('Beta überfällig (1 von 2)', $p[2]['over_n'], 1);
eq('Beta überfällig Betrag', $p[2]['over_sum'], round(400 * 1.19, 2));
eq('Gesamtzeile Schlüssel 0 zuerst', array_key_first($p), 0);
eq('Gesamt bezahlt', $p[0]['paid'], 3);
eq('Gesamt überfällig', $p[0]['over_n'], 1);
eq('Keine Division durch 0', $p[2]['share'], null);
eq('Einstufung pünktlich', R::rating(5, 0.9), 'pünktlich');
eq('Einstufung oft verspätet', R::rating(4, 0.5), 'oft verspätet');
eq('Grenze 60 %', R::rating(5, 0.6), 'meist pünktlich');
eq('weniger als 3', R::rating(2, 1.0), 'zu wenig Daten');
eq('leer', R::punctuality([], '2026-01-01')[0]['paid'], 0);
echo $fail ? "$fail von $n Prüfungen fehlgeschlagen\n" : "Alle $n Prüfungen bestanden\n";
exit($fail ? 1 : 0);
}
