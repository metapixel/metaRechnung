<?php
/*
 * Rechnungs-App – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

$root = dirname(__DIR__);

if (!is_file($root . '/config.php')) {
    http_response_code(503);
    exit(is_file($root . '/install.php')
        ? 'Noch nicht installiert. <a href="install.php">Installation starten</a>'
        : 'Konfiguration (config.php) fehlt.');
}
if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    exit('Das Verzeichnis vendor/ fehlt. Bitte den Ordner vendor/ (oder vendor.zip) hochladen.');
}

$GLOBALS['APP_CONFIG'] = require $root . '/config.php';
define('APP_VERSION', is_file($root . '/VERSION') ? trim((string)file_get_contents($root . '/VERSION')) : 'unbekannt');
require $root . '/vendor/autoload.php';

// Eigene Klassen (Namespace App\) aus src/ laden
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

date_default_timezone_set('Europe/Berlin');

// Basis-Pfad der Installation (funktioniert im Hauptverzeichnis und in Unterordnern)
define('BASE', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/');

$https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

// --- Sicherheits-Header (unabhängig von mod_headers / .htaccess) ---------------------------------
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
    . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");

session_name('rechnung');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params(['lifetime' => 0, 'path' => BASE, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
if (!defined('NO_SESSION')) {
    session_start();
    csrf();   // jede Sitzung bekommt sofort ein Token (kein leeres Token möglich)
}

const UNITS = ['C62' => 'Stk.', 'HUR' => 'Std.', 'DAY' => 'Tag(e)', 'LS' => 'pauschal', 'MTR' => 'm', 'KGM' => 'kg'];

function env(string $key, string $default = ''): string
{
    $v = $GLOBALS['APP_CONFIG'][$key] ?? getenv($key);
    return ($v === false || $v === null) ? $default : (string)$v;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', 'db'), env('DB_NAME', 'rechnung')),
            env('DB_USER'),
            env('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function company(): array
{
    return [
        'name' => env('COMPANY_NAME'), 'owner' => env('COMPANY_OWNER'),
        'street' => env('COMPANY_STREET'), 'zip' => env('COMPANY_ZIP'), 'city' => env('COMPANY_CITY'),
        'country' => env('COMPANY_COUNTRY', 'DE'), 'email' => env('COMPANY_EMAIL'), 'phone' => env('COMPANY_PHONE'),
        'tax_number' => env('COMPANY_TAX_NUMBER'), 'vat_id' => env('COMPANY_VAT_ID'),
        'iban' => str_replace(' ', '', env('COMPANY_IBAN')), 'bic' => env('COMPANY_BIC'), 'bank' => env('COMPANY_BANK'),
    ];
}

function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float $v): string
{
    return number_format($v, 2, ',', '.') . ' €';
}

function fdate(?string $d): string
{
    return $d ? date('d.m.Y', strtotime($d)) : '';
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sess = (string)($_SESSION['csrf'] ?? '');
    $sent = $_POST['csrf'] ?? '';
    if ($sess === '' || !is_string($sent) || !hash_equals($sess, $sent)) {
        http_response_code(400);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden.');
    }
}

/** Nur interne Ziele (Pfad auf diesem Host); alles andere führt zur Startseite (kein Open Redirect, keine Header-Injection). */
function safe_target(?string $url): string
{
    $url = (string)$url;
    if ($url !== '' && $url[0] === '/' && !str_starts_with($url, '//') && !preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) {
        return $url;
    }
    return BASE;
}

function redirect(string $url): never
{
    header('Location: ' . safe_target($url));
    exit;
}

/** Steuerzeichen entfernen (Einzeiler); $multiline erlaubt Zeilenumbrüche und Tabs. */
function clean(mixed $s, bool $multiline = false): string
{
    $s = (string)$s;
    $s = str_replace("\r\n", "\n", $s);
    $re = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
    return trim((string)preg_replace($re, '', $s));
}

/** Stabiler Fingerabdruck der Zugangsdaten: ändert sich bei Passwort-/Benutzerwechsel → alte Sitzungen verfallen. */
function auth_fingerprint(): string
{
    return substr(hash('sha256', env('APP_USER') . '|' . env('APP_PASSWORD_HASH')), 0, 32);
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require __DIR__ . '/views/layout.php';
}

function app_url(): string
{
    $u = env('APP_URL');
    if ($u !== '') {
        return rtrim($u, '/') . '/';
    }
    $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    // Fallback ohne APP_URL: Host-Header nur akzeptieren, wenn er wie ein Hostname aussieht
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-]{1,253}(:\d{1,5})?$|^\[[0-9A-Fa-f:]+\](:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https' : 'http') . '://' . $host . BASE;
}

\App\Migrations::run();
