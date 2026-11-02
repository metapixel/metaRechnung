<?php
// Logik-Tests für den vCard-Import.  Aufruf: php tests/unit/vcf_test.php
declare(strict_types=1);
namespace App { function clean(mixed $s, bool $m = false): string { return trim((string)$s); } }
namespace {
require __DIR__ . '/../../src/CustomerImport.php';
require __DIR__ . '/../../src/VcfImport.php';
use App\VcfImport as V;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . var_export($got, true) . ", erwartet " . var_export($want, true) . "\n"; } }
function thr(string $name, callable $f): void { global $fail, $n; $n++; try { $f(); $fail++; echo "FAIL $name: keine Ausnahme\n"; } catch (\RuntimeException $e) { } }
$r = V::toRows("BEGIN:VCARD\r\nVERSION:3.0\r\nN:Mustermann;Erika;;Dr.;\r\nFN:Dr. Erika Mustermann\r\nORG:Beispiel\\, GmbH;Abt\r\nitem1.EMAIL;TYPE=HOME:privat@x.de\r\nEMAIL;TYPE=WORK:erika@x.de\r\nADR;TYPE=WORK:;;Musterstr. 1;Papenburg;;26871;Deutschland\r\nTEL:+49 1\r\nEND:VCARD\r\n");
eq('Kopf', $r[0], V::HEADER);
eq('Firma+Person', $r[1], ['Beispiel, GmbH', 'Dr. Erika Mustermann', 'erika@x.de', 'Musterstr. 1', '26871', 'Papenburg', 'Deutschland']);
$r = V::toRows("BEGIN:VCARD\nVERSION:2.1\nFN;CHARSET=ISO-8859-1:Hans M\xfcller\nADR;HOME;ENCODING=QUOTED-PRINTABLE:;;Stra=C3=9Fe 2=\n a;K=C3=B6ln;;50667;DE\nEND:VCARD\n");
eq('2.1 QP+Latin-1', $r[1], ['Hans Müller', '', '', 'Straße 2 a', '50667', 'Köln', 'DE']);
$r = V::toRows("BEGIN:VCARD\nFN:Lange\nADR:;;Stra\n ße 9;Berlin;;10115;DE\nEND:VCARD");
eq('Faltung', $r[1][3], 'Straße 9');
$r = V::toRows("BEGIN:VCARD\nFN:A\nPHOTO;ENCODING=b:AAAA=\nEND:VCARD\nBEGIN:VCARD\nFN:B\nEND:VCARD\n");
eq('Foto mit = am Zeilenende verschluckt keine Karte', count($r), 3);
$r = V::toRows("\xEF\xBB\xBFBEGIN:VCARD\nFN:Bom\nEND:VCARD");
eq('BOM', $r[1][0], 'Bom');
$r = V::toRows("BEGIN:VCARD\nFN:Nur Person\nEMAIL:a@b.de\nEND:VCARD");
eq('Person ohne Firma ist Kunde', [$r[1][0], $r[1][1]], ['Nur Person', '']);
$r = V::toRows("BEGIN:VCARD\nFN:X\nADR:;Zusatz;Weg 1;Ort;;1;DE\nEND:VCARD");
eq('Adresszusatz', $r[1][3], 'Weg 1, Zusatz');
eq('csv', str_starts_with(V::toCsv(V::toRows("BEGIN:VCARD\nFN:A;B\nEND:VCARD")), "Name;Ansprechpartner;"), true);
thr('leer', fn() => V::toRows('nichts'));
thr('binär', fn() => V::toRows("BEGIN:VCARD\0\n"));
eq('looksLike', [V::looksLikeVcard(" \nBEGIN:vcard"), V::looksLikeVcard('Name;Straße')], [true, false]);
echo "Vcf: $n Prüfungen, " . ($n - $fail) . " bestanden\n";
exit($fail ? 1 : 0);
}
