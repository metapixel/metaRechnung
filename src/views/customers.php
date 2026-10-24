<div class="row">
  <h1>Kunden</h1>
  <a class="btn primary" href="<?= BASE ?>?p=customer_edit">+ Neuer Kunde</a>
</div>
<div class="scroll"><table>
  <tr><th>Name</th><th>Ort</th><th>E-Mail</th><th></th></tr>
  <?php foreach ($customers as $c): ?>
  <tr>
    <td><?= h($c['name']) ?></td>
    <td><?= h($c['zip'] . ' ' . $c['city']) ?></td>
    <td><?= h($c['email']) ?></td>
    <td class="r"><a href="<?= BASE ?>?p=customer_edit&id=<?= (int)$c['id'] ?>">Bearbeiten</a></td>
  </tr>
  <?php endforeach; ?>
</table></div>
