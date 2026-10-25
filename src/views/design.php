<?php /** @var string $css */ ?>
<h1>Darstellung (eigenes CSS)</h1>
<p class="muted">Hier lässt sich das Aussehen der Oberfläche mit eigenem CSS anpassen – Farben, Rundungen, Schriftgröße. Die Änderung wirkt <b>sofort als Vorschau auf dieser Seite</b>;
  erst „Speichern“ übernimmt sie für alle Seiten. Die Rechnungs-PDF ist davon nicht betroffen.</p>
<form method="post" class="card" id="designform">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <label for="css">CSS
    <span class="hint">Tipp: Die meisten Farben und Abstände steuern die Variablen unter <code>:root</code> (über „Vorlage einfügen“ sichtbar).
      Mit der Tabulatortaste verlässt du den Editor.</span>
  </label>
  <textarea id="css" name="css" rows="18" data-css-editor aria-label="CSS-Editor" spellcheck="false" autocapitalize="off" style="font-family:ui-monospace,Consolas,monospace"><?= h($css) ?></textarea>
  <template id="css-template"><?= h(\App\Theme::TEMPLATE) ?></template>
  <div class="actions" style="margin-top:12px">
    <button class="btn primary" name="action" value="save">Speichern</button>
    <button class="btn" type="button" id="css-insert">Vorlage einfügen</button>
    <a class="btn" href="<?= BASE ?>?p=design">Änderungen verwerfen</a>
  </div>
  <p class="hint">Erlaubt sind bis zu <?= (int)(\App\Theme::MAX_BYTES / 1000) ?> KB. Nicht erlaubt: <code>@import</code>, das Zeichen <code>&lt;</code> und Skript-Konstrukte.
    Wegen der Sicherheitsrichtlinie der App lassen sich keine externen Schriften oder Bilder laden – lokale Dateien oder <code>data:</code>-URLs gehen.</p>
</form>
<?php if ($css !== ''): ?>
<form method="post" data-confirm="Eigenes CSS wirklich entfernen und das Standard-Design wiederherstellen?">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="reset">
  <button class="btn danger">Auf Standard-Design zurücksetzen</button>
</form>
<?php endif; ?>
<div class="card" style="margin-top:20px">
  <h2>Notausgang</h2>
  <p>Falls ein CSS die Seite unbenutzbar macht: Hänge <code>&amp;nocss=1</code> an die Adresse an, z. B. <code><?= h(BASE) ?>?p=design&amp;nocss=1</code>. Dann wird das eigene CSS ignoriert, und du kannst es korrigieren oder zurücksetzen.</p>
</div>
<script src="<?= h(asset('assets/css-editor.js')) ?>" defer></script>
