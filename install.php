<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

/**
 * Installer für metaRechnung (Webspace).
 * Aufruf im Browser: https://deine-domain.de/<ordner>/install.php
 * Nach erfolgreicher Installation diese Datei löschen (geht per Knopf auf der Abschlussseite).
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(300);

$root = __DIR__;
$appVersion = is_file($root . '/VERSION') ? trim((string)file_get_contents($root . '/VERSION')) : '?';
const MIN_PHP = '8.1.0';

function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function val(string $k, string $d = ''): string { return h($_POST[$k] ?? $d); }
function post(string $k): string { return trim((string)($_POST[$k] ?? '')); }

const DENY_HTACCESS = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n";

function ensure_protected(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', DENY_HTACCESS);
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
}

/** @return array<int, array{0:string,1:bool,2:string,3:bool}> [Label, ok, Hinweis, blockiert-Installation] */
function requirements(string $root): array
{
    $r = [];
    $r[] = ['PHP ≥ ' . MIN_PHP . ' (aktuell ' . PHP_VERSION . ')', version_compare(PHP_VERSION, MIN_PHP, '>='), 'Im Hosting-Panel eine neuere PHP-Version wählen.', true];
    foreach (['pdo_mysql' => true, 'mbstring' => true, 'gd' => true, 'dom' => true, 'xml' => true, 'simplexml' => true, 'libxml' => true, 'json' => true, 'ctype' => true] as $ext => $fatal) {
        $r[] = ['PHP-Erweiterung ' . $ext, extension_loaded($ext), 'Im Hosting-Panel aktivieren (PHP-Einstellungen/Erweiterungen).', $fatal];
    }
    $haveVendor = is_file($root . '/vendor/autoload.php');
    $r[] = ['Bibliotheken (vendor/)', $haveVendor || (is_file($root . '/vendor.zip') && class_exists('ZipArchive')),
        $haveVendor ? '' : 'Weder vendor/ noch vendor.zip (+ PHP-Erweiterung zip) gefunden.', true];
    $r[] = ['Ordner beschreibbar (für config.php, storage/)', is_writable($root), 'Schreibrechte für das Installationsverzeichnis setzen (z. B. 755 bzw. Besitzer = PHP-Benutzer).', true];
    $mem = ini_get('memory_limit');
    $bytes = $mem === '-1' ? PHP_INT_MAX : (int)$mem * (stripos($mem, 'g') !== false ? 1073741824 : (stripos($mem, 'm') !== false ? 1048576 : (stripos($mem, 'k') !== false ? 1024 : 1)));
    $r[] = ['memory_limit ≥ 128M (aktuell ' . $mem . ')', $bytes >= 134217728, 'PDF-Erzeugung braucht Speicher; ggf. im Panel erhöhen.', false];
    return $r;
}

function http_probe(string $url): ?int
{
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'method' => 'GET'], 'ssl' => ['verify_peer' => false]]);
    if (ini_get('allow_url_fopen')) {
        $body = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) {
            return (int)$m[1];
        }
        return $body === false ? null : 200;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code ?: null;
    }
    return null;
}

function base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

/** App-Adresse aus der aktuellen Anfrage (nur bei plausiblem Hostnamen), sonst leer. */
function detect_app_url(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-]{1,253}(:\d{1,5})?$/', $host)) {
        return '';
    }
    return base_url();
}

function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>metaRechnung</title><link rel="stylesheet" href="style.css">'
        . '<style>fieldset{border:1px solid var(--line);border-radius:12px;margin:16px 0;padding:8px 16px 16px}legend{font-weight:600;padding:0 6px}'
        . '.ok{color:var(--ok)}.no{color:var(--bad)}.warn{color:var(--warn)}ul.chk{list-style:none;padding:0}ul.chk li{padding:3px 0}pre.log{background:var(--card);border:1px solid var(--line);padding:10px;border-radius:8px;white-space:pre-wrap}</style>'
        . '</head><body><main>' . $body . '</main></body></html>';
    exit;
}

// ---------------------------------------------------------------- bereits installiert?
if (is_file($root . '/config.php')) {
    if (($_POST['action'] ?? '') === 'selfdelete') {
        // Geprüft: Pfad wird serverseitig gebildet (feste Ordner, kein Benutzerpfad)
        // nosemgrep
        $ok = @unlink(__FILE__);
        if ($ok && is_file($root . '/vendor.zip') && is_file($root . '/vendor/autoload.php')) {
            // Geprüft: Pfad wird serverseitig gebildet (feste Ordner, kein Benutzerpfad)
            // nosemgrep
            @unlink($root . '/vendor.zip');
        }
        page('Installer entfernt', $ok
            ? '<div class="card"><h1>Installer gelöscht</h1><p>Die Datei <code>install.php</code> wurde entfernt.</p><a class="btn primary" href="index.php">Zur Anmeldung</a></div>'
            : '<div class="card"><h1>Löschen nicht möglich</h1><p class="no">Bitte <code>install.php</code> (und <code>vendor.zip</code>) per FTP löschen.</p></div>');
    }
    page('Bereits installiert', '<div class="card"><h1>Bereits installiert</h1>'
        . '<p>Es existiert schon eine <code>config.php</code>. Aus Sicherheitsgründen sollte <code>install.php</code> jetzt entfernt werden.</p>'
        . '<form method="post"><input type="hidden" name="action" value="selfdelete"><button class="btn danger">install.php jetzt löschen</button> '
        . '<a class="btn primary" href="index.php">Zur Anmeldung</a></form></div>');
}

$reqs = requirements($root);
$blocked = false;
foreach ($reqs as $q) {
    if (!$q[1] && $q[3]) {
        $blocked = true;
    }
}

// ---------------------------------------------------------------- Installation ausführen
$errors = [];
if (($_POST['action'] ?? '') === 'install' && !$blocked) {
    $log = [];
    try {
        foreach (['db_host' => 'Datenbank-Server', 'db_name' => 'Datenbankname', 'db_user' => 'Datenbank-Benutzer', 'admin_user' => 'Login-Benutzername',
                  'company_name' => 'Firmenname', 'company_street' => 'Straße', 'company_zip' => 'PLZ', 'company_city' => 'Ort'] as $k => $label) {
            if (post($k) === '') {
                $errors[] = $label . ' fehlt.';
            }
        }
        if (strlen((string)($_POST['admin_pass'] ?? '')) < 10) {
            $errors[] = 'Das Passwort braucht mindestens 10 Zeichen.';
        }
        if (($_POST['admin_pass'] ?? '') !== ($_POST['admin_pass2'] ?? '')) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }
        if (post('company_email') !== '' && !filter_var(post('company_email'), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Firmen-E-Mail ist ungültig.';
        }
        if (post('smtp_host') !== '' && post('mail_from') === '' && post('company_email') === '') {
            $errors[] = 'Bitte eine Absenderadresse für den Mailversand angeben.';
        }
        if ($errors) {
            throw new RuntimeException('Eingaben unvollständig.');
        }

        // 1. vendor entpacken
        if (!is_file($root . '/vendor/autoload.php')) {
            $zip = new ZipArchive();
            if ($zip->open($root . '/vendor.zip') !== true || !$zip->extractTo($root)) {
                throw new RuntimeException('vendor.zip konnte nicht entpackt werden.');
            }
            $zip->close();
            $log[] = '✔ Bibliotheken entpackt (vendor/)';
        } else {
            $log[] = '✔ Bibliotheken vorhanden (vendor/)';
        }
        if (!is_file($root . '/vendor/autoload.php')) {
            throw new RuntimeException('vendor/autoload.php fehlt nach dem Entpacken.');
        }

        // 2. Datenbank
        $dsn = 'mysql:host=' . post('db_host') . (post('db_port') !== '' ? ';port=' . (int)post('db_port') : '') . ';dbname=' . post('db_name') . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, post('db_user'), (string)($_POST['db_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            throw new RuntimeException('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
        }
        $log[] = '✔ Datenbankverbindung OK';

        $existing = $pdo->query("SHOW TABLES LIKE 'invoices'")->fetchColumn();
        if ($existing && (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() > 0) {
            throw new RuntimeException('In dieser Datenbank existiert bereits eine befüllte Tabelle „invoices“. Zur Sicherheit wird nichts überschrieben – bitte eine leere Datenbank verwenden.');
        }
        $pdo->exec((string)file_get_contents($root . '/src/schema.sql'));
        $log[] = '✔ Tabellen angelegt (customers, invoices, invoice_items)';

        // 3. Ordner + Schutz
        foreach (['storage', 'storage/invoices', 'storage/tmp'] as $d) {
            if (!is_dir($root . '/' . $d) && !mkdir($root . '/' . $d, 0755, true)) {
                throw new RuntimeException('Ordner ' . $d . ' konnte nicht angelegt werden.');
            }
            ensure_protected($root . '/' . $d);
        }
        file_put_contents($root . '/storage/schema_version', '2');
        ensure_protected($root . '/src');
        ensure_protected($root . '/vendor');
        $log[] = '✔ Ordner storage/ angelegt und gegen Webzugriff geschützt';

        // 4. config.php
        $cfg = [
            'DB_HOST' => post('db_host') . (post('db_port') !== '' ? ';port=' . (int)post('db_port') : ''),
            'DB_NAME' => post('db_name'), 'DB_USER' => post('db_user'), 'DB_PASS' => (string)($_POST['db_pass'] ?? ''),
            'APP_USER' => post('admin_user'),
            'APP_PASSWORD_HASH' => password_hash((string)$_POST['admin_pass'], PASSWORD_DEFAULT),
            'COMPANY_NAME' => post('company_name'), 'COMPANY_OWNER' => post('company_owner'),
            'COMPANY_STREET' => post('company_street'), 'COMPANY_ZIP' => post('company_zip'), 'COMPANY_CITY' => post('company_city'),
            'COMPANY_COUNTRY' => strtoupper(post('company_country') ?: 'DE'),
            'COMPANY_EMAIL' => post('company_email'), 'COMPANY_PHONE' => post('company_phone'),
            'COMPANY_TAX_NUMBER' => post('company_tax_number'), 'COMPANY_VAT_ID' => post('company_vat_id'),
            'COMPANY_IBAN' => post('company_iban'), 'COMPANY_BIC' => post('company_bic'), 'COMPANY_BANK' => post('company_bank'),
            'INVOICE_PREFIX' => preg_replace('/[^A-Za-z0-9]/', '', post('invoice_prefix')) ?: 'RE',
            'PAYMENT_DAYS' => (string)max(0, (int)(post('payment_days') ?: 14)),
            'KLEINUNTERNEHMER' => isset($_POST['kleinunternehmer']) ? '1' : '0',
            'TEST_MODE' => isset($_POST['test_mode']) ? '1' : '0',
            'SMTP_HOST' => post('smtp_host'), 'SMTP_PORT' => post('smtp_port') ?: '587', 'SMTP_SECURE' => post('smtp_secure') ?: 'tls',
            'SMTP_USER' => post('smtp_user'), 'SMTP_PASS' => (string)($_POST['smtp_pass'] ?? ''),
            'MAIL_FROM' => post('mail_from') ?: post('company_email'),
            'MAIL_FROM_NAME' => post('mail_from_name') ?: post('company_name'),
            'MAIL_BCC' => post('mail_bcc'),
            // Feste App-Adresse (für Links in Mails); verhindert Host-Header-Manipulation
            'APP_URL' => detect_app_url(),
        ];
        $code = "<?php\n// Von install.php erzeugt. Enthält Zugangsdaten – nicht weitergeben.\nreturn " . var_export($cfg, true) . ";\n";
        if (file_put_contents($root . '/config.php', $code, LOCK_EX) === false) {
            throw new RuntimeException('config.php konnte nicht geschrieben werden.');
        }
        $log[] = '✔ config.php geschrieben';

        // 5. Schutz von außen prüfen
        $base = base_url();
        file_put_contents($root . '/storage/probe.txt', 'probe');
        file_put_contents($root . '/src/probe.txt', 'probe');
        $leaks = [];
        $unchecked = false;
        foreach (['storage/probe.txt', 'src/probe.txt'] as $f) {
            $code = http_probe($base . $f);
            if ($code === 200) {
                $leaks[] = $f;
            }
            if ($code === null) {
                $unchecked = true;
                $log[] = '⚠ Webzugriff auf ' . $f . ' konnte nicht geprüft werden – bitte ' . $base . $f . ' manuell aufrufen: es darf KEIN Inhalt erscheinen.';
            }
        }
        // Geprüft: Pfad wird serverseitig gebildet (feste Ordner, kein Benutzerpfad)
        // nosemgrep
        @unlink($root . '/storage/probe.txt');
        // Geprüft: Pfad wird serverseitig gebildet (feste Ordner, kein Benutzerpfad)
        // nosemgrep
        @unlink($root . '/src/probe.txt');

        $done = '<div class="card"><h1>Installation abgeschlossen ✔</h1><pre class="log">' . h(implode("\n", $log)) . '</pre>';
        if ($leaks) {
            $done .= '<p class="no"><b>Achtung:</b> Die Ordner ' . h(implode(', ', $leaks)) . ' sind von außen erreichbar (Server ohne .htaccess-Unterstützung, z. B. nginx). '
                . 'Rechnungs-PDFs wären damit abrufbar, wenn jemand den Dateinamen errät. Bitte im Hosting-Panel den Zugriff auf <code>storage/</code>, <code>src/</code>, <code>vendor/</code> sperren '
                . 'oder den Support fragen – oder die Anwendung außerhalb des Web-Root betreiben.</p>';
        } elseif ($unchecked) {
            $done .= '<p class="warn">Der Webzugriffsschutz konnte nicht automatisch geprüft werden. Bitte die oben genannten Adressen im Browser aufrufen – es muss „403 Forbidden“ (oder 404) erscheinen, <b>nicht</b> das Wort „probe“.</p>';
        } else {
            $done .= '<p class="ok">Die Ordner storage/ und src/ sind von außen nicht abrufbar.</p>';
        }
        $done .= '<p>Aus Sicherheitsgründen jetzt den Installer entfernen:</p>'
            . '<form method="post"><input type="hidden" name="action" value="selfdelete"><button class="btn danger">install.php (und vendor.zip) jetzt löschen</button> '
            . '<a class="btn primary" href="index.php">Zur Anmeldung</a></form></div>';
        page('Installation abgeschlossen', $done);
    } catch (Throwable $e) {
        if (!$errors) {
            $errors[] = $e->getMessage();
        }
        $failLog = $log;
    }
}

// ---------------------------------------------------------------- Formular
$b = '<h1>metaRechnung installieren <small class="muted">v' . h($appVersion) . '</small></h1>';
$b .= '<div class="card"><h2>Systemprüfung</h2><ul class="chk">';
foreach ($reqs as [$label, $ok, $hint, $fatal]) {
    $b .= '<li class="' . ($ok ? 'ok' : ($fatal ? 'no' : 'warn')) . '">' . ($ok ? '✔ ' : ($fatal ? '✘ ' : '⚠ ')) . h($label) . ($ok ? '' : ' – ' . h($hint)) . '</li>';
}
$b .= '</ul>' . ($blocked ? '<p class="no">Bitte zuerst die mit ✘ markierten Punkte beheben und die Seite neu laden.</p>' : '') . '</div>';

if ($errors) {
    $b .= '<div class="card"><p class="no"><b>Fehler:</b></p><ul>';
    foreach ($errors as $e) {
        $b .= '<li class="no">' . h($e) . '</li>';
    }
    $b .= '</ul>' . (!empty($failLog) ? '<pre class="log">' . h(implode("\n", $failLog)) . '</pre>' : '') . '</div>';
}

if (!$blocked) {
    $b .= '<form method="post" autocomplete="off"><input type="hidden" name="action" value="install">';
    $b .= '<fieldset><legend>Datenbank (MySQL/MariaDB)</legend>
      <p class="muted">Die Zugangsdaten findest du im Hosting-Panel unter „Datenbanken“. Bitte eine leere Datenbank verwenden.</p>
      <div class="grid2"><label>Server <input name="db_host" value="' . val('db_host', 'localhost') . '" required></label>
      <label>Port (optional) <input name="db_port" value="' . val('db_port') . '" inputmode="numeric"></label></div>
      <label>Datenbankname <input name="db_name" value="' . val('db_name') . '" required></label>
      <div class="grid2"><label>Benutzer <input name="db_user" value="' . val('db_user') . '" required></label>
      <label>Passwort <input name="db_pass" type="password"></label></div></fieldset>';
    $b .= '<fieldset><legend>Dein Login für die App</legend>
      <div class="grid3"><label>Benutzername <input name="admin_user" value="' . val('admin_user', 'admin') . '" required></label>
      <label>Passwort (min. 10 Zeichen) <input name="admin_pass" type="password" minlength="10" required></label>
      <label>Passwort wiederholen <input name="admin_pass2" type="password" minlength="10" required></label></div></fieldset>';
    $b .= '<fieldset><legend>Dein Unternehmen (Rechnungssteller)</legend>
      <div class="grid2"><label>Firma / Name * <input name="company_name" value="' . val('company_name') . '" required></label>
      <label>Inhaber / Ansprechpartner <input name="company_owner" value="' . val('company_owner') . '"></label></div>
      <label>Straße + Nr. * <input name="company_street" value="' . val('company_street') . '" required></label>
      <div class="grid3"><label>PLZ * <input name="company_zip" value="' . val('company_zip') . '" required></label>
      <label>Ort * <input name="company_city" value="' . val('company_city') . '" required></label>
      <label>Land (ISO) <input name="company_country" maxlength="2" value="' . val('company_country', 'DE') . '"></label></div>
      <div class="grid2"><label>E-Mail <input name="company_email" type="email" value="' . val('company_email') . '"></label>
      <label>Telefon <input name="company_phone" value="' . val('company_phone') . '"></label></div>
      <div class="grid2"><label>Steuernummer <input name="company_tax_number" value="' . val('company_tax_number') . '"></label>
      <label>USt-IdNr. <input name="company_vat_id" value="' . val('company_vat_id') . '"></label></div>
      <p class="muted">Für ZUGFeRD ist Steuernummer <i>oder</i> USt-IdNr. erforderlich.</p>
      <div class="grid3"><label>IBAN <input name="company_iban" value="' . val('company_iban') . '"></label>
      <label>BIC <input name="company_bic" value="' . val('company_bic') . '"></label>
      <label>Bank <input name="company_bank" value="' . val('company_bank') . '"></label></div></fieldset>';
    $b .= '<fieldset><legend>Rechnungen</legend>
      <div class="grid2"><label>Nummernpräfix <input name="invoice_prefix" value="' . val('invoice_prefix', 'RE') . '" maxlength="8"></label>
      <label>Zahlungsziel (Tage) <input name="payment_days" type="number" min="0" value="' . val('payment_days', '14') . '"></label></div>
      <label style="color:inherit"><input type="checkbox" name="kleinunternehmer" value="1" style="width:auto" ' . (isset($_POST['kleinunternehmer']) ? 'checked' : '') . '> Kleinunternehmer (§ 19 UStG, keine Umsatzsteuer)</label>
      <label style="color:inherit"><input type="checkbox" name="test_mode" value="1" style="width:auto" ' . (isset($_POST['test_mode']) ? 'checked' : '') . '> Testbetrieb (schaltet die Reset-Funktion frei – für den Echtbetrieb deaktiviert lassen)</label></fieldset>';
    $b .= '<fieldset><legend>E-Mail-Versand</legend>
      <p class="muted">SMTP-Server leer lassen = Mailversand über den Webspace (PHP <code>mail()</code>). SMTP ist zuverlässiger und wird empfohlen.</p>
      <div class="grid3"><label>SMTP-Server <input name="smtp_host" value="' . val('smtp_host') . '" placeholder="z. B. smtp.example.de"></label>
      <label>Port <input name="smtp_port" value="' . val('smtp_port', '587') . '"></label>
      <label>Verschlüsselung <select name="smtp_secure">';
    foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'keine'] as $k => $l) {
        $b .= '<option value="' . $k . '"' . (($_POST['smtp_secure'] ?? 'tls') === $k ? ' selected' : '') . '>' . $l . '</option>';
    }
    $b .= '</select></label></div>
      <div class="grid2"><label>SMTP-Benutzer <input name="smtp_user" value="' . val('smtp_user') . '"></label>
      <label>SMTP-Passwort <input name="smtp_pass" type="password"></label></div>
      <div class="grid3"><label>Absenderadresse <input name="mail_from" type="email" value="' . val('mail_from') . '" placeholder="leer = Firmen-E-Mail"></label>
      <label>Absendername <input name="mail_from_name" value="' . val('mail_from_name') . '" placeholder="leer = Firmenname"></label>
      <label>BCC an dich (optional) <input name="mail_bcc" type="email" value="' . val('mail_bcc') . '"></label></div></fieldset>';
    $b .= '<button class="btn primary">Installieren</button></form>';
}
page('Installation', $b);
