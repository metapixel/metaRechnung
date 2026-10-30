<?php
// Logik-Tests für die Textvorlagen (Platzhalter).  Aufruf: php tests/unit/templates_test.php
declare(strict_types=1);
namespace App { function env(string $k, string $d = ''): string { return $d; } function clean(mixed $s, bool $m = false): string { return trim((string)$s); } function company(): array { return ['name' => 'Fa', 'owner' => '']; } function money(float $v): string { return number_format($v, 2, ',', '.') . ' €'; } function fdate(?string $d): string { return $d ? date('d.m.Y', strtotime($d)) : ''; } }
namespace {
require __DIR__ . '/../../src/Templates.php';
use App\Templates as T;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . var_export($got, true) . ", erwartet " . var_export($want, true) . "\n"; } }
eq('fill basic', T::fill('Nr {nummer} über {BETRAG}', ['NUMMER' => 'RE-1', 'BETRAG' => '5,00 €']), 'Nr RE-1 über 5,00 €');
eq('fill case', T::fill('{Nummer}{NUMMER}{nummer}', ['NUMMER' => 'x']), 'xxx');
eq('known but unset stays empty', T::fill('a{link}b{faellig}c', ['NUMMER' => 'x']), 'abc');
eq('unknown stays', T::fill('a{betrg}b', []), 'a{betrg}b');
eq('no recursion', T::fill('{kunde}', ['KUNDE' => '{nummer}', 'NUMMER' => 'X']), '{nummer}');
eq('braces w/o name', T::fill('{ } {1} {}', []), '{ } {1} {}');
eq('unknown list', T::unknown('{nummer} {foo} {Foo} {BAR}'), ['{foo}', '{BAR}']);
eq('unknown none', T::unknown('{nummer} {gueltig_bis} {LINK}'), []);
foreach (T::DEFS as $k => $d) { eq('default ok ' . $k, T::unknown($d[3]), []); }
$v = T::vars(['number' => 'AN-1', 'issue_date' => '2026-10-30', 'valid_until' => '2026-11-29', 'c_name' => 'K', 'c_contact' => 'Erika', 'totals' => ['gross' => 119.0, 'net' => 100.0], 'co' => ['name' => 'Fa', 'owner' => 'Chef']], 'offer');
eq('vars anrede', $v['ANREDE'], 'Guten Tag Erika');
eq('vars gueltig', $v['GUELTIG_BIS'], '29.11.2026');
eq('vars faellig leer im Angebot', $v['FAELLIG'], '');
eq('vars absender', $v['ABSENDER'], 'Chef');
eq('vars betrag', $v['BETRAG'], '119,00 €');
$v2 = T::vars(['number' => 'R', 'c_contact' => '', 'totals' => [], 'co' => ['name' => 'Fa', 'owner' => '']], 'invoice');
eq('anrede ohne Kontakt', $v2['ANREDE'], 'Guten Tag'); eq('absender ohne Inhaber', $v2['ABSENDER'], 'Fa');
echo "Templates: $n Prüfungen, " . ($n - $fail) . " bestanden\n"; exit($fail ? 1 : 0);
}
