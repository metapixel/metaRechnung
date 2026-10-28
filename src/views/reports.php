<?php
$mn = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
$mlong = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$pct = fn(?float $v) => $v === null ? '–' : number_format($v * 100, 0, ',', '.') . ' %';
$dd = fn(?float $v) => $v === null ? '–' : number_format($v, 1, ',', '.') . ' Tage';
$tot = ['count' => 0, 'net' => 0.0, 'gross' => 0.0];
foreach ($perMonth as $m) { $tot['count'] += $m['count']; $tot['net'] += $m['net']; $tot['gross'] += $m['gross']; }
$all = $pun[0]; $per = array_slice($pun, 1, null, true);
// Diagramm: Balken je Monat (Nettoumsatz); negative Monate (Storno überwiegt) nach unten
$vals = array_map(fn($m) => $m['net'], $perMonth);
$pmax = max(0.0, max($vals)); $nmax = abs(min(0.0, min($vals)));
$W = 720; $H = 190; $top = 12; $bot = 26; $ph = $H - $top - $bot;
$span = $pmax + $nmax; $sc = $span > 0 ? $ph / $span : 0; $base = $top + $pmax * $sc;
?>
<div class="row">
  <h1>Auswertung</h1>
  <?php if ($years): ?>
  <form method="get" class="actions">
    <input type="hidden" name="p" value="reports">
    <label class="sr" for="year">Jahr</label>
    <select id="year" name="year" style="width:auto;display:inline-block"><?php foreach ($years as $y): ?><option value="<?= (int)$y ?>" <?= $y === $year ? 'selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?></select>
    <button class="btn">Anzeigen</button>
  </form>
  <?php endif; ?>
</div>
<?php if (!$years): ?>
  <p class="muted">Noch keine ausgestellten Rechnungen – sobald es welche gibt, erscheint hier die Auswertung.</p>
<?php else: ?>
<p class="muted">Grundlage sind ausgestellte und bezahlte Rechnungen (keine Entwürfe). Der Umsatz zählt im Monat des Rechnungsdatums; Stornorechnungen mindern ihn im Monat ihres Rechnungsdatums.<?= $kl ? '' : ' Angaben netto, sofern nicht anders vermerkt.' ?></p>

<section class="card" aria-labelledby="h-month">
  <h2 id="h-month">Umsatz <?= (int)$year ?> je Monat</h2>
  <?php if ($span > 0): ?>
  <svg class="chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Säulendiagramm: Nettoumsatz je Monat <?= (int)$year ?>. Die Werte stehen in der Tabelle darunter.">
    <line x1="0" x2="<?= $W ?>" y1="<?= round($base, 1) ?>" y2="<?= round($base, 1) ?>"/>
    <?php foreach ($perMonth as $m => $v): $x = 6 + ($m - 1) * 59.5; $bh = $sc ? abs($v['net']) * $sc : 0; $neg = $v['net'] < 0; ?>
      <rect class="bar<?= $neg ? ' neg' : '' ?>" x="<?= round($x, 1) ?>" width="46" rx="4" y="<?= round($neg ? $base : $base - $bh, 1) ?>" height="<?= round(max($bh, $v['net'] != 0 ? 1.5 : 0), 1) ?>"><title><?= h($mlong[$m - 1] . ': ' . money($v['net'])) ?></title></rect>
      <text x="<?= round($x + 23, 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= h($mn[$m - 1]) ?></text>
    <?php endforeach; ?>
  </svg>
  <?php else: ?>
  <p class="muted">Im Jahr <?= (int)$year ?> gibt es keinen Umsatz.</p>
  <?php endif; ?>
  <div class="scroll"><table>
    <caption class="sr">Umsatz je Monat <?= (int)$year ?></caption>
    <tr><th scope="col">Monat</th><th scope="col" class="r">Rechnungen</th><th scope="col" class="r"><?= $kl ? 'Umsatz' : 'Netto' ?></th><?php if (!$kl): ?><th scope="col" class="r">Brutto</th><?php endif; ?></tr>
    <?php foreach ($perMonth as $m => $v): ?>
    <tr><th scope="row"><?= h($mlong[$m - 1]) ?></th><td class="r"><?= (int)$v['count'] ?></td><td class="r"><?= h(money($v['net'])) ?></td><?php if (!$kl): ?><td class="r"><?= h(money($v['gross'])) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <tr><th scope="row">Summe <?= (int)$year ?></th><td class="r"><b><?= (int)$tot['count'] ?></b></td><td class="r"><b><?= h(money(round($tot['net'], 2))) ?></b></td><?php if (!$kl): ?><td class="r"><b><?= h(money(round($tot['gross'], 2))) ?></b></td><?php endif; ?></tr>
  </table></div>
</section>

<section class="card" aria-labelledby="h-year">
  <h2 id="h-year">Umsatz je Jahr</h2>
  <div class="scroll"><table>
    <tr><th scope="col">Jahr</th><th scope="col" class="r">Rechnungen</th><th scope="col" class="r"><?= $kl ? 'Umsatz' : 'Netto' ?></th><?php if (!$kl): ?><th scope="col" class="r">Brutto</th><?php endif; ?><th scope="col" class="r">Davon noch offen</th></tr>
    <?php foreach ($perYear as $y => $v): ?>
    <tr><th scope="row"><a href="<?= BASE ?>?p=reports&amp;year=<?= (int)$y ?>"><?= (int)$y ?></a></th><td class="r"><?= (int)$v['count'] ?></td><td class="r"><?= h(money($v['net'])) ?></td><?php if (!$kl): ?><td class="r"><?= h(money($v['gross'])) ?></td><?php endif; ?><td class="r"><?= h(money($v['open'])) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted">„Offen“ ist der Bruttobetrag ausgestellter, noch nicht bezahlter Rechnungen (ohne stornierte).</p>
</section>

<section class="card" aria-labelledby="h-pay">
  <h2 id="h-pay">Zahlungsverhalten der Kunden</h2>
  <p>Insgesamt wurden <b><?= (int)$all['paid'] ?></b> Rechnungen bezahlt<?php if ($all['paid']): ?>, davon <b><?= h($pct($all['share'])) ?></b> pünktlich (bis zum Fälligkeitsdatum). Im Schnitt dauert es <b><?= h($dd($all['avg_days'])) ?></b> von der Rechnung bis zur Zahlung<?= $all['avg_late'] !== null ? '; verspätete Zahlungen kommen durchschnittlich ' . h($dd($all['avg_late'])) . ' nach Fälligkeit' : '' ?><?php endif; ?>.
  <?php if ($all['over_n']): ?> Aktuell <b><?= (int)$all['over_n'] ?></b> überfällig (<?= h(money($all['over_sum'])) ?>).<?php endif; ?></p>
  <div class="scroll"><table>
    <caption class="sr">Zahlungsverhalten je Kunde</caption>
    <tr><th scope="col">Kunde</th><th scope="col" class="r">Bezahlt</th><th scope="col" class="r">Pünktlich</th><th scope="col" class="r">Ø Zahlungsdauer</th><th scope="col" class="r">Ø Verzug</th><th scope="col" class="r">Überfällig</th><th scope="col">Einstufung</th></tr>
    <?php foreach ($per as $cid => $c): ?>
    <tr>
      <th scope="row"><a href="<?= BASE ?>?p=customer_edit&amp;id=<?= (int)$cid ?>"><?= h($c['name']) ?></a></th>
      <td class="r"><?= (int)$c['paid'] ?></td>
      <td class="r"><?= h($pct($c['share'])) ?></td>
      <td class="r"><?= h($dd($c['avg_days'])) ?></td>
      <td class="r"><?= h($dd($c['avg_late'])) ?></td>
      <td class="r"><?= $c['over_n'] ? (int)$c['over_n'] . ' (' . h(money($c['over_sum'])) . ')' : '–' ?></td>
      <td><span class="badge <?= $c['rating'] === 'pünktlich' ? 'paid' : ($c['rating'] === 'meist pünktlich' ? 'issued' : ($c['rating'] === 'oft verspätet' ? 'over' : '')) ?>"><?= h($c['rating']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted">Pünktlich heißt: Zahlungsdatum ≤ Fälligkeitsdatum. „Ø Verzug“ bezieht sich nur auf verspätete Zahlungen. Die Einstufung gibt es ab drei bezahlten Rechnungen (pünktlich ab 90 %, meist pünktlich ab 60 %). Das Zahlungsdatum ist das beim Markieren als „bezahlt“ erfasste Datum; stornierte Rechnungen und Stornorechnungen zählen nicht mit.</p>
</section>
<?php endif; ?>
