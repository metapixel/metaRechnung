<?php /** @var array $d @var list<string> $errors @var string $notice @var ?string $logoColor */
use App\PdfDesign as P;
$radio = function (string $name, array $opts, string $cur): void {
    foreach ($opts as $k => $label) {
        echo '<label class="opt"><input type="radio" name="' . h($name) . '" value="' . h((string)$k) . '"' . ((string)$k === $cur ? ' checked' : '') . '> ' . h((string)$label) . '</label>';
    }
};
$cr = P::contrast($d['accent'], '#ffffff');
$tl = fn(string $k): string => P::TEMPLATES[$k]['label'];
?>
<h1>PDF-Design</h1>
<p class="muted">So sehen die Rechnungs-PDFs aus. Änderungen gelten für <b>neu ausgestellte</b> Rechnungen und für Entwürfe; bereits ausgestellte Rechnungen bleiben unverändert (ihr Design wird beim Ausstellen festgehalten).
  Mit Briefpapier oder Logo lässt sich das Design kombinieren.</p>
<?php if ($errors): ?><div class="card warnbox"><?php foreach ($errors as $e): ?><p class="err" role="alert"><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>
<?php if ($notice): ?><div class="flash" role="status"><?= h($notice) ?></div><?php endif; ?>

<form method="post" class="card" id="pdfdesign">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">

  <h2>Vorlagen</h2>
  <p class="hint">Eine Vorlage füllt Schrift, Farbe und Tabellenstil vor – du kannst danach alles weiter anpassen. Die Vorlage wird erst mit „Speichern“ übernommen.</p>
  <div class="actions">
    <?php foreach (P::TEMPLATES as $k => $t): ?>
      <button class="btn" name="template" value="<?= h($k) ?>" title="<?= h($t['text']) ?>"><?= h($t['label']) ?></button>
    <?php endforeach; ?>
  </div>
  <ul class="hint" style="margin:8px 0 0 18px">
    <?php foreach (P::TEMPLATES as $t): ?><li><b><?= h($t['label']) ?>:</b> <?= h($t['text']) ?></li><?php endforeach; ?>
  </ul>

  <h2>Schrift</h2>
  <fieldset><legend>Schriftart</legend><?php $radio('font', P::FONTS, $d['font']); ?></fieldset>
  <fieldset><legend>Schriftgröße</legend><?php $radio('size', P::SIZE_LABELS, $d['size']); ?></fieldset>

  <h2>Farbe</h2>
  <label for="accent">Akzentfarbe (Linien, Tabellenkopf, Gesamtbetrag-Linie)</label>
  <div class="actions" style="align-items:center">
    <input type="color" id="accent" name="accent" value="<?= h($d['accent']) ?>" style="width:64px;height:40px;padding:2px">
    <code><?= h($d['accent']) ?></code>
    <?php if ($logoColor): ?><button type="button" class="btn" data-fill="accent" data-value="<?= h($logoColor) ?>">Farbe aus dem Logo übernehmen (<?= h($logoColor) ?>)</button><?php endif; ?>
  </div>
  <p class="hint">Kontrast der Farbe zu Weiß: <b><?= h(number_format($cr, 1, ',', '')) ?> : 1</b>
    <?= $cr >= 4.5 ? '– gut lesbar, auch als Titelfarbe.' : '– für Text auf Weiß (Titel) etwas hell; Linien und farbige Tabellenköpfe (der Text im Kopf wird automatisch hell oder dunkel gewählt) bleiben unkritisch.' ?>
    Die Farbe lässt sich im Farbfeld wählen; die Anzeige oben aktualisiert sich nach „Vorschau“ oder „Speichern“.</p>
  <label style="color:inherit"><input type="checkbox" name="title_accent" value="1" style="width:auto" <?= $d['title_accent'] ? 'checked' : '' ?>> Titel „Rechnung …“ in der Akzentfarbe</label>

  <h2>Tabelle und Aufbau</h2>
  <fieldset><legend>Tabellenstil der Positionen</legend><?php $radio('table', P::TABLES, $d['table']); ?></fieldset>
  <fieldset><legend>Absenderblock (ohne Briefpapier)</legend><?php $radio('sender', P::SENDERS, $d['sender']); ?></fieldset>
  <label style="color:inherit"><input type="checkbox" name="addrline" value="1" style="width:auto" <?= $d['addrline'] ? 'checked' : '' ?>> Kleine Absenderzeile über der Kundenanschrift</label>
  <label style="color:inherit"><input type="checkbox" name="footer" value="1" style="width:auto" <?= $d['footer'] ? 'checked' : '' ?>> Fußzeile mit Steuer- und Bankdaten</label>
  <label style="color:inherit"><input type="checkbox" name="pagenum" value="1" style="width:auto" <?= $d['pagenum'] ? 'checked' : '' ?>> Seitenzahl („Seite 1 von 2“) unten rechts</label>

  <h2>Zusatztexte</h2>
  <label for="closing">Schlusstext unter den Summen (optional, bis <?= P::MAX_CLOSING ?> Zeichen)</label>
  <textarea id="closing" name="closing" rows="3" maxlength="<?= P::MAX_CLOSING ?>"><?= h($d['closing']) ?></textarea>
  <label for="footer_text">Zusatzzeile in der Fußzeile (optional, bis <?= P::MAX_FOOTER ?> Zeichen, z. B. Registergericht oder Webseite)</label>
  <textarea id="footer_text" name="footer_text" rows="2" maxlength="<?= P::MAX_FOOTER ?>"><?= h($d['footer_text']) ?></textarea>

  <div class="actions" style="margin-top:14px">
    <button class="btn primary" name="action" value="save">Speichern</button>
    <button class="btn" name="action" value="preview" formaction="<?= BASE ?>?p=pdf_design" formtarget="_blank">Vorschau (PDF, neuer Tab)</button>
    <a class="btn" href="<?= BASE ?>?p=settings">Zurück zu den Einstellungen</a>
  </div>
  <p class="hint">Die Vorschau zeigt Beispieldaten mit deinen Firmendaten, ohne etwas zu speichern. Hinweis: Der GiroCode und die ZUGFeRD-Daten sind vom Design unabhängig.</p>
</form>

<form method="post" data-confirm="Das PDF-Design wirklich auf das klassische Standard-Aussehen zurücksetzen?">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="reset">
  <button class="btn danger">Auf Standard zurücksetzen</button>
</form>
