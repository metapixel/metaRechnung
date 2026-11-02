<?php
// Logik-Tests für Nummernkreise (Format, Rendern, Prüfung).  Aufruf: php tests/unit/numbering_test.php
declare(strict_types=1);
namespace App { function env(string $k, string $d = ''): string { return $GLOBALS['ENV'][$k] ?? $d; } }
namespace {
require __DIR__ . '/../../src/Numbering.php';
use App\Numbering as N;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . var_export($got, true) . ", erwartet " . var_export($want, true) . "\n"; } }
eq('Standard', N::render('RE-{JAHR}-{NR}', 4, '2026-11-02', 341), 'RE-2026-0341');
eq('JJ/MONAT/TAG', N::render('X{JJ}{MONAT}{TAG}-{NR}', 3, '2026-03-07', 5), 'X260307-005');
eq('nur NR', N::render('{NR}', 6, '2026-01-01', 42), '000042');
eq('Kleinschreibung', N::render('r-{jahr}-{nr}', 2, '2026-01-01', 7), 'r-2026-07');
eq('Nummer länger als Stellen', N::render('{NR}', 2, '2026-01-01', 12345), '12345');
foreach (['RE-{JAHR}-{NR}', 'RE{JJ}{MONAT}-{NR}', '{NR}', 'A_{JJ}.{NR}', '{nr}-{jahr}'] as $f) { eq("gültig $f", N::validate($f, 4, 'T'), null); }
foreach (['', 'RE-{JAHR}', '{NR}-{NR}', 'RE-{FIRMA}-{NR}', 'RE/{NR}', 'RE {NR}', '<b>{NR}', 'R"E{NR}', 'RE-{JAHR}{NR}', '{NR}{JJ}', 'RE-2026{NR}', '{NR}1', str_repeat('A', 61) . '{NR}', 'RE-{NR', 'RE-{}-{NR}', 'ä{NR}'] as $f) {
    eq("ungültig " . substr($f, 0, 20), N::validate($f, 4, 'T') !== null, true);
}
eq('zu lang mit Stellen', N::validate(str_repeat('A', 25) . '-{NR}', 6, 'T') !== null, true);
eq('Meldung nennt Label', str_starts_with((string)N::validate('x', 4, 'Rechnungen'), 'Rechnungen:'), true);
$GLOBALS['ENV'] = [];
eq('Standardformat ohne Konfiguration', N::format('invoice'), 'RE-{JAHR}-{NR}');
$GLOBALS['ENV'] = ['CANCEL_PREFIX' => 'GS'];
eq('Rückfall auf altes Präfix', N::format('cancel'), 'GS-{JAHR}-{NR}');
$GLOBALS['ENV'] = ['OFFER_FORMAT' => 'A{NR}', 'OFFER_PAD' => '9', 'OFFER_NEXT' => '77'];
eq('Format gesetzt', [N::format('offer'), N::pad('offer'), N::start('offer')], ['A{NR}', 9, 77]);
$GLOBALS['ENV'] = ['OFFER_PAD' => 'x', 'OFFER_NEXT' => '-3'];
eq('ungültige Stellen/Start -> Standard', [N::pad('offer'), N::start('offer')], [4, 0]);
echo "Numbering: $n Prüfungen, " . ($n - $fail) . " bestanden\n";
exit($fail ? 1 : 0);
}
