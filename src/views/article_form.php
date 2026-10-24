<?php
$a = $a ?? [];
$kl = \App\Invoices::kleinunternehmer();
$val = fn(string $k, string $d = '') => h($a[$k] ?? $d);
$unit = $a['unit'] ?? 'C62';
$vat = (float)($a['vat_rate'] ?? 19);
?>
<h1><?= $a ? 'Artikel bearbeiten' : 'Neuer Artikel' ?></h1>
<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <label>Bezeichnung * <span class="hint">Dieser Text erscheint als Position auf der Rechnung.</span>
    <input name="description" maxlength="500" value="<?= $val('description') ?>" required autofocus>
  </label>
  <div class="grid3">
    <label>Einheit
      <select name="unit"><?php foreach (UNITS as $code => $lbl): ?><option value="<?= h($code) ?>" <?= $unit === $code ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select>
    </label>
    <label>Einzelpreis (netto) in €
      <input name="unit_price" inputmode="decimal" value="<?= $a ? h(number_format((float)$a['unit_price'], 2, '.', '')) : '' ?>" placeholder="0.00" required>
    </label>
    <?php if ($kl): ?>
      <input type="hidden" name="vat_rate" value="<?= h($vat) ?>">
    <?php else: ?>
    <label>MwSt %
      <select name="vat_rate"><?php foreach ([19, 7, 0] as $r): ?><option value="<?= $r ?>" <?= $vat === (float)$r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select>
    </label>
    <?php endif; ?>
  </div>
  <button class="btn primary">Speichern</button>
  <a class="btn" href="<?= BASE ?>?p=articles">Abbrechen</a>
</form>
<?php if ($a): ?>
<form method="post" data-confirm="Artikel wirklich löschen? Bereits erstellte Rechnungen bleiben unverändert.">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="delete">
  <button class="btn danger">Artikel löschen</button>
</form>
<?php endif; ?>
