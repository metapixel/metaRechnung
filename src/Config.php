<?php
/*
 * Rechnungs-App – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

final class Config
{
    public static function path(): string
    {
        return dirname(__DIR__) . '/config.php';
    }

    public static function writable(): bool
    {
        return is_writable(self::path()) || (!is_file(self::path()) && is_writable(dirname(self::path())));
    }

    /** Ändert Werte in config.php (bestehende Schlüssel bleiben erhalten). */
    public static function save(array $changes): void
    {
        $new = array_merge($GLOBALS['APP_CONFIG'], array_map('strval', $changes));
        $code = "<?php\n// Von der App erzeugt. Enthält Zugangsdaten – nicht weitergeben.\nreturn " . var_export($new, true) . ";\n";
        $path = self::path();
        $tmp = dirname($path) . '/config.tmp.php';
        if (@file_put_contents($tmp, $code, LOCK_EX) !== false && @rename($tmp, $path)) {
            // ok
        } else {
            // Geprüft: Pfad wird serverseitig gebildet (feste Ordner, kein Benutzerpfad)
            // nosemgrep
            @unlink($tmp);
            if (@file_put_contents($path, $code, LOCK_EX) === false) {
                throw new \RuntimeException('config.php ist nicht beschreibbar – bitte Schreibrechte prüfen.');
            }
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        $GLOBALS['APP_CONFIG'] = $new;
    }

    public static function ibanValid(string $iban): bool
    {
        $i = strtoupper(str_replace(' ', '', $iban));
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $i)) {
            return false;
        }
        $r = substr($i, 4) . substr($i, 0, 4);
        $n = '';
        foreach (str_split($r) as $c) {
            $n .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c;
        }
        $rem = 0;
        foreach (str_split($n, 7) as $chunk) {
            $rem = (int)($rem . $chunk) % 97;
        }
        return $rem === 1;
    }
}
