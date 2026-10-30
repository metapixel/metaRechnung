<?php /** @var array $inv @var array $co @var bool $kl */ $t = $inv['totals']; $dz = \App\PdfDesign::sanitize($inv['design'] ?? \App\PdfDesign::current()); $tv = \App\Templates::vars($inv, 'offer'); ?>
<style>
<?= \App\PdfDesign::css($dz) ?></style>

<?php $lg = $lh === null ? \App\Logo::info() : null; if ($lg): ?>
<div style="text-align:<?= h($lg['align']) ?>"><img src="<?= h($lg['path']) ?>" style="width:<?= h((string)$lg['width']) ?>mm" alt="<?= h($co['name']) ?>"></div>
<?php endif; ?>
<?php if (empty($hideSender)): ?>
<div style="text-align:<?= $dz['sender'] === 'left' ? 'left' : 'right' ?>">
  <b><?= h($co['name']) ?></b><br>
  <?= h($co['street']) ?><br><?= h($co['zip'] . ' ' . $co['city']) ?><br>
  <?= $co['phone'] ? h($co['phone']) . '<br>' : '' ?><?= h($co['email']) ?>
</div>
<?php endif; ?>

<?php if ($dz['addrline']): ?><div class="small" style="margin-top:24px"><u><?= h($co['name'] . ' · ' . $co['street'] . ' · ' . $co['zip'] . ' ' . $co['city']) ?></u></div><?php else: ?><div style="margin-top:24px"></div><?php endif; ?>

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
      <tr><td>Angebots-Nr.:</td><td class="r"><b><?= h($inv['number']) ?></b></td></tr>
      <tr><td>Angebotsdatum:</td><td class="r"><?= h(fdate($inv['issue_date'])) ?></td></tr>
      <tr><td>Gültig bis:</td><td class="r"><?= h(fdate($inv['valid_until'])) ?></td></tr>
    </table>
  </td>
</tr>
</table>

<h1>Angebot <?= h($inv['number']) ?></h1>
<?php $intro = trim(\App\Templates::fill((string)($inv['tpl']['pdf_offer_intro'] ?? ''), $tv)); if ($intro !== ''): ?><p><?= nl2br(h($intro)) ?></p><?php endif; ?>

<table class="items">
  <tr><th>Pos.</th><th>Beschreibung</th><th class="r">Menge</th><th class="r">Einzelpreis</th><?php if (!$kl): ?><th class="r">MwSt</th><?php endif; ?><th class="r">Summe (netto)</th></tr>
  <?php foreach ($inv['items'] as $ix => $it): ?>
  <tr<?= $dz['table'] === 'zebra' && $ix % 2 === 1 ? ' class="z"' : '' ?>>
    <td><?= (int)$it['pos'] ?></td>
    <td><?= h($it['description']) ?><?= !empty($it['note']) ? '<br><span class="small">' . nl2br(h($it['note'])) . '</span>' : '' ?></td>
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
  <tr><td class="sum"><b>Gesamtbetrag</b></td><td class="r sum"><b><?= h(money($t['gross'])) ?></b></td></tr>
</table>
<div style="clear:both"></div>

<?php if ($kl): ?><p style="margin-top:16px">Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG.</p><?php endif; ?>
<?php if (!empty($inv['notes'])): ?><p style="margin-top:12px"><?= nl2br(h($inv['notes'])) ?></p><?php endif; ?>
<?php $closing = trim(\App\Templates::fill((string)($inv['tpl']['pdf_offer_closing'] ?? ''), $tv)); if ($closing !== ''): ?><p style="margin-top:12px"><?= nl2br(h($closing)) ?></p><?php endif; ?>
<?php if ($dz['closing'] !== ''): ?><p style="margin-top:12px"><?= nl2br(h($dz['closing'])) ?></p><?php endif; ?>

<?php if (empty($hideFooter) && $dz['footer']): ?>
<div class="small" style="margin-top:28px; border-top:0.5px solid #999; padding-top:6px">
  <?= h($co['name']) ?><?= $co['owner'] ? ' · Inhaber: ' . h($co['owner']) : '' ?><br>
  <?= $co['tax_number'] ? 'Steuernr.: ' . h($co['tax_number']) . ' ' : '' ?><?= $co['vat_id'] ? 'USt-IdNr.: ' . h($co['vat_id']) : '' ?><br>
  <?= $co['bank'] ? h($co['bank']) . ' · ' : '' ?>IBAN: <?= h(trim(chunk_split($co['iban'], 4, ' '))) ?><?= $co['bic'] ? ' · BIC: ' . h($co['bic']) : '' ?>
  <?= $dz['footer_text'] !== '' ? '<br>' . nl2br(h($dz['footer_text'])) : '' ?>
</div>
<?php endif; ?>
