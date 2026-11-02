<?php
// Logik-Tests für 2FA (TOTP, Wiederherstellungscodes).  Aufruf: php tests/unit/totp_test.php
declare(strict_types=1);
namespace App { function env(string $k, string $d = ''): string { return $GLOBALS['ENV'][$k] ?? $d; } }
namespace {
require __DIR__ . '/../../src/Totp.php';
use App\Totp as T;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . var_export($got, true) . ", erwartet " . var_export($want, true) . "\n"; } }
$sec = T::base32Encode('12345678901234567890');
eq('base32 encode', $sec, 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
eq('base32 roundtrip', T::base32Decode($sec), '12345678901234567890');
eq('base32 ungültig', T::base32Decode('1!'), '');
// RFC 6238 Anhang B (SHA-1), auf 6 Stellen gekürzt
foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $t => $c) {
    eq("rfc6238 t=$t", T::code($sec, intdiv($t, 30)), $c);
}
eq('verify ok', T::verify($sec, '287082', 0, 59), 1);
eq('verify mit Leerzeichen', T::verify($sec, '287 082', 0, 59), 1);
eq('verify Toleranz -1 Schritt', T::verify($sec, '287082', 0, 59 + 30), 1);
eq('verify außerhalb Fenster', T::verify($sec, '287082', 0, 59 + 120), null);
eq('verify Replay', T::verify($sec, '287082', 1, 59), null);
eq('verify falsche Länge', T::verify($sec, '28708', 0, 59), null);
eq('verify Buchstaben', T::verify($sec, 'abcdef', 0, 59), null);
eq('verify ungültiges Secret', T::verify('abc', '287082', 0, 59), null);
eq('validSecret', [T::validSecret($sec), T::validSecret(''), T::validSecret(strtolower($sec)), T::validSecret(T::newSecret())], [true, false, false, true]);
eq('newSecret verschieden', T::newSecret() !== T::newSecret(), true);
$uri = T::uri($sec, 'kai@x');
eq('uri', str_starts_with($uri, 'otpauth://totp/metaRechnung%3Akai%40x?secret=' . $sec . '&issuer=metaRechnung'), true);
$codes = T::newRecoveryCodes();
eq('10 Codes', count($codes), 10);
eq('Format', count(array_filter($codes, fn($c) => preg_match('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/D', $c))), 10);
eq('eindeutig', count(array_unique($codes)), 10);
eq('normalize', T::normalizeRecovery(' ab-cd ef.12 '), 'ABCDEF12');
eq('hash stabil', T::hashRecovery('abcde-fghjk'), T::hashRecovery('ABCDEFGHJK'));
eq('hash 64 hex', (bool)preg_match('/^[0-9a-f]{64}$/D', T::hashRecovery($codes[0])), true);
echo "Totp: $n Prüfungen, " . ($n - $fail) . " bestanden\n";
exit($fail ? 1 : 0);
}
