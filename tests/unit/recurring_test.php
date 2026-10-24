<?php
// Reine Logik-Tests für Zeiträume, Platzhalter und Folgetermine (ohne Datenbank).  Aufruf: php tests/unit/recurring_test.php
declare(strict_types=1);
namespace App { function env(string $k, string $d = ''): string { return $d; } }
namespace {
require __DIR__ . '/../../src/Recurring.php';
use App\Recurring as R;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . var_export($got, true) . ", erwartet " . var_export($want, true) . "\n"; } }
$d = fn(string $s) => new DateTimeImmutable($s);
$v = fn(string $run, string $f, string $p) => R::vars($d($run), $f, $p, $d('2026-10-24'));

// Zeiträume
eq('Monat zurück', $v('2026-11-01', 'month', 'previous')['ZEITRAUM'], 'Oktober 2026');
eq('Monat laufend', $v('2026-11-01', 'month', 'current')['ZEITRAUM'], 'November 2026');
eq('Monat voraus', $v('2026-11-25', 'month', 'next')['ZEITRAUM'], 'Dezember 2026');
eq('Jahreswechsel zurück', $v('2027-01-05', 'month', 'previous')['ZEITRAUM'], 'Dezember 2026');
eq('Jahreswechsel voraus', $v('2026-12-20', 'month', 'next')['ZEITRAUM'], 'Januar 2027');
eq('Quartal zurück', $v('2026-10-01', 'quarter', 'previous')['ZEITRAUM'], '3. Quartal 2026');
eq('Quartal zurück Q1', $v('2027-01-10', 'quarter', 'previous')['ZEITRAUM'], '4. Quartal 2026');
eq('Quartal laufend', $v('2026-11-15', 'quarter', 'current')['ZEITRAUM'], '4. Quartal 2026');
eq('Quartal voraus', $v('2026-12-15', 'quarter', 'next')['ZEITRAUM'], '1. Quartal 2027');
eq('Quartal von', $v('2026-10-01', 'quarter', 'previous')['ZEITRAUM_VON'], '01.07.2026');
eq('Quartal bis', $v('2026-10-01', 'quarter', 'previous')['ZEITRAUM_BIS'], '30.09.2026');
eq('Jahr zurück', $v('2027-01-15', 'year', 'previous')['ZEITRAUM'], '2026');
eq('Jahr voraus', $v('2026-12-01', 'year', 'next')['ZEITRAUM'], '2027');
eq('Jahr bis', $v('2027-03-01', 'year', 'previous')['ZEITRAUM_BIS'], '31.12.2026');
eq('Schaltjahr Feb bis', $v('2028-03-01', 'month', 'previous')['ZEITRAUM_BIS'], '29.02.2028');
eq('Feb bis (kein Schaltjahr)', $v('2027-03-01', 'month', 'previous')['ZEITRAUM_BIS'], '28.02.2027');
eq('Monat Nr.', $v('2026-04-01', 'month', 'current')['MONAT_NR'], '04');
eq('Quartal Nr.', $v('2026-04-01', 'month', 'current')['QUARTAL'], '2');
eq('Kurzname März', $v('2026-03-01', 'month', 'current')['MONAT_KURZ'], 'März');
eq('Kurzname Mai', $v('2026-05-01', 'month', 'current')['MONAT_KURZ'], 'Mai');
eq('Intervall', $v('2026-05-01', 'quarter', 'current')['INTERVALL'], 'vierteljährlich');
eq('Datum', $v('2026-05-01', 'month', 'current')['DATUM'], '24.10.2026');

// Folgetermine
eq('Monat +1', R::nextRun($d('2026-10-15'), 'month', 15)->format('Y-m-d'), '2026-11-15');
eq('31. → Feb', R::nextRun($d('2027-01-31'), 'month', 31)->format('Y-m-d'), '2027-02-28');
eq('Feb → 31. wieder', R::nextRun($d('2027-02-28'), 'month', 31)->format('Y-m-d'), '2027-03-31');
eq('Schaltjahr 29.', R::nextRun($d('2028-01-31'), 'month', 31)->format('Y-m-d'), '2028-02-29');
eq('Quartal +3', R::nextRun($d('2026-11-30'), 'quarter', 30)->format('Y-m-d'), '2027-02-28');
eq('Jahr +1', R::nextRun($d('2028-02-29'), 'year', 29)->format('Y-m-d'), '2029-02-28');
eq('Dez → Jan', R::nextRun($d('2026-12-31'), 'month', 31)->format('Y-m-d'), '2027-01-31');

// Ersetzen
$vars = $v('2026-11-01', 'month', 'previous');
eq('render', R::render('Hosting {ZEITRAUM} ({monat_nr}/{JAHR})', $vars), 'Hosting Oktober 2026 (10/2026)');
eq('unbekannt bleibt', R::render('{FOO} {JAHR}', $vars), '{FOO} 2026');
eq('keine Rekursion', R::render('{MONAT}', ['MONAT' => '{JAHR}', 'JAHR' => 'x']), '{JAHR}');
eq('unknownVars', R::unknownVars('{JAHR} {Foo} {BAR_1} {}', array_keys(R::VARS)), ['{Foo}']);
eq('Klammern/Sonderzeichen', R::render('a {{JAHR}} $1 \\1 {JAHR}', ['JAHR' => '$1\\1']), 'a {$1\\1} $1 \\1 $1\\1');
echo $fail ? "$fail von $n Prüfungen fehlgeschlagen\n" : "Alle $n Prüfungen bestanden\n";
exit($fail ? 1 : 0);
}
