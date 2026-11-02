<?php $step = $step ?? 'password'; ?>
<form method="post" class="card narrow">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <?php if ($step === 'code'): ?>
  <h1>Zwei-Faktor-Code</h1>
  <?php if (!empty($error)): ?><p class="err" role="alert"><?= h($error) ?></p><?php endif; ?>
  <p class="muted">Gib den 6-stelligen Code aus deiner Authenticator-App ein – oder einen deiner Wiederherstellungscodes.</p>
  <label>Code <input name="code" inputmode="text" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="20" required autofocus></label>
  <div class="actions">
    <button class="btn primary">Bestätigen</button>
    <button class="btn" name="action" value="cancel" formnovalidate>Abbrechen</button>
  </div>
  <?php else: ?>
  <h1>Anmelden</h1>
  <?php if (!empty($error)): ?><p class="err" role="alert"><?= h($error) ?></p><?php endif; ?>
  <label>Benutzer <input name="user" autocomplete="username" required autofocus></label>
  <label>Passwort <input name="pass" type="password" autocomplete="current-password" required></label>
  <button class="btn primary">Anmelden</button>
  <?php endif; ?>
</form>
