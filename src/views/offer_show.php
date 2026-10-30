<?php
$t = $off['totals'];
$kl = $off['kl'];
$st = $off['status'];
$eff = $off['eff_status'];
$cls = ['draft' => 'draft', 'open' => 'issued', 'expired' => 'over', 'accepted' => 'paid', 'declined' => 'cancel'][$eff] ?? 'draft';
$vars = \App\Templates::vars($off, 'offer');
$post = fn(string $action, string $label, string $cls = '', string $confirm = '') =>
    '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
    . '<input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="' . h($action) . '">'
    . '<button class="btn ' . $cls . '">' . h($label) . '</button></form>';
?>
<div class="row">
  <h1><?= $off['number'] ? 'Angebot ' . h($off['number']) : 'Angebotsentwurf' ?></h1>
  <span class="badge <?= h($cls) ?>"><?= h(\App\Offers::label($eff)) ?></span>
</div>
<?php if ($off['invoice']): ?>
<p class="statusbox open">Aus diesem Angebot wurde die <?= $off['invoice']['number'] ? 'Rechnung <a href="' . BASE . '?p=invoice&amp;id=' . (int)$off['invoice']['id'] . '">' . h($off['invoice']['number']) . '</a>' : '<a href="' . BASE . '?p=invoice&amp;id=' . (int)$off['invoice']['id'] . '">Rechnung (Entwurf)</a>' ?> erstellt.</p>
<?php elseif ($eff === 'expired'): ?>
<p class="statusbox late">Die Gültigkeit dieses Angebots ist am <?= h(fdate($off['valid_until'])) ?> abgelaufen.</p>
<?php endif; ?>
<p><b><?= h($off['c_name']) ?></b><br><?= h($off['c_street']) ?>, <?= h($off['c_zip'] . ' ' . $off['c_city']) ?><?= $off['c_email'] ? '<br>' . h($off['c_email']) : '' ?></p>
<p class="muted">Angebotsdatum <?= h(fdate($off['issue_date'])) ?> · gültig bis <?= h(fdate($off['valid_until'])) ?>
<?= $off['sent_at'] ? ' · gesendet ' . h(date('d.m.Y H:i', strtotime($off['sent_at']))) : '' ?>
<?= in_array($st, ['accepted', 'declined'], true) && $off['decided_at'] ? ' · ' . ($st === 'accepted' ? 'angenommen' : 'abgelehnt') . ' am ' . h(fdate($off['decided_at'])) : '' ?></p>

<div class="scroll"><table>
  <tr><th scope="col">#</th><th scope="col">Beschreibung</th><th scope="col" class="r">Menge</th><th scope="col" class="r">Einzelpreis</th><?php if (!$kl): ?><th scope="col" class="r">MwSt</th><?php endif; ?><th scope="col" class="r">Summe</th></tr>
  <?php foreach ($off['items'] as $it): ?>
  <tr>
    <td><?= (int)$it['pos'] ?></td><td><?= h($it['description']) ?><?= !empty($it['note']) ? '<br><span class="muted">' . nl2br(h($it['note'])) . '</span>' : '' ?></td>
    <td class="r"><?= h(rtrim(rtrim(number_format((float)$it['quantity'], 3, ',', ''), '0'), ',')) ?> <?= h(UNITS[$it['unit']] ?? '') ?></td>
    <td class="r"><?= h(money((float)$it['unit_price'])) ?></td>
    <?php if (!$kl): ?><td class="r"><?= h((float)$it['vat_rate']) ?> %</td><?php endif; ?>
    <td class="r"><?= h(money(round((float)$it['quantity'] * (float)$it['unit_price'], 2))) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<p class="totals">Netto <b><?= h(money($t['net'])) ?></b> · USt <b><?= h(money($t['tax'])) ?></b> · Brutto <b><?= h(money($t['gross'])) ?></b></p>
<?php if ($off['notes']): ?><p class="muted"><?= nl2br(h($off['notes'])) ?></p><?php endif; ?>

<div class="actions">
<?php if ($st === 'draft'): ?>
  <a class="btn" href="<?= BASE ?>?p=offer_edit&id=<?= (int)$off['id'] ?>">Bearbeiten</a>
  <a class="btn" href="<?= BASE ?>?p=offer_preview&id=<?= (int)$off['id'] ?>" target="_blank">PDF-Vorschau</a>
  <?= $post('issue', 'Ausstellen (Nummer vergeben + PDF erzeugen)', 'primary', 'Angebot jetzt ausstellen? Danach ist es nicht mehr änderbar.') ?>
  <?= $post('duplicate', 'Duplizieren') ?>
  <?= $post('delete', 'Löschen', 'danger', 'Entwurf wirklich löschen?') ?>
<?php else: ?>
  <a class="btn primary" href="<?= BASE ?>?p=offer_pdf&id=<?= (int)$off['id'] ?>" target="_blank">PDF öffnen</a>
  <a class="btn" href="<?= BASE ?>?p=offer_edit&id=<?= (int)$off['id'] ?>">Bearbeiten</a>
  <?php if (!$off['invoice'] && $st !== 'declined'): ?><?= $post('convert', 'In Rechnung wandeln', 'primary', 'Aus diesem Angebot einen Rechnungsentwurf anlegen? Das Angebot gilt danach als angenommen.') ?><?php endif; ?>
  <?= $post('duplicate', 'Als neues Angebot duplizieren') ?>
  <?php if ($st === 'open'): ?>
    <?= $post('accepted', 'Als angenommen markieren') ?>
    <?= $post('declined', 'Als abgelehnt markieren') ?>
  <?php elseif (!$off['invoice']): ?>
    <?= $post('reopen', 'Wieder auf „offen“ setzen') ?>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php if ($st !== 'draft'): ?>
<form method="post" class="card">
  <h2>Per E-Mail senden</h2>
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <input type="hidden" name="action" value="send">
  <label>An <input type="email" name="to" value="<?= h($off['c_email']) ?>" required></label>
  <label>Betreff <input name="subject" maxlength="190" value="<?= h(\App\Templates::render('mail_offer_subject', $vars)) ?>"></label>
  <label>Nachricht
    <textarea name="message" rows="10"><?= h(\App\Templates::render('mail_offer_body', $vars)) ?></textarea>
  </label>
  <p class="hint">Das Angebots-PDF hängt automatisch an. Die Texte kommen aus den <a href="<?= BASE ?>?p=templates">Textvorlagen</a>.</p>
  <button class="btn primary">E-Mail senden</button>
</form>
<?php endif; ?>
