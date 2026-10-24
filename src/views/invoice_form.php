<?php
$kl = \App\Invoices::kleinunternehmer();
$items = $inv['items'] ?? [];
if (!$items) { $items = [['description' => '', 'quantity' => 1, 'unit' => 'C62', 'unit_price' => '', 'vat_rate' => 19]]; }
$sel = (int)($inv['customer_id'] ?? $preselect ?? 0);
$issue = $inv['issue_date'] ?? date('Y-m-d');
$due = $inv['due_date'] ?? date('Y-m-d', strtotime('+' . (int)env('PAYMENT_DAYS', '14') . ' days'));
?>
<h1><?= $inv ? 'Entwurf bearbeiten' : 'Neue Rechnung' ?></h1>
<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <label>Kunde *
    <select name="customer_id" required>
      <option value="">– bitte wählen –</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $sel === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="muted"><a href="<?= BASE ?>?p=customer_edit&next=invoice_edit">+ Neuen Kunden anlegen</a></p>
  <div class="grid3">
    <label>Rechnungsdatum <input type="date" name="issue_date" value="<?= h($issue) ?>" required></label>
    <label>Leistungsdatum <input type="date" name="delivery_date" value="<?= h($inv['delivery_date'] ?? '') ?>"></label>
    <label>Fällig am <input type="date" name="due_date" value="<?= h($due) ?>" required></label>
  </div>

  <h2>Positionen</h2>
  <div class="scroll"><table id="items">
    <thead><tr><th>Beschreibung</th><th>Menge</th><th>Einheit</th><th>Einzelpreis (netto)</th><?php if (!$kl): ?><th>MwSt %</th><?php endif; ?><th class="r">Summe</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><input name="desc[]" value="<?= h($it['description']) ?>" placeholder="Leistung / Artikel"></td>
        <td><input name="qty[]" type="number" step="0.001" min="0" value="<?= h(rtrim(rtrim((string)$it['quantity'], '0'), '.') ?: 1) ?>" class="s"></td>
        <td><select name="unit[]" class="s"><?php foreach (UNITS as $code => $lbl): ?><option value="<?= h($code) ?>" <?= $it['unit'] === $code ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></td>
        <td><input name="price[]" type="number" step="0.01" value="<?= h($it['unit_price']) ?>" class="s"></td>
        <td <?= $kl ? 'hidden' : '' ?>><select name="vat[]" class="s">
          <?php foreach ([19, 7, 0] as $r): ?><option value="<?= $r ?>" <?= (float)$it['vat_rate'] === (float)$r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?>
        </select></td>
        <td class="r line">0,00 €</td>
        <td><button type="button" class="btn small rm" title="Zeile entfernen">×</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p><button type="button" class="btn" id="addrow">+ Position</button></p>
  <p class="totals">Netto <b id="s-net">0,00 €</b> · <?= $kl ? 'keine USt (§ 19 UStG)' : 'USt <b id="s-tax">0,00 €</b>' ?> · Brutto <b id="s-gross">0,00 €</b></p>

  <label>Hinweistext (erscheint auf der Rechnung)
    <textarea name="notes" rows="3"><?= h($inv['notes'] ?? '') ?></textarea>
  </label>
  <button class="btn primary">Entwurf speichern</button>
  <a class="btn" href="<?= BASE ?>">Abbrechen</a>
</form>
