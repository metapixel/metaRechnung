<?php
// Logik-Tests für Validierung und CSS des PDF-Designs.  Aufruf: php tests/unit/pdfdesign_test.php
declare(strict_types=1);
namespace App { function env(string $k, string $d = ''): string { return $GLOBALS['ENV'][$k] ?? $d; } }
namespace {
require __DIR__ . '/../../src/PdfDesign.php';
use App\PdfDesign as P;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . var_export($got, true) . ", erwartet " . var_export($want, true) . "\n"; } }
$ok = ['font' => 'dejavusans', 'size' => 'large', 'accent' => '#1D4ED8', 'table' => 'zebra', 'sender' => 'left', 'title_accent' => '1', 'footer' => '1', 'closing' => "Danke\r\n\r\n\r\n\r\nschön", 'footer_text' => 'HRB 1'];
[$d, $e] = P::fromForm($ok);
eq('gültig ohne Fehler', $e, []);
eq('Farbe klein', $d['accent'], '#1d4ed8');
eq('Checkbox an/aus', [$d['title_accent'], $d['footer'], $d['addrline'], $d['pagenum']], [true, true, false, false]);
eq('Umbrüche normalisiert', $d['closing'], "Danke\n\nschön");
foreach (['font' => 'comic', 'size' => 'huge', 'table' => 'x', 'sender' => 'mitte', 'accent' => 'red'] as $k => $v) {
    [$_, $er] = P::fromForm([$k => $v] + $ok);
    eq("ungültig $k", count($er) >= 1, true);
}
foreach (['url(x)', '#12345', '#12345g', '#1234567', "#123456\n", 'javascript:1', ['#111111']] as $i => $v) {
    [$_, $er] = P::fromForm(['accent' => $v] + $ok);
    eq("Farbe ungültig $i", count($er), 1);
}
[$_, $er] = P::fromForm(['closing' => str_repeat('a', 501)] + $ok); eq('Schlusstext zu lang', count($er), 1);
[$_, $er] = P::fromForm(['footer_text' => ['x']] + $ok); eq('Array als Text', count($er) >= 1, true);
[$_, $er] = P::fromForm([]); eq('leer → Fehler', count($er) >= 5, true);
eq('Steuerzeichen raus', P::text("a\x00b\x07c\td\n", 50), "abc\td");
// sanitize: Fremdes/Defekte fällt auf Standard
$s = P::sanitize(['font' => '}{', 'accent' => 'url(javascript:1)', 'table' => 5, 'size' => ['x'], 'closing' => 123]);
eq('sanitize Standardwerte', [$s['font'], $s['accent'], $s['table'], $s['size'], $s['closing']], ['dejavusanscondensed', '#333333', 'lines', 'normal', '']);
// CSS enthält nur Erlaubtes
$css = P::css(['font' => 'dejavusans', 'accent' => '#1d4ed8', 'table' => 'filled', 'size' => 'normal', 'title_accent' => true]);
eq('CSS Farbe', str_contains($css, 'background-color: #1d4ed8; color: #ffffff'), true);
eq('CSS Schrift', str_contains($css, 'font-family: dejavusans;'), true);
eq('CSS ohne Fremdes', preg_match('/[<>@\\\\]|url\(|expression/i', $css), 0);
$classic = P::css(P::legacy());
eq('Klassisch = bisheriges Aussehen', [str_contains($classic, 'border-bottom: 1px solid #333333'), str_contains($classic, 'border-bottom: 0.5px solid #ccc'), str_contains($classic, 'font-size: 9.5pt'), str_contains($classic, 'h1 { font-size: 15pt; margin: 22px 0 8px 0; }')], [true, true, true, true]);
// Kontrast
eq('Kontrast Schwarz/Weiß', round(P::contrast('#000000', '#ffffff'), 1), 21.0);
eq('Text auf Dunkelblau weiß', P::onAccent('#1d4ed8'), '#ffffff');
eq('Text auf Gelb dunkel', P::onAccent('#ffd400'), '#111111');
eq('Abdunkeln erreicht 4,5', P::contrast(P::darkenToContrast('#ffd400'), '#ffffff') >= 4.5, true);
// Vorlagen vollständig und gültig
foreach (P::TEMPLATES as $k => $t) { $x = P::template($k); [$_, $er] = P::fromForm(array_map(static fn($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $x)); eq("Vorlage $k gültig", $er, []); }
// current(): defekte Konfiguration
$GLOBALS['ENV']['PDF_DESIGN'] = '{kaputt'; eq('current bei kaputtem JSON', P::current(), P::defaults());
$GLOBALS['ENV']['PDF_DESIGN'] = P::toJson($d); eq('Roundtrip', P::current(), $d);
echo $fail ? "$fail von $n Prüfungen fehlgeschlagen\n" : "Alle $n Prüfungen bestanden\n";
exit($fail ? 1 : 0);
}
