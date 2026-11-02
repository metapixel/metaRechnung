<?php
/** @var array $c @var list<array> $invoices @var list<array> $offers @var list<array> $recurring @var array $pay */
$pct = fn(?float $v) => $v === null ? '–' : number_format($v * 100, 0, ',', '.') . ' %';
$dd = fn(?float $v) => $v === null ? '–' : number_format($v, 1, ',', '.') . ' Tage';
$id = (int)$c['id'];
$freq = ['month' => 'monatlich', 'quarter' => 'vierteljährlich', 'year' => 'jährlich'];
?>
<div class="row">
  <h1><?= h($c['name']) ?></h1>
  <span class="actions">
    <a class="btn" href="<?= BASE ?>?p=customer_edit&amp;id=<?= $id ?>">Bearbeiten</a>
    <a class="btn" href="<?= BASE ?>?p=offer_edit&amp;customer=<?= $id ?>">+ Angebot</a>
    <a class="btn primary" href="<?= BASE ?>?p=invoice_edit&amp;customer=<?= $id ?>">+ Rechnung</a>
  </span>
</div>

<section class="card" aria-labelledby="h-master">
  <h2 id="h-master">Stammdaten</h2>
  <table class="kv">
    <?php foreach ([
        'Anschrift' => trim($c['street'] . ', ' . $c['zip'] . ' ' . $c['city'] . ($c['country'] && $c['country'] !== 'DE' ? ' (' . $c['country'] . ')' : ''), ', '),
        'Ansprechpartner' => (string)$c['contact'],
        'E-Mail' => (string)$c['email'],
        'USt-IdNr.' => (string)$c['vat_id'],
        'Käuferreferenz / Leitweg-ID' => (string)$c['buyer_reference'],
    ] as $k => $v): if ($v === '') { continue; } ?>
    <tr><th scope="row"><?= h($k) ?></th><td><?= h($v) ?></td></tr>
    <?php endforeach; ?>
  </table>
</section>

<section class="card" aria-labelledby="h-pay">
  <h2 id="h-pay">Zahlungsmoral</h2>
  <?php if ($pay['paid'] === 0 && $pay['open_n'] === 0): ?>
    <p class="muted">Noch keine bezahlten oder offenen Rechnungen – sobald es welche gibt, erscheint hier die Auswertung.</p>
  <?php else: ?>
  <p><span class="badge <?= $pay['rating'] === 'pünktlich' ? 'paid' : ($pay['rating'] === 'meist pünktlich' ? 'issued' : ($pay['rating'] === 'oft verspätet' ? 'over' : '')) ?>"><?= h($pay['rating']) ?></span>
    <?php if ($pay['paid']): ?> <b><?= (int)$pay['paid'] ?></b> Rechnung(en) bezahlt, davon <b><?= h($pct($pay['share'])) ?></b> pünktlich. Ø Zahlungsdauer <b><?= h($dd($pay['avg_days'])) ?></b><?= $pay['avg_late'] !== null ? ', verspätete Zahlungen im Schnitt <b>' . h($dd($pay['avg_late'])) . '</b> nach Fälligkeit (längster Verzug ' . (int)$pay['max_late'] . ' Tage)' : '' ?>.<?php endif; ?>
    <?php if ($pay['open_n']): ?> Offen: <b><?= (int)$pay['open_n'] ?></b> (<?= h(money($pay['open_sum'])) ?>)<?= $pay['over_n'] ? ', davon überfällig: <b>' . (int)$pay['over_n'] . '</b> (' . h(money($pay['over_sum'])) . ')' : '' ?>.<?php endif; ?></p>
  <p class="muted">Pünktlich heißt: Zahlungsdatum ≤ Fälligkeitsdatum. Die Einstufung gibt es ab drei bezahlten Rechnungen. Die Summen über alle Kunden stehen in der <a href="<?= BASE ?>?p=reports">Auswertung</a>.</p>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="h-inv">
  <h2 id="h-inv">Rechnungen (<?= count($invoices) ?>)</h2>
  <?php if (!$invoices): ?><p class="muted">Noch keine Rechnungen für diesen Kunden.</p><?php else: ?>
  <div class="scroll"><table>
    <caption class="sr">Rechnungen von <?= h($c['name']) ?></caption>
    <tr><th scope="col">Nr.</th><th scope="col">Datum</th><th scope="col">Fällig</th><th scope="col" class="r">Brutto</th><th scope="col">Status</th></tr>
    <?php foreach ($invoices as $r):
        $isCredit = $r['cancels_id'] !== null; $isCancelled = (int)$r['cancelled'] > 0;
        $overdue = $r['status'] === 'issued' && !$isCredit && !$isCancelled && $r['due_date'] < date('Y-m-d');
        $label = ['draft' => $isCredit ? 'Entwurf (Storno)' : 'Entwurf', 'issued' => $overdue ? 'überfällig' : 'offen', 'paid' => 'bezahlt'][$r['status']];
        $cls = $overdue ? 'over' : $r['status'];
        if ($isCancelled) { $label = 'storniert'; $cls = 'cancel'; } elseif ($isCredit && $r['status'] !== 'draft') { $label = 'Storno'; $cls = 'cancel'; } ?>
    <tr class="rowlink" data-href="<?= BASE ?>?p=invoice&amp;id=<?= (int)$r['id'] ?>">
      <td><a href="<?= BASE ?>?p=invoice&amp;id=<?= (int)$r['id'] ?>"><?= h($r['number'] ?: '—') ?></a></td>
      <td><?= h(fdate($r['issue_date'])) ?></td>
      <td><?= $isCredit ? '–' : h(fdate($r['due_date'])) ?></td>
      <td class="r"><?= h(money($r['totals']['gross'])) ?></td>
      <td><span class="badge <?= h($cls) ?>"><?= h($label) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="h-off">
  <h2 id="h-off">Angebote (<?= count($offers) ?>)</h2>
  <?php if (!$offers): ?><p class="muted">Noch keine Angebote für diesen Kunden.</p><?php else: ?>
  <div class="scroll"><table>
    <caption class="sr">Angebote für <?= h($c['name']) ?></caption>
    <tr><th scope="col">Nr.</th><th scope="col">Datum</th><th scope="col">Gültig bis</th><th scope="col" class="r">Brutto</th><th scope="col">Status</th></tr>
    <?php foreach ($offers as $o): ?>
    <tr class="rowlink" data-href="<?= BASE ?>?p=offer&amp;id=<?= (int)$o['id'] ?>">
      <td><a href="<?= BASE ?>?p=offer&amp;id=<?= (int)$o['id'] ?>"><?= h($o['number'] ?: '—') ?></a></td>
      <td><?= h(fdate($o['issue_date'])) ?></td>
      <td><?= h(fdate($o['valid_until'])) ?></td>
      <td class="r"><?= h(money($o['totals']['gross'])) ?></td>
      <td><span class="badge <?= h($o['eff_status'] === 'accepted' ? 'paid' : ($o['eff_status'] === 'open' ? 'issued' : ($o['eff_status'] === 'draft' ? 'draft' : 'cancel'))) ?>"><?= h(App\Offers::label($o['eff_status'])) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($recurring): ?>
<section class="card" aria-labelledby="h-rec">
  <h2 id="h-rec">Wiederkehrende Rechnungen (<?= count($recurring) ?>)</h2>
  <div class="scroll"><table>
    <caption class="sr">Wiederkehrende Vorlagen für <?= h($c['name']) ?></caption>
    <tr><th scope="col">Vorlage</th><th scope="col">Rhythmus</th><th scope="col">Nächster Lauf</th><th scope="col">Status</th></tr>
    <?php foreach ($recurring as $r): ?>
    <tr class="rowlink" data-href="<?= BASE ?>?p=recurring_edit&amp;id=<?= (int)$r['id'] ?>">
      <td><a href="<?= BASE ?>?p=recurring_edit&amp;id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
      <td><?= h($freq[$r['frequency']] ?? $r['frequency']) ?></td>
      <td><?= h(fdate($r['next_run'])) ?></td>
      <td><?= (int)$r['active'] ? 'aktiv' : 'pausiert' ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</section>
<?php endif; ?>
