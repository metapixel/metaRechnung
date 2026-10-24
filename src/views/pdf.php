<?php /** @var array $inv @var array $co @var bool $kl */ $t = $inv['totals']; ?>
<style>
  body { font-family: dejavusanscondensed; font-size: 9.5pt; color: #111; }
  table { border-collapse: collapse; width: 100%; }
  th { text-align: left; border-bottom: 1px solid #333; padding: 4px 3px; font-size: 8.5pt; }
  td { padding: 4px 3px; border-bottom: 0.5px solid #ccc; vertical-align: top; }
  .r { text-align: right; }
  .small { font-size: 7.5pt; color: #555; }
  h1 { font-size: 15pt; margin: 22px 0 8px 0; }
  .plain td { border: 0; padding: 1px 3px; }
</style>

<?php if (empty($hideSender)): ?>
<div style="text-align:right">
  <b><?= h($co['name']) ?></b><br>
  <?= h($co['street']) ?><br><?= h($co['zip'] . ' ' . $co['city']) ?><br>
  <?= $co['phone'] ? h($co['phone']) . '<br>' : '' ?><?= h($co['email']) ?>
</div>
<?php endif; ?>

<div class="small" style="margin-top:24px"><u><?= h($co['name'] . ' · ' . $co['street'] . ' · ' . $co['zip'] . ' ' . $co['city']) ?></u></div>

<table class="plain" style="margin-top:4px">
<tr>
  <td style="width:55%">
    <b><?= h($inv['c_name']) ?></b><br>
    <?= $inv['c_contact'] ? h($inv['c_contact']) . '<br>' : '' ?>
    <?= h($inv['c_street']) ?><br>
    <?= h($inv['c_zip'] . ' ' . $inv['c_city']) ?><?= $inv['c_country'] !== 'DE' ? '<br>' . h($inv['c_country']) : '' ?>
    <?= $inv['c_vat_id'] ? '<br>USt-IdNr.: ' . h($inv['c_vat_id']) : '' ?>
  </td>
  <td>
    <table class="plain">
      <tr><td>Rechnungs-Nr.:</td><td class="r"><b><?= h($inv['number']) ?></b></td></tr>
      <tr><td>Rechnungsdatum:</td><td class="r"><?= h(fdate($inv['issue_date'])) ?></td></tr>
      <tr><td>Leistungsdatum:</td><td class="r"><?= h(fdate($inv['delivery_date'] ?: $inv['issue_date'])) ?></td></tr>
      <tr><td>Fällig am:</td><td class="r"><?= h(fdate($inv['due_date'])) ?></td></tr>
    </table>
  </td>
</tr>
</table>

<h1>Rechnung <?= h($inv['number']) ?></h1>

<table>
  <tr><th>Pos.</th><th>Beschreibung</th><th class="r">Menge</th><th class="r">Einzelpreis</th><?php if (!$kl): ?><th class="r">MwSt</th><?php endif; ?><th class="r">Summe (netto)</th></tr>
  <?php foreach ($inv['items'] as $it): ?>
  <tr>
    <td><?= (int)$it['pos'] ?></td>
    <td><?= h($it['description']) ?></td>
    <td class="r"><?= h(rtrim(rtrim(number_format((float)$it['quantity'], 3, ',', ''), '0'), ',')) ?> <?= h(UNITS[$it['unit']] ?? '') ?></td>
    <td class="r"><?= h(money((float)$it['unit_price'])) ?></td>
    <?php if (!$kl): ?><td class="r"><?= h(rtrim(rtrim(number_format((float)$it['vat_rate'], 2, ',', ''), '0'), ',')) ?> %</td><?php endif; ?>
    <td class="r"><?= h(money(round((float)$it['quantity'] * (float)$it['unit_price'], 2))) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<table align="right" class="plain" style="width:48%; margin-top:10px">
  <tr><td>Nettobetrag</td><td class="r"><?= h(money($t['net'])) ?></td></tr>
  <?php if (!$kl): foreach ($t['taxes'] as $x): ?>
  <tr><td>USt <?= h(rtrim(rtrim(number_format($x['rate'], 2, ',', ''), '0'), ',')) ?> % auf <?= h(money($x['basis'])) ?></td><td class="r"><?= h(money($x['tax'])) ?></td></tr>
  <?php endforeach; endif; ?>
  <tr><td style="border-top:1px solid #333"><b>Gesamtbetrag</b></td><td class="r" style="border-top:1px solid #333"><b><?= h(money($t['gross'])) ?></b></td></tr>
</table>
<div style="clear:both"></div>

<?php if ($kl): ?><p style="margin-top:16px">Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG.</p><?php endif; ?>
<?php if (!empty($inv['notes'])): ?><p style="margin-top:12px"><?= nl2br(h($inv['notes'])) ?></p><?php endif; ?>

<p style="margin-top:16px">Bitte überweisen Sie den Rechnungsbetrag bis zum <b><?= h(fdate($inv['due_date'])) ?></b> unter Angabe der Rechnungsnummer
auf das unten genannte Konto.</p>

<?php if (empty($hideFooter)): ?>
<div class="small" style="margin-top:28px; border-top:0.5px solid #999; padding-top:6px">
  <?= h($co['name']) ?><?= $co['owner'] ? ' · Inhaber: ' . h($co['owner']) : '' ?><br>
  <?= $co['tax_number'] ? 'Steuernr.: ' . h($co['tax_number']) . ' ' : '' ?><?= $co['vat_id'] ? 'USt-IdNr.: ' . h($co['vat_id']) : '' ?><br>
  <?= $co['bank'] ? h($co['bank']) . ' · ' : '' ?>IBAN: <?= h(trim(chunk_split($co['iban'], 4, ' '))) ?><?= $co['bic'] ? ' · BIC: ' . h($co['bic']) : '' ?>
</div>
<?php endif; ?>
