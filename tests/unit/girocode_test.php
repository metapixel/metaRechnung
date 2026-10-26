<?php
// Logik-Tests für GiroCode-Payload und IBAN-Prüfung (ohne Datenbank).  Aufruf: php tests/unit/girocode_test.php
declare(strict_types=1);
namespace App { function env(string $k, string $d = ''): string { return $d; } }
namespace {
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../src/GiroCode.php';
use App\GiroCode as G;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . var_export($got, true) . ", erwartet " . var_export($want, true) . "\n"; } }
$inv = fn(array $co = [], float $gross = 119.0, string $no = 'RE-1') => ['number' => $no, 'totals' => ['gross' => $gross],
    'co' => $co + ['name' => 'Müller & Söhne GmbH', 'iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'cobadeffxxx']];
eq('IBAN gültig', G::validIban('DE89370400440532013000'), true);
eq('IBAN AT gültig', G::validIban('AT611904300234573201'), true);
eq('IBAN Prüfsumme falsch', G::validIban('DE89370400440532013001'), false);
eq('IBAN zu kurz', G::validIban('DE89'), false);
eq('IBAN leer', G::validIban(''), false);
eq('Payload', G::payload($inv()), "BCD\n002\n1\nSCT\nCOBADEFFXXX\nMüller & Söhne GmbH\nDE89370400440532013000\nEUR119.00\n\n\nRechnung RE-1");
eq('Betrag gerundet', explode("\n", (string)G::payload($inv([], 10.005)))[7], 'EUR10.01');
eq('Betrag 0', G::payload($inv([], 0.0)), null);
eq('Betrag negativ', G::payload($inv([], -5.0)), null);
eq('Betrag zu groß', G::payload($inv([], 1e10)), null);
eq('IBAN ungültig', G::payload($inv(['iban' => 'DE00123'])), null);
eq('ohne Nummer', G::payload($inv([], 5.0, '')), null);
eq('BIC ungültig → leer', explode("\n", (string)G::payload($inv(['bic' => 'xx'])))[4], '');
eq('Name leer', G::payload($inv(['name' => '  '])), null);
eq('Name gekürzt auf 70', mb_strlen(explode("\n", (string)G::payload($inv(['name' => str_repeat('ä', 100)])))[5]), 70);
$p = (string)G::payload($inv(['name' => "Evil\nGmbH\\nIBAN\r\x00x"]));
eq('keine Zusatzzeilen durch Name', count(explode("\n", $p)), 11);
eq('Backslash entfernt', str_contains($p, '\\'), false);
eq('forMpdf', G::forMpdf("a\nb"), 'a\\nb');
eq('forMpdf ohne echte Umbrüche', str_contains(G::forMpdf($p), "\n"), false);
eq('Max. Größe', strlen((string)G::payload($inv(['name' => str_repeat('ä', 70)], 5.0, str_repeat('x', 30)))) <= G::MAX_BYTES, true);
eq('SVG-URI', str_starts_with(G::svgDataUri((string)G::payload($inv())), 'data:image/svg+xml;base64,'), true);
echo $fail ? "$fail von $n Prüfungen fehlgeschlagen\n" : "Alle $n Prüfungen bestanden\n";
exit($fail ? 1 : 0);
}
