<?php if (!$inv): ?>
<div class="card narrow"><h1>Link nicht gültig</h1><p class="muted">Diese Statusseite wurde nicht gefunden. Bitte den Link aus der Rechnungs-E-Mail verwenden.</p></div>
<?php else:
    $co = $inv['co']; $t = $inv['totals'];
    $paid = $inv['status'] === 'paid';
    $overdue = !$paid && $inv['due_date'] < date('Y-m-d');
?>
<div class="card">
  <p class="muted"><?= h($co['name']) ?></p>
  <h1>Rechnung <?= h($inv['number']) ?></h1>

  <?php if ($paid): ?>
    <p class="statusbox ok">✔ Zahlung eingegangen<?= $inv['paid_at'] ? ' am ' . h(fdate($inv['paid_at'])) : '' ?>. Vielen Dank!</p>
  <?php else: ?>
    <p class="statusbox <?= $overdue ? 'late' : 'open' ?>">
      <?= $overdue ? 'Die Zahlung ist noch nicht eingegangen (fällig war der ' . h(fdate($inv['due_date'])) . ').' : 'Die Zahlung ist noch nicht eingegangen (fällig am ' . h(fdate($inv['due_date'])) . ').' ?>
    </p>
  <?php endif; ?>

  <table>
    <tr><td>Rechnungsdatum</td><td class="r"><?= h(fdate($inv['issue_date'])) ?></td></tr>
    <tr><td>Rechnungsbetrag (brutto)</td><td class="r"><b><?= h(money($t['gross'])) ?></b></td></tr>
    <?php if (!$paid && $co['iban'] !== ''): ?>
    <tr><td>Empfänger</td><td class="r"><?= h($co['name']) ?></td></tr>
    <tr><td>IBAN</td><td class="r"><?= h(trim(chunk_split($co['iban'], 4, ' '))) ?></td></tr>
    <tr><td>Verwendungszweck</td><td class="r"><?= h($inv['number']) ?></td></tr>
    <?php endif; ?>
  </table>
  <?php $gc = (!$paid && \App\GiroCode::enabled()) ? \App\GiroCode::payload($inv) : null; if ($gc): ?>
  <figure class="girocode">
    <img src="<?= h(\App\GiroCode::svgDataUri($gc)) ?>" width="180" height="180" alt="GiroCode zum Scannen mit der Banking-App: Überweisung an <?= h($co['name']) ?>, Betrag <?= h(money((float)$t['gross'])) ?>, Verwendungszweck Rechnung <?= h($inv['number']) ?>">
    <figcaption>GiroCode – mit der Banking-App scannen, die Überweisung wird automatisch ausgefüllt.</figcaption>
  </figure>
  <?php endif; ?>

  <h2>Downloads</h2>
  <p>
    <a class="btn primary" href="?t=<?= h($token) ?>&dl=pdf">Rechnung als PDF (ZUGFeRD)</a>
    <?php if ($xrOk): ?><a class="btn" href="?t=<?= h($token) ?>&dl=xrechnung">XRechnung (XML)</a><?php endif; ?>
  </p>
  <p class="muted">Das PDF enthält die Rechnungsdaten zusätzlich maschinenlesbar (ZUGFeRD/Factur-X). Die XRechnung ist eine reine XML-Datei für die automatische Verarbeitung.</p>
</div>
<?php endif; ?>
