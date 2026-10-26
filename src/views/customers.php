<div class="row">
  <h1>Kunden</h1>
  <a class="btn primary" href="<?= BASE ?>?p=customer_edit">+ Neuer Kunde</a>
</div>
<div class="scroll"><table>
  <caption class="sr">Kundenliste</caption>
  <tr><th scope="col">Name</th><th scope="col">Ort</th><th scope="col">E-Mail</th></tr>
  <?php foreach ($customers as $c): ?>
  <tr class="rowlink" data-href="<?= BASE ?>?p=customer_edit&amp;id=<?= (int)$c['id'] ?>">
    <td><a href="<?= BASE ?>?p=customer_edit&amp;id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></td>
    <td><?= h($c['zip'] . ' ' . $c['city']) ?></td>
    <td><?= h($c['email']) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
