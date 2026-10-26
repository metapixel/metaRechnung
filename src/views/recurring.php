<?php
/** @var array $rows */
$cronUrl = env('CRON_TOKEN') !== '' ? app_url() . 'cron.php?token=' . env('CRON_TOKEN') : '';
$root = dirname(__DIR__, 2);
?>
<div class="row">
  <h1>Wiederkehrende Rechnungen</h1>
  <a class="btn primary" href="<?= BASE ?>?p=recurring_edit">+ Neue Vorlage</a>
</div>
<p class="muted">Vorlagen für regelmäßige Leistungen (Wartung, Hosting, Abos …). Zum eingestellten Termin legt ein Cronjob automatisch eine Rechnung an –
  als Entwurf oder gleich ausgestellt und versendet. In Positionen und Hinweistext lassen sich Platzhalter wie <code>{ZEITRAUM}</code> verwenden.</p>

<?php if (!$rows): ?>
  <p class="muted">Noch keine Vorlagen. Lege die erste mit „+ Neue Vorlage“ an.</p>
<?php else: ?>
<div class="scroll"><table>
  <caption class="sr">Wiederkehrende Rechnungen</caption>
  <tr><th scope="col">Bezeichnung</th><th scope="col">Kunde</th><th scope="col">Intervall</th><th scope="col">Nächste Erstellung</th><th scope="col">Ablauf</th><th scope="col">Status</th></tr>
  <?php foreach ($rows as $r):
      $done = !(int)$r['active'] && $r['end_date'] !== null && $r['next_run'] > $r['end_date'];
      $st = (int)$r['active'] ? 'aktiv' : ($done ? 'beendet' : 'pausiert'); ?>
  <tr class="rowlink" data-href="<?= BASE ?>?p=recurring_edit&amp;id=<?= (int)$r['id'] ?>">
    <td><a href="<?= BASE ?>?p=recurring_edit&amp;id=<?= (int)$r['id'] ?>"><b><?= h($r['name']) ?></b></a></td>
    <td><?= h($r['c_name']) ?></td>
    <td><?= h(\App\Recurring::FREQ[$r['frequency']]) ?></td>
    <td><?= (int)$r['active'] ? h(fdate($r['next_run'])) : '–' ?><?= (int)$r['active'] && $r['next_run'] <= date('Y-m-d') ? ' <span class="badge over">fällig</span>' : '' ?></td>
    <td><?= ['draft' => 'Entwurf', 'issue' => 'Ausstellen', 'send' => 'Ausstellen + Mail'][$r['mode']] ?></td>
    <td><span class="badge <?= $st === 'aktiv' ? 'paid' : 'draft' ?>"><?= h($st) ?></span>
      <?php if ($r['run_count']): ?><br><span class="muted"><?= (int)$r['run_count'] ?>× erzeugt<?= $r['last_run'] ? ', zuletzt ' . h(fdate($r['last_run'])) : '' ?><?= $r['last_invoice_id'] ? ' (<a href="' . BASE . '?p=invoice&amp;id=' . (int)$r['last_invoice_id'] . '">Rechnung</a>)' : '' ?></span><?php endif; ?>
      <?php if ($r['last_error']): ?><br><span class="err" role="alert"><?= h($r['last_error']) ?></span><?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<form method="post" class="actions" style="margin-top:12px">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="run_due">
  <button class="btn" data-confirm="Alle jetzt fälligen Vorlagen auslösen?">Fällige Rechnungen jetzt erzeugen</button>
</form>
<?php endif; ?>

<div class="card" style="margin-top:20px">
  <h2>Cronjob einrichten</h2>
  <p class="hint">Der Cronjob soll <b>einmal täglich</b> laufen (z. B. nachts um 3 Uhr). Er legt nur Rechnungen an, deren Termin erreicht ist; ein Zeitraum wird nie doppelt abgerechnet, auch nicht bei mehrfachem Aufruf. Wurden Läufe versäumt, werden sie nachgeholt.</p>
  <p><b>Variante 1 – Kommandozeile</b> (empfohlen, sofern der Webspace PHP-Cronjobs bietet):</p>
  <p><input class="copy" readonly data-select aria-label="Cronjob-Befehl für die Kommandozeile" value="<?= h('php ' . $root . '/cron.php') ?>"></p>
  <p><b>Variante 2 – Aufruf per URL</b> (z. B. über „wget“ oder „curl“ bzw. einen externen Cron-Dienst):</p>
  <?php if ($cronUrl): ?>
    <p><input class="copy" readonly data-select aria-label="Cronjob-URL mit geheimem Token" value="<?= h($cronUrl) ?>"></p>
    <p class="hint">Die URL enthält ein geheimes Token – nicht weitergeben. Falsche Tokens werden gezählt und nach 10 Versuchen für 15 Minuten gesperrt.</p>
    <form method="post" class="actions">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
      <button class="btn" name="action" value="cron_token" data-confirm="Neues Token erzeugen? Die bisherige URL funktioniert dann nicht mehr.">Neues Token erzeugen</button>
      <button class="btn danger" name="action" value="cron_token_off" data-confirm="Aufruf per URL deaktivieren?">URL-Aufruf deaktivieren</button>
    </form>
  <?php else: ?>
    <p class="muted">Der Aufruf per URL ist deaktiviert.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="btn" name="action" value="cron_token">URL-Aufruf aktivieren (Token erzeugen)</button></form>
  <?php endif; ?>
</div>
