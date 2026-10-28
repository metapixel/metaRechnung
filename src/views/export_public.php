<?php if (!$exp): ?>
<div class="card narrow"><h1>Link nicht gültig</h1><p class="muted">Dieser Export wurde nicht gefunden oder ist abgelaufen. Bitte beim Rechnungssteller einen neuen Link anfordern.</p></div>
<?php else: $co = company(); ?>
<div class="card narrow">
  <p class="muted"><?= h($co['name']) ?></p>
  <h1>Rechnungsexport</h1>
  <table>
    <tr><td>Zeitraum</td><td class="r"><?= h($exp['label']) ?></td></tr>
    <tr><td>Belege</td><td class="r"><?= (int)$exp['invoice_count'] ?></td></tr>
    <tr><td>Größe</td><td class="r"><?= h(number_format($exp['size_bytes'] / 1048576, 1, ',', '.')) ?> MB</td></tr>
    <tr><td>Link gültig bis</td><td class="r"><?= h(date('d.m.Y', strtotime((string)$exp['expires_at']))) ?></td></tr>
  </table>
  <p>Das ZIP enthält die Rechnungen als PDF (ZUGFeRD)<?= $exp['with_xml'] ? ', XRechnung-XML' : '' ?> und eine CSV-Übersicht (Rechnungsausgangsbuch).</p>
  <p><a class="btn primary" href="?t=<?= h($t) ?>&amp;dl=1">ZIP herunterladen</a></p>
</div>
<?php endif; ?>
