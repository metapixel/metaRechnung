<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Sehr einfacher Fehlversuchs-Zähler (Dateien in storage/ratelimit/), damit Anmeldung und Status-Token
 * nicht beliebig oft durchprobiert werden können. Funktioniert auch auf Webspace ohne Redis/APCu.
 */
final class RateLimit
{
    private static function file(string $key): string
    {
        $dir = dirname(__DIR__) . '/storage/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir . '/' . hash('sha256', $key) . '.json';
    }

    /**
     * Client-IP für Sperren und Protokoll. Standard: nur REMOTE_ADDR (X-Forwarded-For lässt sich fälschen).
     * Steht die direkte Gegenstelle in TRUSTED_PROXIES (Liste aus IPs/CIDR, z. B. "172.16.0.0/12,10.0.0.5"),
     * wird X-Forwarded-For von rechts gelesen; die erste nicht vertrauenswürdige Adresse ist der Client.
     */
    public static function ip(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES'))));
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!$trusted || !is_string($xff) || $xff === '' || !self::inList($remote, $trusted)) {
            return $remote;
        }
        $chain = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($chain as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_IP) === false) {
                return $remote; // unplausible Kette: lieber die direkte Adresse nehmen
            }
            if (!self::inList($addr, $trusted)) {
                return $addr;
            }
        }
        return $remote;
    }

    /** @param string[] $list IPs oder CIDR-Bereiche (IPv4/IPv6) */
    public static function inList(string $ip, array $list): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($list as $entry) {
            [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $nb = @inet_pton((string)$net);
            if ($nb === false || strlen($nb) !== strlen($bin)) {
                continue;
            }
            $max = strlen($nb) * 8;
            $n = $bits === null ? $max : (ctype_digit($bits) ? (int)$bits : -1);
            if ($n < 0 || $n > $max) {
                continue;
            }
            $full = intdiv($n, 8);
            if ($full > 0 && substr($bin, 0, $full) !== substr($nb, 0, $full)) {
                continue;
            }
            $rest = $n % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$full]) & $mask) === (ord($nb[$full]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** @return int[] Zeitstempel der Fehlversuche innerhalb des Fensters */
    private static function load(string $key, int $window): array
    {
        $f = self::file($key);
        $data = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        $now = time();
        return array_values(array_filter(is_array($data) ? $data : [], fn($t) => is_int($t) && $t > $now - $window));
    }

    public static function blocked(string $key, int $max, int $window): bool
    {
        return count(self::load($key, $window)) >= $max;
    }

    /** Wartezeit in Sekunden, bis der Sperre-Zeitraum endet (0 = nicht gesperrt). */
    public static function retryAfter(string $key, int $max, int $window): int
    {
        $l = self::load($key, $window);
        return count($l) >= $max ? max(1, $l[0] + $window - time()) : 0;
    }

    public static function fail(string $key, int $window): void
    {
        $f = self::file($key);
        $h = @fopen($f, 'c+');
        if (!$h) {
            return;
        }
        flock($h, LOCK_EX);
        $data = json_decode((string)stream_get_contents($h), true);
        $now = time();
        $data = array_values(array_filter(is_array($data) ? $data : [], fn($t) => is_int($t) && $t > $now - $window));
        $data[] = $now;
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($data));
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
    }

    public static function clear(string $key): void
    {
        // Geprüft: Pfad wird serverseitig gebildet (feste Ordner, kein Benutzerpfad)
        // nosemgrep
        @unlink(self::file($key));
    }
}
