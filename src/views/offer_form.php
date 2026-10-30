<?php
$kl = \App\Invoices::kleinunternehmer();
$inv = $off;   // _items.php erwartet $inv['items']
$items = $inv['items'] ?? [];
if (!$items) { $items = [['description' => '', 'quantity' => 1, 'unit' => 'C62', 'unit_price' => '', 'vat_rate' => 19]]; }
$sel = (int)($off['customer_id'] ?? $preselect ?? 0);
$issue = $off['issue_date'] ?? date('Y-m-d');
$valid = $off['valid_until'] ?? date('Y-m-d', strtotime('+' . (int)env('OFFER_VALID_DAYS', '30') . ' days'));
?>
<?php $isIssued = $off && $off['status'] !== 'draft'; ?>
<h1><?= $isIssued ? 'Angebot ' . h($off['number']) . ' bearbeiten' : ($off ? 'Angebotsentwurf bearbeiten' : 'Neues Angebot') ?></h1>
<?php if ($isIssued): ?><p class="statusbox open">Dieses Angebot ist bereits ausgestellt. Änderungen sind möglich: Die Nummer bleibt, das PDF wird neu erzeugt. Wurde das Angebot schon versendet, bitte dem Kunden die neue Fassung schicken.</p><?php endif; ?>
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
  <p class="muted"><a href="<?= BASE ?>?p=customer_edit&next=offer_edit">+ Neuen Kunden anlegen</a></p>
  <div class="grid2">
    <label>Angebotsdatum <input type="date" name="issue_date" value="<?= h($issue) ?>" required></label>
    <label>Gültig bis <input type="date" name="valid_until" value="<?= h($valid) ?>" required></label>
  </div>

  <?php require __DIR__ . '/_items.php'; ?>

  <label>Hinweistext (erscheint auf dem Angebot)
    <textarea name="notes" rows="3"><?= h($off['notes'] ?? '') ?></textarea>
  </label>
  <button class="btn primary"><?= $isIssued ? 'Änderungen speichern' : 'Entwurf speichern' ?></button>
  <button class="btn" name="after" value="preview">Speichern und PDF-Vorschau</button>
  <a class="btn" href="<?= BASE ?>?p=<?= $off ? 'offer&amp;id=' . (int)$off['id'] : 'offers' ?>">Abbrechen</a>
</form>
