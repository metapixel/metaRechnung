<div class="row">
  <h1>Artikel &amp; Leistungen</h1>
  <a class="btn primary" href="<?= BASE ?>?p=article_edit">+ Neuer Artikel</a>
</div>
<p class="muted">Wiederkehrende Leistungen einmal anlegen und in der Rechnung per Klick als Position einfügen.</p>
<?php if (!$articles): ?>
  <p class="muted">Noch keine Artikel. Lege den ersten mit „+ Neuer Artikel“ an.</p>
<?php else: ?>
<div class="scroll"><table>
  <tr><th scope="col">Bezeichnung</th><th scope="col">Einheit</th><th scope="col" class="r">Preis (netto)</th><?php if (!\App\Invoices::kleinunternehmer()): ?><th scope="col" class="r">MwSt</th><?php endif; ?></tr>
  <?php foreach ($articles as $a): ?>
  <tr class="rowlink" data-href="<?= BASE ?>?p=article_edit&amp;id=<?= (int)$a['id'] ?>">
    <td><a href="<?= BASE ?>?p=article_edit&amp;id=<?= (int)$a['id'] ?>"><?= h($a['description']) ?></a><?= !empty($a['note']) ? '<br><span class="muted">' . h(mb_strimwidth((string)$a['note'], 0, 90, '…')) . '</span>' : '' ?></td>
    <td><?= h(UNITS[$a['unit']] ?? '') ?></td>
    <td class="r"><?= h(money((float)$a['unit_price'])) ?></td>
    <?php if (!\App\Invoices::kleinunternehmer()): ?><td class="r"><?= h((float)$a['vat_rate']) ?> %</td><?php endif; ?>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
