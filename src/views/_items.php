<?php /** @var array $items @var array $articles @var bool $kl  Positionstabelle (Rechnungsentwurf und wiederkehrende Rechnung) */ ?>
  <h2>Positionen</h2>
  <div class="scroll"><table id="items"<?= !empty($credit) ? ' data-credit="1"' : '' ?>>
    <caption class="sr">Rechnungspositionen</caption>
    <thead><tr><th scope="col">Beschreibung</th><th scope="col">Menge</th><th scope="col">Einheit</th><th scope="col">Einzelpreis (netto)</th><?php if (!$kl): ?><th scope="col">MwSt %</th><?php endif; ?><th scope="col" class="r">Summe</th><th scope="col"><span class="sr">Aktionen</span></th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><input name="desc[]" aria-label="Beschreibung" value="<?= h($it['description']) ?>" placeholder="Leistung / Artikel">
          <textarea name="note[]" rows="1" maxlength="1000" class="note" aria-label="Notiz zur Position (optional)" placeholder="Notiz (optional)"><?= h($it['note'] ?? '') ?></textarea></td>
        <td><input name="qty[]" aria-label="Menge" type="number" step="0.001"<?= empty($credit) ? ' min="0"' : '' ?> value="<?= h(rtrim(rtrim((string)$it['quantity'], '0'), '.') ?: 1) ?>" class="s"></td>
        <td><select name="unit[]" aria-label="Einheit" class="s"><?php foreach (UNITS as $code => $lbl): ?><option value="<?= h($code) ?>" <?= $it['unit'] === $code ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></td>
        <td><input name="price[]" aria-label="Einzelpreis netto in Euro" type="number" step="0.01" value="<?= h($it['unit_price']) ?>" class="s"></td>
        <td <?= $kl ? 'hidden' : '' ?>><select name="vat[]" aria-label="MwSt-Satz in Prozent" class="s">
          <?php foreach ([19, 7, 0] as $vr): ?><option value="<?= $vr ?>" <?= (float)$it['vat_rate'] === (float)$vr ? 'selected' : '' ?>><?= $vr ?></option><?php endforeach; ?>
        </select></td>
        <td class="r line">0,00 €</td>
        <td><button type="button" class="btn small rm" title="Zeile entfernen" aria-label="Zeile entfernen"><span aria-hidden="true">×</span></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="addbar">
    <button type="button" class="btn" id="addrow">+ Position</button>
    <?php if (!empty($articles)): ?>
    <select id="addarticle" aria-label="Artikel einfügen">
      <option value="">Artikel einfügen …</option>
      <?php foreach ($articles as $ar): ?>
      <option value="<?= (int)$ar['id'] ?>" data-desc="<?= h($ar['description']) ?>" data-note="<?= h($ar['note'] ?? '') ?>" data-unit="<?= h($ar['unit']) ?>" data-price="<?= h(number_format((float)$ar['unit_price'], 2, '.', '')) ?>" data-vat="<?= h((float)$ar['vat_rate']) ?>"><?= h(mb_strimwidth($ar['description'], 0, 70, '…')) ?> – <?= h(money((float)$ar['unit_price'])) ?></option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <a href="<?= BASE ?>?p=article_edit" class="muted">Artikel anlegen, um sie hier einzufügen</a>
    <?php endif; ?>
  </div>
  <p class="totals" aria-live="polite" aria-atomic="true">Netto <b id="s-net">0,00 €</b> · <?= $kl ? 'keine USt (§ 19 UStG)' : 'USt <b id="s-tax">0,00 €</b>' ?> · Brutto <b id="s-gross">0,00 €</b></p>
