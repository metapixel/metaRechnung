<?php
/** @var ?array $r @var array $customers @var array $articles */
$r = $r ?? [];
$kl = \App\Invoices::kleinunternehmer();
$items = $r['items'] ?? [];
if (!$items) { $items = [['description' => '', 'quantity' => 1, 'unit' => 'C62', 'unit_price' => '', 'vat_rate' => 19]]; }
$v = fn(string $k, string $d = '') => h($r[$k] ?? $d);
$sel = (int)($r['customer_id'] ?? 0);
$freq = $r['frequency'] ?? 'month';
$per = $r['period'] ?? 'previous';
$mode = $r['mode'] ?? 'draft';
$active = !$r || (int)$r['active'];
$pv = $r ? \App\Recurring::preview($r) : null;
?>
<h1><?= $r ? 'Vorlage bearbeiten' : 'Neue wiederkehrende Rechnung' ?></h1>
<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <p class="hint">Felder mit * sind Pflichtfelder.</p>
  <label>Bezeichnung * <span class="hint">Nur zur Übersicht, erscheint nicht auf der Rechnung.</span>
    <input name="name" maxlength="120" value="<?= $v('name') ?>" required autofocus placeholder="z. B. Hosting Muster GmbH">
  </label>
  <label>Kunde *
    <select name="customer_id" required>
      <option value="">– bitte wählen –</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $sel === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?><?= $c['email'] ? '' : ' (ohne E-Mail)' ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <div class="grid3">
    <label>Intervall *
      <select name="frequency"><?php foreach (\App\Recurring::FREQ as $k => $l): ?><option value="<?= h($k) ?>" <?= $freq === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    </label>
    <label>Nächste Erstellung *
      <input type="date" name="next_run" value="<?= h($r['next_run'] ?? date('Y-m-d', strtotime('+1 day'))) ?>" required>
    </label>
    <label>Letzte Erstellung am <span class="hint">(optional)</span>
      <input type="date" name="end_date" value="<?= h($r['end_date'] ?? '') ?>">
    </label>
  </div>
  <p class="hint">Der Tag der ersten Erstellung bleibt der feste Tag im Monat; am 31. beginnende Reihen fallen in kürzeren Monaten auf den letzten Tag.</p>

  <label>Abrechnungszeitraum für die Platzhalter *
    <select name="period"><?php foreach (\App\Recurring::PERIODS as $k => $l): ?><option value="<?= h($k) ?>" <?= $per === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  </label>
  <label>Ablauf bei der Erstellung *
    <select name="mode"><?php foreach (\App\Recurring::MODES as $k => $l): ?><option value="<?= h($k) ?>" <?= $mode === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  </label>
  <p class="hint">Empfehlung: zunächst „Als Entwurf anlegen“ – so sehen Sie jede Rechnung vor dem Ausstellen. Eine ausgestellte Rechnung ist unveränderlich.
    Der automatische Versand setzt eine gültige E-Mail-Adresse des Kunden, eingerichtete Mail-Zugangsdaten und die App-Adresse (<code>APP_URL</code>) voraus.</p>

  <?php $articles = $articles ?? []; require __DIR__ . '/_items.php'; ?>

  <details class="vars" open>
    <summary>Platzhalter für Positionen und Hinweistext</summary>
    <p class="hint">Beim Erstellen jeder Rechnung wird <code>{NAME}</code> durch den Wert des Abrechnungszeitraums ersetzt. Beispiel: „Hosting {ZEITRAUM}“ wird zu „Hosting Oktober 2026“.</p>
    <dl class="varlist">
      <?php foreach (\App\Recurring::VARS as $k => $d): ?><dt><code>{<?= h($k) ?>}</code></dt><dd><?= h($d) ?></dd><?php endforeach; ?>
    </dl>
  </details>

  <label>Hinweistext (erscheint auf jeder Rechnung)
    <textarea name="notes" rows="3" placeholder="z. B. Leistungszeitraum: {ZEITRAUM_VON} bis {ZEITRAUM_BIS}"><?= $v('notes') ?></textarea>
  </label>
  <label>E-Mail-Text <span class="hint">(nur bei automatischem Versand; zusätzlich: <?= h(implode(' ', array_map(static fn($k) => '{' . $k . '}', array_keys(\App\Recurring::MAIL_VARS)))) ?>)</span>
    <textarea name="mail_message" rows="7"><?= h($r['mail_message'] ?? \App\Recurring::DEFAULT_MAIL) ?></textarea>
  </label>
  <label class="check"><input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?>> Vorlage aktiv (zum Pausieren abwählen)</label>

  <?php if ($pv): ?>
  <div class="preview" role="note">
    <b>Vorschau der nächsten Rechnung</b> (Erstellung am <?= h(fdate($r['next_run'])) ?>, Zeitraum <?= h($pv['period']) ?>, <?= h($pv['vars']['ZEITRAUM_VON']) ?> – <?= h($pv['vars']['ZEITRAUM_BIS']) ?>):
    <ul><?php foreach ($pv['items'] as $it): ?><li><?= h($it['description']) ?></li><?php endforeach; ?></ul>
    <?php if ($pv['notes']): ?><p><?= nl2br(h($pv['notes'])) ?></p><?php endif; ?>
    <p class="hint">Die Vorschau zeigt den zuletzt gespeicherten Stand.</p>
  </div>
  <?php endif; ?>

  <button class="btn primary">Speichern</button>
  <a class="btn" href="<?= BASE ?>?p=recurring">Abbrechen</a>
</form>
<?php if ($r): ?>
<form method="post" style="margin-top:10px" data-confirm="Vorlage wirklich löschen? Bereits erzeugte Rechnungen bleiben erhalten.">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="delete">
  <button class="btn danger">Vorlage löschen</button>
</form>
<?php endif; ?>
