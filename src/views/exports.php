<?php
$adv = env('TAX_ADVISOR_EMAIL');
$advName = env('TAX_ADVISOR_NAME');
$mail = $adv !== '' ? $adv : env('EXPORT_EMAIL');
$expired = fn(array $r) => $r['expires_at'] < date('Y-m-d H:i:s');
$mb = fn($b) => number_format($b / 1048576, 1, ',', '.') . ' MB';
?>
<h1>Export für den Steuerberater</h1>
<p class="muted">Erstellt ein ZIP mit allen Rechnungen eines Zeitraums (PDF/ZUGFeRD, optional XRechnung-XML) und einer CSV-Übersicht. Das ZIP liegt geschützt auf dem Server (<code>storage/exports/</code>) und wird über einen geheimen, befristeten Link abgerufen, den du per E-Mail senden kannst. Nach Ablauf lässt sich der Link noch <?= \App\Exports::GRACE_DAYS ?> Tage lang verlängern, danach wird die Datei automatisch gelöscht.</p>
<?php if (!\App\Exports::available()): ?><div class="card warnbox"><p class="err" role="alert">Die PHP-Erweiterung „zip“ fehlt. Bitte im Hosting-Panel aktivieren.</p></div><?php endif; ?>
<?php if ($errors): ?><div class="card warnbox"><?php foreach ($errors as $e): ?><p class="err" role="alert"><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>

<form method="post" class="card" autocomplete="off" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="create">
  <h2>Neuen Export erstellen</h2>
  <div class="grid3">
    <label>Zeitraum (nach Rechnungsdatum)
      <select name="mode">
        <?php foreach (['prev_month' => 'Letzter Monat', 'prev_quarter' => 'Letztes Quartal', 'prev_year' => 'Letztes Jahr', 'year' => 'Laufendes Jahr (bis heute)', 'custom' => 'Eigener Zeitraum'] as $k => $l): ?>
          <option value="<?= h($k) ?>" <?= ($old['mode'] ?? 'prev_month') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Von (nur bei eigenem Zeitraum) <input type="date" name="from" value="<?= h($old['from'] ?? '') ?>"></label>
    <label>Bis <input type="date" name="to" value="<?= h($old['to'] ?? '') ?>"></label>
  </div>
  <div class="grid2">
    <label>Link gültig für
      <select name="days"><?php foreach (\App\Exports::VALID_DAYS as $d): ?><option value="<?= $d ?>" <?= (int)($old['days'] ?? 14) === $d ? 'selected' : '' ?>><?= $d ?> Tage</option><?php endforeach; ?></select>
    </label>
    <label style="align-self:end"><input type="checkbox" name="with_xml" value="1" <?= !$old || isset($old['with_xml']) ? 'checked' : '' ?>> XRechnung-XML zusätzlich beilegen</label>
  </div>
  <h3>Unterlagen beilegen <span class="hint">(optional)</span></h3>
  <p class="hint">Die Dateien kommen unverändert in den Ordner <code>zusatzunterlagen/</code> des Exports (ZIP-Dateien werden nicht entpackt). Je Datei höchstens <?= (int)(\App\Exports::docLimit() / 1048576) ?> MB (Server-Limit <?= h(ini_get('upload_max_filesize')) ?>).</p>
  <div class="grid3">
    <?php foreach (\App\Exports::DOC_SLOTS as $slot => [$lbl, , $exts]): ?>
    <label><?= h($lbl) ?> <span class="hint">(<?= h(strtoupper(implode(' oder ', $exts))) ?>)</span>
      <input type="file" name="doc_<?= h($slot) ?>" accept="<?= h(implode(',', array_map(fn($x) => '.' . $x, $exts))) ?>"></label>
    <?php endforeach; ?>
  </div>
  <h3>Link per E-Mail senden <span class="hint">(optional)</span></h3>
  <?php if ($adv !== '' || $advName !== ''): ?><p class="hint">Standard-Ansprechpartner: <b><?= h($advName ?: 'ohne Namen') ?></b><?= $adv !== '' ? ' &lt;' . h($adv) . '&gt;' : '' ?> – <a href="<?= BASE ?>?p=settings#steuerberater">ändern</a></p>
  <?php else: ?><p class="hint">Tipp: Hinterlege den Steuerberater einmalig in den <a href="<?= BASE ?>?p=settings#steuerberater">Einstellungen</a>, dann ist er hier vorausgefüllt.</p><?php endif; ?>
  <label>E-Mail-Adresse<?= $advName !== '' ? ' von ' . h($advName) : ' des Steuerberaters' ?> <input type="email" name="mail_to" maxlength="190" value="<?= h($old['mail_to'] ?? $mail) ?>" placeholder="steuerberater@example.com"></label>
  <label>Nachricht <span class="hint">Der Link und die Gültigkeit werden automatisch angehängt.</span>
    <textarea name="message" rows="6" maxlength="3000"><?= h($old['message'] ?? \App\Exports::defaultMessage()) ?></textarea>
  </label>
  <label><input type="checkbox" name="send" value="1" <?= !empty($old['send']) ? 'checked' : '' ?>> Nach dem Erstellen sofort per E-Mail senden</label>
  <button class="btn primary">Export erstellen</button>
</form>

<h2>Erstellte Exporte</h2>
<?php if (!$rows): ?>
  <p class="muted">Noch keine Exporte.</p>
<?php else: ?>
<div class="scroll"><table>
  <caption class="sr">Erstellte Exporte</caption>
  <tr><th scope="col">Zeitraum</th><th scope="col" class="r">Belege</th><th scope="col" class="r">Größe</th><th scope="col">Gültig bis</th><th scope="col" class="r">Downloads</th><th scope="col">Zugangslink</th><th scope="col">Aktionen</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <th scope="row"><?= h($r['label']) ?><br><span class="muted"><?= h(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?><?= $r['with_xml'] ? ' · mit XML' : '' ?></span></th>
    <td class="r"><?= (int)$r['invoice_count'] ?></td>
    <td class="r"><?= h($mb((int)$r['size_bytes'])) ?></td>
    <td><?= h(date('d.m.Y', strtotime((string)$r['expires_at']))) ?><?= $expired($r) ? ' <span class="badge over">abgelaufen</span><br><span class="muted">wird am ' . h(date('d.m.Y', strtotime((string)$r['expires_at'] . ' +' . \App\Exports::GRACE_DAYS . ' days'))) . ' gelöscht</span>' : '' ?></td>
    <td class="r"><?= (int)$r['downloads'] ?><?= $r['last_download'] ? '<br><span class="muted">zuletzt ' . h(date('d.m.Y H:i', strtotime((string)$r['last_download']))) . '</span>' : '' ?></td>
    <td>
      <?php if (!$expired($r)): ?>
        <label class="sr" for="lnk<?= (int)$r['id'] ?>">Zugangslink</label>
        <input id="lnk<?= (int)$r['id'] ?>" class="copy" readonly value="<?= h(\App\Exports::url($r)) ?>" data-select>
      <?php else: ?>–<?php endif; ?>
      <?php if ($r['sent_to']): ?><br><span class="muted">gesendet an <?= h($r['sent_to']) ?> (<?= h(date('d.m.Y', strtotime((string)$r['sent_at']))) ?>)</span><?php endif; ?>
    </td>
    <td>
      <?php if (!$expired($r)): ?>
      <details><summary>Per E-Mail senden</summary>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="send"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <label>An <input type="email" name="to" maxlength="190" required value="<?= h($r['sent_to'] ?: $mail) ?>"></label>
          <label>Nachricht <textarea name="message" rows="5" maxlength="3000"><?= h(\App\Exports::defaultMessage()) ?></textarea></label>
          <button class="btn">Senden</button>
        </form>
      </details>
      <?php endif; ?>
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="extend"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <label class="sr" for="ext<?= (int)$r['id'] ?>">Verlängern um</label>
        <select id="ext<?= (int)$r['id'] ?>" name="days"><?php foreach (\App\Exports::VALID_DAYS as $d): ?><option value="<?= $d ?>" <?= $d === 14 ? 'selected' : '' ?>><?= $d ?> Tage</option><?php endforeach; ?></select>
        <button class="btn"><?= $expired($r) ? 'Link reaktivieren' : 'Verlängern' ?></button>
      </form>
      <form method="post" data-confirm="Export wirklich löschen? Der Link funktioniert danach nicht mehr.">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn danger">Löschen</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
