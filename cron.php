<?php
/*
 * metaRechnung – Copyright (C) 2026 Kai
 * Lizenz: GPL-2.0-or-later (siehe LICENSE)
 */
declare(strict_types=1);

/**
 * Erzeugt fällige wiederkehrende Rechnungen. Einmal täglich per Cronjob aufrufen:
 *   php /pfad/zur/installation/cron.php                       (Kommandozeile, kein Token nötig)
 *   wget -q -O /dev/null "https://…/cron.php?token=GEHEIMES_TOKEN"   (HTTP, Token siehe Menü „Wiederkehrend“)
 */
define('NO_SESSION', true);
require __DIR__ . '/src/bootstrap.php';

use App\Audit;
use App\RateLimit;
use App\Recurring;

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $rlKey = 'cron:' . RateLimit::ip();
    if (RateLimit::blocked($rlKey, 10, 900)) {
        http_response_code(429);
        header('Retry-After: 900');
        exit("Zu viele Fehlversuche.\n");
    }
    $given = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
    $real = env('CRON_TOKEN');
    if ($real === '' || !is_string($given) || $given === '' || !hash_equals($real, $given)) {
        RateLimit::fail($rlKey, 900);
        http_response_code(403);
        exit("Zugriff verweigert.\n");
    }
}

// Nur ein Lauf gleichzeitig
$lock = @fopen(__DIR__ . '/storage/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Ein anderer Lauf ist noch aktiv.\n");
}

Audit::setActor('cron');
try {
    $res = Recurring::runDue();
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('metaRechnung cron: ' . $e->getMessage());
    exit("Fehler beim Ausführen (Details im Server-Log).\n");
}
echo date('Y-m-d H:i:s'), ' – ', count($res) === 0 ? 'nichts fällig' : count($res) . ' Rechnung(en) verarbeitet', "\n";
foreach ($res as $r) {
    echo '- ', preg_replace('/[\x00-\x1F]/', ' ', $r['name']), ': ', $r['message'], $r['invoice_id'] ? ' (Rechnung #' . $r['invoice_id'] . ')' : '', "\n";
}
exit(array_filter($res, static fn($r) => $r['status'] === 'fehler') ? 2 : 0);
