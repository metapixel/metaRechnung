<?php
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

    public static function ip(): string
    {
        // Bewusst nur REMOTE_ADDR: X-Forwarded-For lässt sich vom Angreifer fälschen.
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
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
        @unlink(self::file($key));
    }
}
