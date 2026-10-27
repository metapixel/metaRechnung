<div class="row">
  <h1>Rechnungen</h1>
  <a class="btn primary" href="<?= BASE ?>?p=invoice_edit">+ Neue Rechnung</a>
</div>
<?php if (!$rows): ?>
  <p class="muted">Noch keine Rechnungen. Lege zuerst einen <a href="<?= BASE ?>?p=customer_edit">Kunden</a> an.</p>
<?php else: ?>
<div class="scroll"><table>
  <tr><th scope="col">Nr.</th><th scope="col">Kunde</th><th scope="col">Datum</th><th scope="col">Fällig</th><th scope="col" class="r">Brutto</th><th scope="col">Status</th></tr>
  <?php foreach ($rows as $r):
      $isCredit = $r['cancels_id'] !== null; $isCancelled = (int)$r['cancelled'] > 0;
      $overdue = $r['status'] === 'issued' && !$isCredit && !$isCancelled && $r['due_date'] < date('Y-m-d');
      $label = ['draft' => $isCredit ? 'Entwurf (Storno)' : 'Entwurf', 'issued' => $overdue ? 'überfällig' : 'offen', 'paid' => 'bezahlt'][$r['status']];
      $cls = $overdue ? 'over' : $r['status'];
      if ($isCancelled) { $label = 'storniert'; $cls = 'cancel'; } elseif ($isCredit && $r['status'] !== 'draft') { $label = 'Storno'; $cls = 'cancel'; } ?>
  <tr class="rowlink" data-href="<?= BASE ?>?p=invoice&amp;id=<?= (int)$r['id'] ?>">
    <td><a href="<?= BASE ?>?p=invoice&id=<?= (int)$r['id'] ?>"><?= h($r['number'] ?: '—') ?></a></td>
    <td><?= h($r['c_name']) ?></td>
    <td><?= h(fdate($r['issue_date'])) ?></td>
    <td><?= $isCredit ? '–' : h(fdate($r['due_date'])) ?></td>
    <td class="r"><?= h(money($r['totals']['gross'])) ?></td>
    <td><span class="badge <?= h($cls) ?>"><?= h($label) ?></span></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
