<?php $c = $c ?? []; $v = fn($k, $d = '') => h($c[$k] ?? $d); ?>
<h1><?= $c ? 'Kunde bearbeiten' : 'Neuer Kunde' ?></h1>
<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <label>Firma / Name * <input name="name" value="<?= $v('name') ?>" required></label>
  <label>Ansprechpartner <input name="contact" value="<?= $v('contact') ?>"></label>
  <label>E-Mail <input type="email" name="email" value="<?= $v('email') ?>"></label>
  <label>Straße + Nr. * <input name="street" value="<?= $v('street') ?>" required></label>
  <div class="grid2">
    <label>PLZ * <input name="zip" value="<?= $v('zip') ?>" required></label>
    <label>Ort * <input name="city" value="<?= $v('city') ?>" required></label>
  </div>
  <div class="grid2">
    <label>Land (ISO, z. B. DE) <input name="country" maxlength="2" value="<?= $v('country', 'DE') ?>" required></label>
    <label>USt-IdNr. <input name="vat_id" value="<?= $v('vat_id') ?>"></label>
  </div>
  <label>Käuferreferenz / Leitweg-ID (optional)
    <input name="buyer_reference" value="<?= $v('buyer_reference') ?>">
    <span class="hint">Nur nötig für öffentliche Auftraggeber (Leitweg-ID) oder wenn der Kunde eine Bestell-/Referenznummer verlangt. Wird in die XRechnung übernommen.</span>
  </label>
  <button class="btn primary">Speichern</button>
  <a class="btn" href="<?= BASE ?>?p=customers">Abbrechen</a>
</form>
