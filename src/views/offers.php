<div class="row">
  <h1>Angebote</h1>
  <a class="btn primary" href="<?= BASE ?>?p=offer_edit">+ Neues Angebot</a>
</div>
<?php if (!$rows): ?>
  <p class="muted">Noch keine Angebote. Ein Angebot lässt sich später mit einem Klick in eine Rechnung wandeln.</p>
<?php else: ?>
<div class="scroll"><table>
  <tr><th scope="col">Nr.</th><th scope="col">Kunde</th><th scope="col">Datum</th><th scope="col">Gültig bis</th><th scope="col" class="r">Brutto</th><th scope="col">Status</th></tr>
  <?php foreach ($rows as $r):
      $cls = ['draft' => 'draft', 'open' => 'issued', 'expired' => 'over', 'accepted' => 'paid', 'declined' => 'cancel'][$r['eff_status']] ?? 'draft'; ?>
  <tr class="rowlink" data-href="<?= BASE ?>?p=offer&amp;id=<?= (int)$r['id'] ?>">
    <td><a href="<?= BASE ?>?p=offer&id=<?= (int)$r['id'] ?>"><?= h($r['number'] ?: '—') ?></a></td>
    <td><?= h($r['c_name']) ?></td>
    <td><?= h(fdate($r['issue_date'])) ?></td>
    <td><?= h(fdate($r['valid_until'])) ?></td>
    <td class="r"><?= h(money($r['totals']['gross'])) ?></td>
    <td><span class="badge <?= h($cls) ?>"><?= h(\App\Offers::label($r['eff_status'])) ?></span><?= $r['invoice_id'] ? ' <span class="muted">→ Rechnung</span>' : '' ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
