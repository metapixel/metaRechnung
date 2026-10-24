<form method="post" class="card narrow">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
  <h1>Anmelden</h1>
  <?php if (!empty($error)): ?><p class="err" role="alert"><?= h($error) ?></p><?php endif; ?>
  <label>Benutzer <input name="user" autocomplete="username" required autofocus></label>
  <label>Passwort <input name="pass" type="password" autocomplete="current-password" required></label>
  <button class="btn primary">Anmelden</button>
</form>
