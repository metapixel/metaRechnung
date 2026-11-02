<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

namespace App;

/**
 * Zwei-Faktor-Anmeldung per Authenticator-App (TOTP nach RFC 6238: HMAC-SHA1, 6 Stellen, 30 Sekunden)
 * plus Wiederherstellungscodes für den Fall, dass das Handy fehlt. Ohne externe Dienste.
 *
 * Gespeichert in config.php: TOTP_SECRET (Base32), TOTP_LAST (zuletzt akzeptierter Zeitschritt, gegen Wiederverwendung),
 * TOTP_RECOVERY (JSON-Liste von SHA-256-Hashes der noch gültigen Codes).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const WINDOW = 1;          // ±1 Zeitschritt Toleranz für Uhrabweichungen
    public const RECOVERY_COUNT = 10;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // ohne verwechselbare Zeichen

    public static function enabled(): bool
    {
        return self::validSecret(env('TOTP_SECRET'));
    }

    public static function validSecret(string $s): bool
    {
        return (bool)preg_match('/^[A-Z2-7]{32}$/D', $s);
    }

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper($s)) as $c) {
            $p = strpos(self::B32, $c);
            if ($p === false) {
                return '';
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int)bindec($byte));
            }
        }
        return $out;
    }

    /** Code für einen Zeitschritt (RFC 4226 mit dynamischer Kürzung). */
    public static function code(string $secret, int $step): string
    {
        $mac = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $o = ord($mac[19]) & 0x0F;
        $n = ((ord($mac[$o]) & 0x7F) << 24) | (ord($mac[$o + 1]) << 16) | (ord($mac[$o + 2]) << 8) | ord($mac[$o + 3]);
        return str_pad((string)($n % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Prüft einen eingegebenen Code. Gibt den Zeitschritt zurück, wenn er passt und größer als $after ist (kein zweites Mal verwendbar), sonst null.
     */
    public static function verify(string $secret, string $input, int $after = 0, ?int $now = null): ?int
    {
        $input = (string)preg_replace('/\D/', '', $input);
        if (strlen($input) !== self::DIGITS || !self::validSecret($secret)) {
            return null;
        }
        $cur = intdiv($now ?? time(), self::PERIOD);
        for ($s = $cur - self::WINDOW; $s <= $cur + self::WINDOW; $s++) {
            if ($s > $after && hash_equals(self::code($secret, $s), $input)) {
                return $s;
            }
        }
        return null;
    }

    /** otpauth-Adresse für den QR-Code. */
    public static function uri(string $secret, string $account): string
    {
        $issuer = 'metaRechnung';
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** @return list<string> neue Wiederherstellungscodes im Format XXXXX-XXXXX (im Klartext, nur zur einmaligen Anzeige) */
    public static function newRecoveryCodes(): array
    {
        $out = [];
        while (count($out) < self::RECOVERY_COUNT) {
            $c = '';
            for ($i = 0; $i < 10; $i++) {
                $c .= self::RECOVERY_ALPHABET[random_int(0, 31)];
            }
            $out[$c] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return array_values($out);
    }

    public static function normalizeRecovery(string $c): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $c));
    }

    public static function hashRecovery(string $c): string
    {
        return hash('sha256', 'rg-recovery|' . self::normalizeRecovery($c));
    }

    /** @return list<string> */
    public static function recoveryHashes(): array
    {
        $l = json_decode(env('TOTP_RECOVERY', '[]'), true);
        return is_array($l) ? array_values(array_filter($l, static fn($x) => is_string($x) && preg_match('/^[0-9a-f]{64}$/D', $x))) : [];
    }

    /** Löst einen Wiederherstellungscode ein (einmalig). Gibt die Zahl der verbleibenden Codes zurück oder null, wenn ungültig. */
    public static function useRecovery(string $input): ?int
    {
        $n = self::normalizeRecovery($input);
        if (strlen($n) !== 10) {
            return null;
        }
        $h = self::hashRecovery($n);
        $list = self::recoveryHashes();
        foreach ($list as $i => $x) {
            if (hash_equals($x, $h)) {
                unset($list[$i]);
                $list = array_values($list);
                Config::save(['TOTP_RECOVERY' => json_encode($list)]);
                return count($list);
            }
        }
        return null;
    }

    /** @param list<string> $plain */
    public static function saveRecovery(array $plain): void
    {
        Config::save(['TOTP_RECOVERY' => json_encode(array_map([self::class, 'hashRecovery'], $plain))]);
    }
}
