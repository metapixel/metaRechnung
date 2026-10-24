<div class="row">
  <h1>Rechnungen</h1>
  <a class="btn primary" href="<?= BASE ?>?p=invoice_edit">+ Neue Rechnung</a>
</div>
<?php if (!$rows): ?>
  <p class="muted">Noch keine Rechnungen. Lege zuerst einen <a href="<?= BASE ?>?p=customer_edit">Kunden</a> an.</p>
<?php else: ?>
<div class="scroll"><table>
  <tr><th>Nr.</th><th>Kunde</th><th>Datum</th><th>Fällig</th><th class="r">Brutto</th><th>Status</th></tr>
  <?php foreach ($rows as $r):
      $overdue = $r['status'] === 'issued' && $r['due_date'] < date('Y-m-d');
      $label = ['draft' => 'Entwurf', 'issued' => $overdue ? 'überfällig' : 'offen', 'paid' => 'bezahlt'][$r['status']]; ?>
  <tr>
    <td><a href="<?= BASE ?>?p=invoice&id=<?= (int)$r['id'] ?>"><?= h($r['number'] ?: '—') ?></a></td>
    <td><?= h($r['c_name']) ?></td>
    <td><?= h(fdate($r['issue_date'])) ?></td>
    <td><?= h(fdate($r['due_date'])) ?></td>
    <td class="r"><?= h(money($r['totals']['gross'])) ?></td>
    <td><span class="badge <?= $overdue ? 'over' : h($r['status']) ?>"><?= h($label) ?></span></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
