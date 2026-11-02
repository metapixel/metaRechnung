<?php
// Logik-Tests für PayPal.me-Link und Schriftprüfung.  Aufruf: php tests/unit/paypal_fonts_test.php
declare(strict_types=1);
namespace App { function env(string $k, string $d = ''): string { return $d; } function clean(mixed $s, bool $m = false): string { return trim((string)$s); } }
namespace {
require __DIR__ . '/../../src/PayPal.php';
require __DIR__ . '/../../src/Fonts.php';
use App\PayPal as P;
use App\Fonts as F;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . var_export($got, true) . ", erwartet " . var_export($want, true) . "\n"; } }
foreach (['kai' => 'kai', ' Kai99 ' => 'Kai99', 'https://www.paypal.me/MetaTest/25' => 'MetaTest', 'paypal.me/abc' => 'abc', '' => '', 'ab' => null, 'a b c' => null, 'x/../y' => null, str_repeat('a', 21) => null, 'https://evil.example/abc' => null, '<script>' => null, 'ääää' => null] as $in => $want) {
    eq('normalize ' . $in, P::normalize($in), $want);
}
$inv = ['co' => ['paypal' => 'kai'], 'totals' => ['gross' => 119.0]];
eq('url', P::url($inv), 'https://www.paypal.me/kai/119.00EUR');
eq('url Rundung', P::url(['co' => ['paypal' => 'kai'], 'totals' => ['gross' => 10.005]]), 'https://www.paypal.me/kai/10.01EUR');
eq('url Storno', P::url($inv + ['cancels_id' => 3]), null);
eq('url ohne Name', P::url(['co' => ['paypal' => ''], 'totals' => ['gross' => 5.0]]), null);
eq('url Betrag 0', P::url(['co' => ['paypal' => 'kai'], 'totals' => ['gross' => 0.0]]), null);
eq('url negativ', P::url(['co' => ['paypal' => 'kai'], 'totals' => ['gross' => -5.0]]), null);
eq('url ohne co', P::url(['totals' => ['gross' => 5.0]]), null);
$ttf = '/usr/share/fonts/truetype/crosextra/Carlito-Regular.ttf';
if (is_file($ttf)) {
    $b = (string)file_get_contents($ttf);
    eq('inspect Name', F::inspect($b), 'Carlito');
    foreach (['otto' => 'OTTO' . substr($b, 4), 'ttc' => 'ttcf' . substr($b, 4), 'text' => str_repeat('hallo welt ', 20), 'kurz' => 'abc', 'abgeschnitten' => substr($b, 0, 200)] as $k => $bad) {
        $n++; try { F::inspect($bad); $fail++; echo "FAIL inspect $k: keine Ausnahme\n"; } catch (\RuntimeException $e) { }
    }
    // fsType Restricted
    $cnt = unpack('n', substr($b, 4, 2))[1]; $p = $b;
    for ($i = 0; $i < $cnt; $i++) { $r = substr($b, 12 + 16 * $i, 16); if (substr($r, 0, 4) === 'OS/2') { $off = unpack('N', substr($r, 8, 4))[1]; $p = substr($b, 0, $off + 8) . "\x00\x02" . substr($b, $off + 10); } }
    $n++; try { F::inspect($p); $fail++; echo "FAIL fsType: keine Ausnahme\n"; } catch (\RuntimeException $e) { eq('fsType Meldung', str_contains($e->getMessage(), 'Lizenz'), true); }
}
echo "PayPal/Fonts: $n Prüfungen, " . ($n - $fail) . " bestanden\n";
exit($fail ? 1 : 0);
}
