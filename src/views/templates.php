<?php
$val = fn(string $k) => $old[$k] ?? \App\Templates::get($k);
$groups = ['mail' => 'E-Mail-Texte', 'pdf' => 'Texte auf dem PDF'];
?>
<h1>Textvorlagen</h1>
<?php if ($errors): ?><div class="card warnbox"><?php foreach ($errors as $e): ?><p class="err" role="alert"><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>
<?php if (!\App\Config::writable()): ?><div class="card warnbox"><p class="err" role="alert">config.php ist nicht beschreibbar – Änderungen können nicht gespeichert werden.</p></div><?php endif; ?>

<div class="card">
  <h2>Platzhalter</h2>
  <p class="hint">Mit geschweiften Klammern in jedem Text verwendbar, Groß-/Kleinschreibung egal. Nicht zutreffende Werte (z. B. <code>{faellig}</code> in einem Angebot) bleiben leer.</p>
  <div class="scroll"><table>
    <caption class="sr">Verfügbare Platzhalter</caption>
    <tr><th scope="col">Platzhalter</th><th scope="col">Wird ersetzt durch</th></tr>
    <?php foreach (\App\Templates::VARS as $k => $d): ?>
    <tr><td><code>{<?= h(strtolower($k)) ?>}</code></td><td><?= h($d) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</div>

<form method="post" class="card" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <?php foreach ($groups as $g => $gl): ?>
  <h2><?= h($gl) ?></h2>
  <?php if ($g === 'pdf'): ?><p class="hint">Gilt für neu ausgestellte Rechnungen und Angebote und für Entwürfe. Bereits ausgestellte PDFs bleiben unverändert.</p>
  <?php else: ?><p class="hint">Vorbelegung der Nachricht beim Versand; vor dem Senden lässt sie sich noch anpassen. Leere Felder fallen auf den Standardtext zurück.</p><?php endif; ?>
  <?php foreach (\App\Templates::DEFS as $key => [$grp, $label, $single]): if ($grp !== $g) { continue; } ?>
    <label><?= h($label) ?>
      <?php if ($single): ?><input name="<?= h($key) ?>" maxlength="<?= \App\Templates::MAX_SINGLE ?>" value="<?= h($val($key)) ?>">
      <?php else: ?><textarea name="<?= h($key) ?>" rows="<?= $g === 'pdf' ? 3 : 9 ?>" maxlength="<?= \App\Templates::MAX_MULTI ?>"><?= h($val($key)) ?></textarea><?php endif; ?>
    </label>
  <?php endforeach; ?>
  <?php endforeach; ?>
  <button class="btn primary">Textvorlagen speichern</button>
  <a class="btn" href="<?= BASE ?>?p=settings">Zurück</a>
</form>

<form method="post" class="card" data-confirm="Alle Textvorlagen auf die Standardtexte zurücksetzen? Eigene Änderungen gehen verloren.">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="reset">
  <h2>Zurücksetzen</h2>
  <button class="btn danger">Auf Standardtexte zurücksetzen</button>
</form>
