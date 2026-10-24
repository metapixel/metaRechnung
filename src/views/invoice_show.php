<?php
$t = $inv['totals'];
$kl = $inv['kl'];
$st = $inv['status'];
$problems = $inv['number'] ? \App\Einvoice::xrechnungProblems($inv) : [];
$statusUrl = $inv['public_token'] ? \App\Invoices::statusUrl($inv) : '';
$post = fn(string $action, string $label, string $cls = '', string $confirm = '') =>
    '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
    . '<input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="' . h($action) . '">'
    . '<button class="btn ' . $cls . '">' . h($label) . '</button></form>';
?>
<div class="row">
  <h1><?= $inv['number'] ? 'Rechnung ' . h($inv['number']) : 'Entwurf' ?></h1>
  <span class="badge <?= h($st) ?>"><?= ['draft' => 'Entwurf', 'issued' => 'ausgestellt', 'paid' => 'bezahlt'][$st] ?></span>
</div>
<p><b><?= h($inv['c_name']) ?></b><br><?= h($inv['c_street']) ?>, <?= h($inv['c_zip'] . ' ' . $inv['c_city']) ?><?= $inv['c_email'] ? '<br>' . h($inv['c_email']) : '' ?></p>
<p class="muted">Rechnungsdatum <?= h(fdate($inv['issue_date'])) ?> · Leistungsdatum <?= h(fdate($inv['delivery_date'] ?: $inv['issue_date'])) ?> · fällig <?= h(fdate($inv['due_date'])) ?>
<?= $inv['sent_at'] ? ' · gesendet ' . h(date('d.m.Y H:i', strtotime($inv['sent_at']))) : '' ?>
<?= $st === 'paid' && $inv['paid_at'] ? ' · bezahlt am ' . h(fdate($inv['paid_at'])) : '' ?></p>

<div class="scroll"><table>
  <tr><th scope="col">#</th><th scope="col">Beschreibung</th><th scope="col" class="r">Menge</th><th scope="col" class="r">Einzelpreis</th><?php if (!$kl): ?><th scope="col" class="r">MwSt</th><?php endif; ?><th scope="col" class="r">Summe</th></tr>
  <?php foreach ($inv['items'] as $it): ?>
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
<?php if ($inv['notes']): ?><p class="muted"><?= nl2br(h($inv['notes'])) ?></p><?php endif; ?>

<div class="actions">
<?php if ($st === 'draft'): ?>
  <a class="btn" href="<?= BASE ?>?p=invoice_edit&id=<?= (int)$inv['id'] ?>">Bearbeiten</a>
  <?= $post('issue', 'Ausstellen (Nummer vergeben + PDF erzeugen)', 'primary', 'Rechnung jetzt ausstellen? Danach ist sie nicht mehr änderbar.') ?>
  <?= $post('duplicate', 'Duplizieren') ?>
  <?= $post('delete', 'Löschen', 'danger', 'Entwurf wirklich löschen?') ?>
<?php else: ?>
  <a class="btn primary" href="<?= BASE ?>?p=pdf&id=<?= (int)$inv['id'] ?>" target="_blank">PDF (ZUGFeRD) öffnen</a>
  <?php if (!$problems): ?><a class="btn" href="<?= BASE ?>?p=xrechnung&id=<?= (int)$inv['id'] ?>">XRechnung (XML)</a><?php endif; ?>
  <?= $post('duplicate', 'Als neue Rechnung duplizieren') ?>
<?php endif; ?>
</div>
<?php if ($problems): ?><p class="hint">XRechnung nicht verfügbar – es fehlt: <?= h(implode(', ', $problems)) ?>.</p><?php endif; ?>

<?php if ($st === 'issued'): ?>
<form method="post" class="card">
  <h2>Zahlungseingang</h2>
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="paid">
  <div class="grid2"><label>Bezahlt am <input type="date" name="paid_at" value="<?= h(date('Y-m-d')) ?>" required></label></div>
  <button class="btn primary">Als bezahlt markieren</button>
  <span class="hint">Der Kunde sieht den Zahlungseingang sofort auf seiner Statusseite.</span>
</form>
<?php elseif ($st === 'paid'): ?>
<div class="actions"><?= $post('unpaid', 'Zahlung zurücknehmen (wieder „offen“)', '', 'Wirklich wieder auf offen setzen?') ?></div>
<?php endif; ?>

<?php if ($st !== 'draft'): ?>
<div class="card">
  <h2>Statusseite für den Kunden</h2>
  <input class="copy" readonly value="<?= h($statusUrl) ?>" data-select>
  <p class="hint">Dieser Link steht automatisch in der Rechnungsmail. Dort sieht der Kunde, ob die Zahlung eingegangen ist, und kann PDF und XRechnung herunterladen.</p>
  <a class="btn" href="<?= h($statusUrl) ?>" target="_blank">Statusseite öffnen</a>
</div>

<form method="post" class="card" enctype="multipart/form-data">
  <h2>Per E-Mail senden</h2>
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <input type="hidden" name="action" value="send">
  <label>An <input type="email" name="to" value="<?= h($inv['c_email']) ?>" required></label>
  <label>Nachricht
    <textarea name="message" rows="9"><?= h("Guten Tag" . ($inv['c_contact'] ? ' ' . $inv['c_contact'] : '') . ",\n\nanbei erhalten Sie die Rechnung " . $inv['number'] . " über " . money($t['gross']) . ".\nBitte überweisen Sie den Betrag bis zum " . fdate($inv['due_date']) . ".\n\nZahlungsstatus und Downloads (PDF, XRechnung):\n" . $statusUrl . "\n\nMit freundlichen Grüßen\n" . ($inv['co']['owner'] ?: $inv['co']['name'])) ?></textarea>
  </label>
  <label>Zusätzliche Anhänge (optional, z. B. Korrekturabzüge)
    <input type="file" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.zip,.docx,.xlsx,.pptx,.odt,.ods,.txt,.csv">
  </label>
  <p class="hint">Bis zu <?= \App\Attachments::MAX_FILES ?> Dateien, je höchstens <?= (int)(\App\Attachments::serverFileLimit() / 1048576) ?> MB, zusammen <?= \App\Attachments::MAX_TOTAL / 1048576 ?> MB.
    Erlaubt: <?= h(\App\Attachments::allowedExtensions()) ?>. Die Rechnungs-PDF hängt immer automatisch an; die Dateien werden nicht gespeichert.</p>
  <button class="btn primary">E-Mail senden</button>
</form>
<?php endif; ?>
