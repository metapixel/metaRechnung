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

  <?php require __DIR__ . '/_items.php'; ?>

  <label>Hinweistext (erscheint auf der Rechnung)
    <textarea name="notes" rows="3"><?= h($inv['notes'] ?? '') ?></textarea>
  </label>
  <button class="btn primary">Entwurf speichern</button>
  <a class="btn" href="<?= BASE ?>">Abbrechen</a>
</form>
