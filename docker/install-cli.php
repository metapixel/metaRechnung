<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 *
 * Erst-Einrichtung im Container (ersetzt install.php): legt Tabellen an und schreibt die config.php
 * (Pfad: METARECHNUNG_CONFIG, im Container /data/config.php). Wird vom Entrypoint nur aufgerufen,
 * solange keine config.php existiert. Alles Weitere lässt sich danach in den Einstellungen ändern.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Migrations.php';

function e(string $k, string $d = ''): string
{
    $v = getenv($k);
    return is_string($v) && $v !== '' ? $v : $d;
}
function fail(string $msg): never
{
    fwrite(STDERR, "metaRechnung: $msg\n");
    exit(1);
}

$configFile = e('METARECHNUNG_CONFIG', $root . '/config.php');
if (is_file($configFile)) {
    exit(0);
}

$missing = [];
foreach (['ADMIN_USER', 'ADMIN_PASSWORD', 'COMPANY_NAME', 'COMPANY_STREET', 'COMPANY_ZIP', 'COMPANY_CITY', 'APP_URL', 'DB_HOST', 'DB_NAME', 'DB_USER'] as $k) {
    if (e($k) === '') {
        $missing[] = $k;
    }
}
if ($missing) {
    fail('Erst-Einrichtung nicht möglich, es fehlen Umgebungsvariablen: ' . implode(', ', $missing) . ' (siehe .env.example).');
}
if (strlen(e('ADMIN_PASSWORD')) < 10) {
    fail('ADMIN_PASSWORD braucht mindestens 10 Zeichen.');
}
if (e('COMPANY_EMAIL') !== '' && !filter_var(e('COMPANY_EMAIL'), FILTER_VALIDATE_EMAIL)) {
    fail('COMPANY_EMAIL ist ungültig.');
}
if (!preg_match('#^https?://#i', e('APP_URL'))) {
    fail('APP_URL muss mit http:// oder https:// beginnen.');
}

// Datenbank (der DB-Container braucht beim ersten Start einige Sekunden)
$host = e('DB_HOST');
$port = e('DB_PORT', '3306');
$dsn = 'mysql:host=' . $host . ';port=' . (int)$port . ';dbname=' . e('DB_NAME') . ';charset=utf8mb4';
$pdo = null;
$last = '';
for ($i = 0; $i < 60 && !$pdo; $i++) {
    try {
        $pdo = new PDO($dsn, e('DB_USER'), e('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $ex) {
        $last = $ex->getMessage();
        sleep(2);
    }
}
if (!$pdo) {
    fail('Datenbankverbindung fehlgeschlagen: ' . $last);
}

$existing = $pdo->query("SHOW TABLES LIKE 'invoices'")->fetchColumn();
if ($existing && (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() > 0) {
    fail('In der Datenbank gibt es bereits Rechnungen, aber keine config.php. Zur Sicherheit wird nichts überschrieben. '
        . 'Stelle die config.php im Volume wieder her oder verwende eine leere Datenbank.');
}
$pdo->exec((string)file_get_contents($root . '/src/schema.sql'));

$storage = $root . '/storage';
foreach (['', '/invoices', '/offers', '/tmp'] as $d) {
    if (!is_dir($storage . $d) && !mkdir($storage . $d, 0755, true)) {
        fail('Ordner storage' . $d . ' konnte nicht angelegt werden.');
    }
}
file_put_contents($storage . '/schema_version', (string)App\Migrations::VERSION);

$cfg = [
    'DB_HOST' => $host . ';port=' . (int)$port,
    'DB_NAME' => e('DB_NAME'), 'DB_USER' => e('DB_USER'), 'DB_PASS' => e('DB_PASS'),
    'APP_USER' => e('ADMIN_USER'),
    'APP_PASSWORD_HASH' => password_hash(e('ADMIN_PASSWORD'), PASSWORD_DEFAULT),
    'COMPANY_NAME' => e('COMPANY_NAME'), 'COMPANY_OWNER' => e('COMPANY_OWNER'),
    'COMPANY_STREET' => e('COMPANY_STREET'), 'COMPANY_ZIP' => e('COMPANY_ZIP'), 'COMPANY_CITY' => e('COMPANY_CITY'),
    'COMPANY_COUNTRY' => strtoupper(e('COMPANY_COUNTRY', 'DE')),
    'COMPANY_EMAIL' => e('COMPANY_EMAIL'), 'COMPANY_PHONE' => e('COMPANY_PHONE'),
    'COMPANY_TAX_NUMBER' => e('COMPANY_TAX_NUMBER'), 'COMPANY_VAT_ID' => e('COMPANY_VAT_ID'),
    'COMPANY_IBAN' => e('COMPANY_IBAN'), 'COMPANY_BIC' => e('COMPANY_BIC'), 'COMPANY_BANK' => e('COMPANY_BANK'),
    'INVOICE_PREFIX' => preg_replace('/[^A-Za-z0-9]/', '', e('INVOICE_PREFIX', 'RE')) ?: 'RE',
    'PAYMENT_DAYS' => (string)max(0, (int)e('PAYMENT_DAYS', '14')),
    'KLEINUNTERNEHMER' => e('KLEINUNTERNEHMER') === '1' ? '1' : '0',
    'TEST_MODE' => e('TEST_MODE') === '1' ? '1' : '0',
    'SMTP_HOST' => e('SMTP_HOST'), 'SMTP_PORT' => e('SMTP_PORT', '587'), 'SMTP_SECURE' => e('SMTP_SECURE', 'tls'),
    'SMTP_USER' => e('SMTP_USER'), 'SMTP_PASS' => e('SMTP_PASS'),
    'MAIL_FROM' => e('MAIL_FROM', e('COMPANY_EMAIL')),
    'MAIL_FROM_NAME' => e('MAIL_FROM_NAME', e('COMPANY_NAME')),
    'MAIL_BCC' => e('MAIL_BCC'),
    'APP_URL' => rtrim(e('APP_URL'), '/') . '/',
    'CRON_TOKEN' => bin2hex(random_bytes(20)),
];
$code = "<?php\n// Von docker/install-cli.php erzeugt. Enthält Zugangsdaten – nicht weitergeben.\nreturn " . var_export($cfg, true) . ";\n";
$tmp = $configFile . '.new';
if (file_put_contents($tmp, $code, LOCK_EX) === false || !rename($tmp, $configFile)) {
    fail('config.php konnte nicht nach ' . $configFile . ' geschrieben werden.');
}
chmod($configFile, 0640);
fwrite(STDOUT, "metaRechnung: Erst-Einrichtung abgeschlossen (Benutzer \"" . e('ADMIN_USER') . "\").\n");
