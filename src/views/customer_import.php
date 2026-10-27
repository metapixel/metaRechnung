<?php /** @var string $step */
$step = $step ?? 'upload';
use App\CustomerImport as CI;
?>
<h1>Kunden importieren (CSV)</h1>
<?php if ($step === 'upload'): ?>
<p class="muted">Lies bestehende Kundendaten aus einer CSV-Datei ein (Export aus Excel, einer anderen Rechnungssoftware oder einem Adressbuch). Im nächsten Schritt siehst du eine Vorschau und kannst die Spalten zuordnen – importiert wird erst nach deiner Bestätigung.</p>
<form method="post" enctype="multipart/form-data" class="card">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="upload">
  <label for="csv">CSV-Datei (bis <?= (int)(CI::MAX_BYTES / 1048576) ?> MB, höchstens <?= CI::MAX_ROWS ?> Kunden)
    <input type="file" id="csv" name="csv" accept=".csv,.txt,text/csv,text/plain" required></label>
  <label for="delimiter">Trennzeichen
    <select id="delimiter" name="delimiter">
      <option value="auto">automatisch erkennen</option>
      <option value="semicolon">Semikolon ( ; ) – typisch für deutsches Excel</option>
      <option value="comma">Komma ( , )</option>
      <option value="tab">Tabulator</option>
      <option value="pipe">senkrechter Strich ( | )</option>
    </select></label>
  <p class="hint">Zeichensatz (UTF-8 oder Windows/Excel) wird automatisch erkannt. Pflichtangaben je Kunde sind <b>Name, Straße, PLZ und Ort</b> (wie im Kundenformular).
    Weitere Spalten: Ansprechpartner, E-Mail, Land (Standard DE), USt-IdNr., Leitweg-ID/Käuferreferenz.</p>
  <div class="actions">
    <button class="btn primary">Hochladen und Vorschau</button>
    <a class="btn" href="<?= BASE ?>?p=customer_import&amp;template=1">Vorlage herunterladen (CSV)</a>
    <a class="btn" href="<?= BASE ?>?p=customers">Abbrechen</a>
  </div>
</form>
<?php else:
    $labels = ['new' => 'neu', 'update' => 'aktualisieren', 'duplicate' => 'Duplikat', 'error' => 'fehlerhaft'];
    $cls = ['new' => 'paid', 'update' => 'issued', 'duplicate' => 'draft', 'error' => 'over'];
?>
<p class="muted">Datei: <b><?= h($orig) ?></b> · <?= count($rows) ?> Datenzeile(n), <?= (int)$cols ?> Spalte(n). Es wurde noch nichts importiert.</p>
<?php if ($errors): ?><div class="card warnbox"><?php foreach ($errors as $e): ?><p class="err" role="alert"><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>

<form method="post" class="card" id="importform">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <h2>1. Spalten zuordnen</h2>
  <label style="color:inherit"><input type="checkbox" name="has_header" value="1" style="width:auto" <?= $hasHeader ? 'checked' : '' ?>> Die erste Zeile enthält Spaltenüberschriften</label>
  <div class="scroll"><table>
    <caption class="sr">Zuordnung der CSV-Spalten zu Kundenfeldern</caption>
    <tr><th scope="col">Spalte in der Datei</th><th scope="col">Beispielwerte</th><th scope="col">Importieren als</th></tr>
    <?php for ($i = 0; $i < $cols; $i++): ?>
    <tr>
      <td><b><?= h($header[$i] ?? 'Spalte ' . ($i + 1)) ?></b></td>
      <td class="muted"><?= h(implode(' · ', array_filter(array_map(static fn($r) => mb_strimwidth((string)($r[$i] ?? ''), 0, 28, '…'), array_slice($rows, 0, 3)), static fn($x) => $x !== ''))) ?></td>
      <td><select name="map[<?= $i ?>]" aria-label="Importieren als – <?= h($header[$i] ?? 'Spalte ' . ($i + 1)) ?>">
        <option value="">– nicht importieren –</option>
        <?php foreach (CI::FIELDS as $f => [$lbl, , $req]): ?>
          <option value="<?= h($f) ?>" <?= ($map[$i] ?? '') === $f ? 'selected' : '' ?>><?= h($lbl . ($req ? ' *' : '')) ?></option>
        <?php endforeach; ?>
      </select></td>
    </tr>
    <?php endfor; ?>
  </table></div>

  <h2>2. Mit bereits vorhandenen Kunden</h2>
  <p class="hint">Ein Kunde gilt als vorhanden, wenn die E-Mail-Adresse oder Name + PLZ übereinstimmen.</p>
  <label style="color:inherit"><input type="radio" name="mode" value="skip" style="width:auto" <?= $mode === 'skip' ? 'checked' : '' ?>> Vorhandene überspringen (nichts verändern)</label>
  <label style="color:inherit"><input type="radio" name="mode" value="update" style="width:auto" <?= $mode === 'update' ? 'checked' : '' ?>> Vorhandene aktualisieren (nur Felder mit Inhalt überschreiben)</label>

  <div class="actions">
    <button class="btn" name="action" value="preview">Vorschau aktualisieren</button>
  </div>

<?php if ($plan): $c = $plan['counts']; ?>
  <h2>3. Vorschau</h2>
  <p class="totals" role="status"><b><?= (int)$c['new'] ?></b> neu · <b><?= (int)$c['update'] ?></b> zu aktualisieren · <b><?= (int)$c['duplicate'] ?></b> Duplikate (übersprungen) · <b><?= (int)$c['error'] ?></b> fehlerhaft (werden nicht importiert)</p>
  <div class="scroll"><table>
    <caption class="sr">Vorschau der ersten Zeilen</caption>
    <tr><th scope="col">Zeile</th><th scope="col">Name</th><th scope="col">Straße</th><th scope="col">PLZ Ort</th><th scope="col">E-Mail</th><th scope="col">Ergebnis</th></tr>
    <?php foreach (array_slice($plan['items'], 0, 20) as $it): $d = $it['data']; ?>
    <tr>
      <td><?= (int)$it['line'] ?></td><td><?= h((string)$d['name']) ?></td><td><?= h((string)$d['street']) ?></td>
      <td><?= h(trim((string)$d['zip'] . ' ' . (string)$d['city'] . ($d['country'] && $d['country'] !== 'DE' ? ' (' . $d['country'] . ')' : ''))) ?></td><td><?= h((string)$d['email']) ?></td>
      <td><span class="badge <?= h($cls[$it['status']]) ?>"><?= h($labels[$it['status']]) ?></span><?= $it['msgs'] ? '<br><span class="muted">' . h(implode('; ', $it['msgs'])) . '</span>' : '' ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php if (count($plan['items']) > 20): ?><p class="hint">Gezeigt werden die ersten 20 von <?= count($plan['items']) ?> Zeilen.</p><?php endif; ?>

  <?php $bad = array_values(array_filter($plan['items'], static fn($x) => $x['status'] === 'error')); if ($bad): ?>
  <h3>Fehlerhafte Zeilen</h3>
  <ul>
    <?php foreach (array_slice($bad, 0, 50) as $it): ?><li>Zeile <?= (int)$it['line'] ?>: <?= h(implode('; ', $it['msgs'])) ?></li><?php endforeach; ?>
  </ul>
  <?php if (count($bad) > 50): ?><p class="hint">… und <?= count($bad) - 50 ?> weitere.</p><?php endif; ?>
  <p class="hint">Fehlerhafte Zeilen werden nicht importiert. Du kannst die Datei korrigieren und erneut hochladen.</p>
  <?php endif; ?>
<?php endif; ?>

  <div class="actions">
    <?php if ($plan && ($plan['counts']['new'] + $plan['counts']['update']) > 0): ?>
      <button class="btn primary" name="action" value="import">Importieren</button>
    <?php endif; ?>
    <button class="btn" name="action" value="cancel" formnovalidate>Abbrechen</button>
  </div>
</form>
<?php endif; ?>
