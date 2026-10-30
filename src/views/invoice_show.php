<?php
$t = $inv['totals'];
$kl = $inv['kl'];
$st = $inv['status'];
$problems = $inv['number'] ? \App\Einvoice::xrechnungProblems($inv) : [];
$credit = \App\Invoices::isCredit($inv);
$cancelled = !empty($inv['cancelled_by']);
$tvars = \App\Templates::vars($inv);
$overdue = $st === 'issued' && !$credit && !$cancelled && $inv['due_date'] < date('Y-m-d');
$statusUrl = $inv['public_token'] ? \App\Invoices::statusUrl($inv) : '';
$post = fn(string $action, string $label, string $cls = '', string $confirm = '') =>
    '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
    . '<input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="' . h($action) . '">'
    . '<button class="btn ' . $cls . '">' . h($label) . '</button></form>';
?>
<div class="row">
  <h1><?= $inv['number'] ? ($credit ? 'Stornorechnung ' : 'Rechnung ') . h($inv['number']) : ($credit ? 'Entwurf Stornorechnung' : 'Entwurf') ?></h1>
  <?php if ($cancelled): ?><span class="badge cancel">storniert</span>
  <?php elseif ($credit && $st !== 'draft'): ?><span class="badge cancel">Storno</span>
  <?php else: ?><span class="badge <?= h($st) ?>"><?= ['draft' => 'Entwurf', 'issued' => 'ausgestellt', 'paid' => 'bezahlt'][$st] ?></span><?php endif; ?>
</div>
<?php if ($credit && $inv['cancels']): ?>
<p class="statusbox open">Storno zu Rechnung <a href="<?= BASE ?>?p=invoice&amp;id=<?= (int)$inv['cancels']['id'] ?>"><?= h($inv['cancels']['number']) ?></a> vom <?= h(fdate($inv['cancels']['issue_date'])) ?>.
  <?= $st === 'draft' ? 'Erst mit dem Ausstellen ist die Rechnung storniert. Für ein Teilstorno lassen sich die Mengen anpassen.' : '' ?></p>
<?php elseif ($cancelled): ?>
<p class="statusbox late">Diese Rechnung wurde storniert durch <a href="<?= BASE ?>?p=invoice&amp;id=<?= (int)$inv['cancelled_by']['id'] ?>"><?= h($inv['cancelled_by']['number']) ?></a>.
  <?= $st === 'paid' ? 'Die Zahlung wurde bereits verbucht – Erstattung bzw. Verrechnung bitte separat erledigen.' : '' ?></p>
<?php elseif ($inv['storno_draft']): ?>
<p class="statusbox open">Zu dieser Rechnung gibt es einen <a href="<?= BASE ?>?p=invoice&amp;id=<?= (int)$inv['storno_draft'] ?>">Stornoentwurf</a>.</p>
<?php endif; ?>
<?php if (!empty($inv['offer_id'])): $ofr = \App\Offers::find((int)$inv['offer_id']); if ($ofr): ?>
<p class="muted">Erstellt aus Angebot <a href="<?= BASE ?>?p=offer&amp;id=<?= (int)$ofr['id'] ?>"><?= h($ofr['number']) ?></a>.</p>
<?php endif; endif; ?>
<p><b><?= h($inv['c_name']) ?></b><br><?= h($inv['c_street']) ?>, <?= h($inv['c_zip'] . ' ' . $inv['c_city']) ?><?= $inv['c_email'] ? '<br>' . h($inv['c_email']) : '' ?></p>
<p class="muted">Rechnungsdatum <?= h(fdate($inv['issue_date'])) ?> · Leistungsdatum <?= h(fdate($inv['delivery_date'] ?: $inv['issue_date'])) ?><?= $credit ? '' : ' · fällig ' . h(fdate($inv['due_date'])) ?>
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
  <a class="btn" href="<?= BASE ?>?p=draft_preview&id=<?= (int)$inv['id'] ?>" target="_blank">PDF-Vorschau</a>
  <?= $post('issue', 'Ausstellen (Nummer vergeben + PDF erzeugen)', 'primary', 'Rechnung jetzt ausstellen? Danach ist sie nicht mehr änderbar.') ?>
  <?php if (!$credit): ?><?= $post('duplicate', 'Duplizieren') ?><?php endif; ?>
  <?= $post('delete', 'Löschen', 'danger', 'Entwurf wirklich löschen?') ?>
<?php else: ?>
  <a class="btn primary" href="<?= BASE ?>?p=pdf&id=<?= (int)$inv['id'] ?>" target="_blank">PDF (ZUGFeRD) öffnen</a>
  <?php if (!$problems): ?><a class="btn" href="<?= BASE ?>?p=xrechnung&id=<?= (int)$inv['id'] ?>">XRechnung (XML)</a><?php endif; ?>
  <?php if (!$credit): ?>
    <?= $post('duplicate', $cancelled ? 'Korrigierte Rechnung erstellen (Kopie als Entwurf)' : 'Als neue Rechnung duplizieren') ?>
    <?php if (!$cancelled && !$inv['storno_draft']): ?><?= $post('cancel', 'Stornorechnung erstellen', 'danger', 'Zu dieser Rechnung einen Stornoentwurf anlegen? Die Rechnung gilt erst als storniert, wenn du die Stornorechnung ausstellst.') ?><?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php if ($problems): ?><p class="hint">XRechnung nicht verfügbar – es fehlt: <?= h(implode(', ', $problems)) ?>.</p><?php endif; ?>

<?php if ($st === 'issued' && !$credit && !$cancelled): ?>
<form method="post" class="card">
  <h2>Zahlungseingang</h2>
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="paid">
  <div class="grid2"><label>Bezahlt am <input type="date" name="paid_at" value="<?= h(date('Y-m-d')) ?>" required></label></div>
  <button class="btn primary">Als bezahlt markieren</button>
  <span class="hint">Der Kunde sieht den Zahlungseingang sofort auf seiner Statusseite.</span>
</form>
<?php elseif ($st === 'paid' && !$cancelled): ?>
<div class="actions"><?= $post('unpaid', 'Zahlung zurücknehmen (wieder „offen“)', '', 'Wirklich wieder auf offen setzen?') ?></div>
<?php endif; ?>

<?php if ($overdue): ?>
<form method="post" class="card warnbox">
  <h2>Zahlungserinnerung</h2>
  <p>Die Rechnung war am <?= h(fdate($inv['due_date'])) ?> fällig.<?= (int)$inv['reminder_count'] > 0 ? ' Bisher ' . (int)$inv['reminder_count'] . ' Erinnerung(en), zuletzt am ' . h(date('d.m.Y', strtotime((string)$inv['reminded_at']))) . '.' : '' ?></p>
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="remind">
  <label>An <input type="email" name="to" value="<?= h($inv['c_email']) ?>" required></label>
  <label>Betreff <input name="subject" maxlength="190" value="<?= h(\App\Templates::render('mail_reminder_subject', $tvars)) ?>"></label>
  <label>Nachricht <textarea name="message" rows="10"><?= h(\App\Templates::render('mail_reminder_body', $tvars)) ?></textarea></label>
  <p class="hint">Die Rechnung hängt als PDF an. Texte: <a href="<?= BASE ?>?p=templates">Textvorlagen</a>.</p>
  <button class="btn primary">Erinnerung senden</button>
</form>
<?php endif; ?>

<?php if ($st !== 'draft'): ?>
<div class="card">
  <h2>Statusseite für den Kunden</h2>
  <input class="copy" aria-label="Link zur Statusseite" readonly value="<?= h($statusUrl) ?>" data-select>
  <p class="hint">Dieser Link steht automatisch in der Rechnungsmail. Dort sieht der Kunde, ob die Zahlung eingegangen ist, und kann PDF und XRechnung herunterladen.</p>
  <a class="btn" href="<?= h($statusUrl) ?>" target="_blank">Statusseite öffnen</a>
</div>

<form method="post" class="card" enctype="multipart/form-data">
  <h2>Per E-Mail senden</h2>
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <input type="hidden" name="action" value="send">
  <label>An <input type="email" name="to" value="<?= h($inv['c_email']) ?>" required></label>
  <label>Betreff <input name="subject" maxlength="190" value="<?= h($credit ? 'Stornorechnung ' . $inv['number'] . ' von ' . $inv['co']['name'] : \App\Templates::render('mail_invoice_subject', $tvars)) ?>"></label>
  <label>Nachricht
    <textarea name="message" rows="9"><?= $credit ? h("Guten Tag" . ($inv['c_contact'] ? ' ' . $inv['c_contact'] : '') . ",\n\nanbei erhalten Sie die Stornorechnung " . $inv['number'] . " zur Rechnung " . ($inv['cancels']['number'] ?? '') . " über " . money($t['gross']) . ".\nBereits geleistete Zahlungen werden erstattet bzw. verrechnet.\n\nDownloads (PDF, XRechnung):\n" . $statusUrl . "\n\nMit freundlichen Grüßen\n" . ($inv['co']['owner'] ?: $inv['co']['name'])) : h(\App\Templates::render('mail_invoice_body', $tvars)) ?></textarea>
  </label>
  <label>Zusätzliche Anhänge (optional, z. B. Korrekturabzüge)
    <input type="file" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.zip,.docx,.xlsx,.pptx,.odt,.ods,.txt,.csv">
  </label>
  <p class="hint">Bis zu <?= \App\Attachments::MAX_FILES ?> Dateien, je höchstens <?= (int)(\App\Attachments::serverFileLimit() / 1048576) ?> MB, zusammen <?= \App\Attachments::MAX_TOTAL / 1048576 ?> MB.
    Erlaubt: <?= h(\App\Attachments::allowedExtensions()) ?>. Die Rechnungs-PDF hängt immer automatisch an; die Dateien werden nicht gespeichert.</p>
  <button class="btn primary">E-Mail senden</button>
</form>
<?php endif; ?>
