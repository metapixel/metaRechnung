<?php
// Tests für RateLimit::ip()/inList() (vertrauenswürdige Proxys).  Aufruf: php tests/unit/ratelimit_ip_test.php
declare(strict_types=1);
namespace App { function env(string $k, string $d = ''): string { return $GLOBALS['ENV'][$k] ?? $d; } }
namespace {
require __DIR__ . '/../../src/RateLimit.php';
use App\RateLimit as R;
$fail = 0; $n = 0;
function eq(string $name, mixed $got, mixed $want): void { global $fail, $n; $n++; if ($got !== $want) { $fail++; echo "FAIL $name: erhalten " . json_encode($got) . ", erwartet " . json_encode($want) . "\n"; } }
function ip(string $remote, string $xff = '', string $trusted = ''): string {
    $GLOBALS['ENV'] = ['TRUSTED_PROXIES' => $trusted]; $_SERVER['REMOTE_ADDR'] = $remote;
    if ($xff === '') unset($_SERVER['HTTP_X_FORWARDED_FOR']); else $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    return R::ip();
}
eq('Standard: nur REMOTE_ADDR', ip('203.0.113.9', '1.2.3.4'), '203.0.113.9');
eq('Standard ohne XFF', ip('203.0.113.9'), '203.0.113.9');
eq('Proxy vertrauenswürdig (CIDR)', ip('172.18.0.5', '198.51.100.7', '172.16.0.0/12'), '198.51.100.7');
eq('Proxy vertrauenswürdig (einzelne IP)', ip('10.0.0.5', '198.51.100.7', '10.0.0.5'), '198.51.100.7');
eq('Proxy NICHT vertrauenswürdig: XFF ignoriert', ip('203.0.113.9', '1.2.3.4', '172.16.0.0/12'), '203.0.113.9');
eq('Gefälschter Vorspann wird ignoriert (von rechts lesen)', ip('172.18.0.5', '6.6.6.6, 198.51.100.7', '172.16.0.0/12'), '198.51.100.7');
eq('Kette über zwei Proxys', ip('172.18.0.5', '198.51.100.7, 172.18.0.9', '172.16.0.0/12'), '198.51.100.7');
eq('Unplausible Kette → direkte Adresse', ip('172.18.0.5', 'unknown', '172.16.0.0/12'), '172.18.0.5');
eq('Alle vertrauenswürdig → direkte Adresse', ip('172.18.0.5', '172.18.0.9', '172.16.0.0/12'), '172.18.0.5');
eq('IPv6-Client', ip('172.18.0.5', '2001:db8::1', '172.16.0.0/12'), '2001:db8::1');
eq('IPv6-Proxy im Bereich', ip('fd00::5', '198.51.100.7', 'fd00::/8'), '198.51.100.7');
eq('Ungültiger Eintrag in der Liste wird übersprungen', ip('172.18.0.5', '198.51.100.7', 'quatsch, 172.16.0.0/99, 172.16.0.0/12'), '198.51.100.7');
eq('/0 trifft alles (bewusst vertraut)', R::inList('8.8.8.8', ['0.0.0.0/0']), true);
eq('/20 Grenze innerhalb', R::inList('10.0.15.255', ['10.0.0.0/20']), true);
eq('/20 Grenze außerhalb', R::inList('10.0.16.0', ['10.0.0.0/20']), false);
eq('v4-Adresse gegen v6-Netz', R::inList('10.0.0.1', ['fd00::/8']), false);
echo $fail ? "$fail von $n Tests FEHLGESCHLAGEN\n" : "Alle $n Tests bestanden\n";
exit($fail ? 1 : 0);
}
