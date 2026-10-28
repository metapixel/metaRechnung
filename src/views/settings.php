<?php
$posted = !empty($old);
$g = fn(string $k, string $d = '') => h($posted ? ($old[$k] ?? $d) : env($k, $d));
$ku = $posted ? isset($old['KLEINUNTERNEHMER']) : env('KLEINUNTERNEHMER') === '1';
$co = company();
$miss = [];
if ($co['phone'] === '') { $miss[] = 'Telefon'; }
if ($co['email'] === '') { $miss[] = 'E-Mail'; }
if ($co['iban'] === '') { $miss[] = 'IBAN'; }
if ($co['vat_id'] === '' && $co['tax_number'] === '') { $miss[] = 'Steuernummer oder USt-IdNr.'; }
?>
<h1>Einstellungen</h1>

<?php if ($errors): ?><div class="card warnbox"><?php foreach ($errors as $e): ?><p class="err" role="alert"><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>
<?php if (!\App\Config::writable()): ?><div class="card warnbox"><p class="err" role="alert">config.php ist nicht beschreibbar – Änderungen können nicht gespeichert werden. Bitte Schreibrechte prüfen.</p></div><?php endif; ?>
<?php if ($miss): ?><div class="card warnbox"><p>Für gültige <b>XRechnungen</b> fehlen noch: <?= h(implode(', ', $miss)) ?>.</p></div><?php endif; ?>

<form method="post" class="card" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="save">

  <h2>Dein Unternehmen</h2>
  <p class="hint">Gilt für neu ausgestellte Rechnungen. Bereits ausgestellte Rechnungen behalten die Daten vom Zeitpunkt der Ausstellung.</p>
  <div class="grid2"><label>Firma / Name * <input name="COMPANY_NAME" value="<?= $g('COMPANY_NAME') ?>" required></label>
  <label>Inhaber / Ansprechpartner <input name="COMPANY_OWNER" value="<?= $g('COMPANY_OWNER') ?>"></label></div>
  <label>Straße + Nr. * <input name="COMPANY_STREET" value="<?= $g('COMPANY_STREET') ?>" required></label>
  <div class="grid3"><label>PLZ * <input name="COMPANY_ZIP" value="<?= $g('COMPANY_ZIP') ?>" required></label>
  <label>Ort * <input name="COMPANY_CITY" value="<?= $g('COMPANY_CITY') ?>" required></label>
  <label>Land (ISO) <input name="COMPANY_COUNTRY" maxlength="2" value="<?= $g('COMPANY_COUNTRY', 'DE') ?>"></label></div>
  <div class="grid2"><label>E-Mail <input type="email" name="COMPANY_EMAIL" value="<?= $g('COMPANY_EMAIL') ?>"></label>
  <label>Telefon <input name="COMPANY_PHONE" value="<?= $g('COMPANY_PHONE') ?>"></label></div>
  <div class="grid2"><label>Steuernummer <input name="COMPANY_TAX_NUMBER" value="<?= $g('COMPANY_TAX_NUMBER') ?>"></label>
  <label>USt-IdNr. <input name="COMPANY_VAT_ID" value="<?= $g('COMPANY_VAT_ID') ?>"></label></div>
  <div class="grid3"><label>IBAN <input name="COMPANY_IBAN" value="<?= $g('COMPANY_IBAN') ?>"></label>
  <label>BIC <input name="COMPANY_BIC" value="<?= $g('COMPANY_BIC') ?>"></label>
  <label>Bank <input name="COMPANY_BANK" value="<?= $g('COMPANY_BANK') ?>"></label></div>

  <h2>Rechnungen</h2>
  <div class="grid3"><label>Nummernpräfix <input name="INVOICE_PREFIX" maxlength="8" value="<?= $g('INVOICE_PREFIX', 'RE') ?>"></label>
  <label>Präfix Stornorechnungen <input name="CANCEL_PREFIX" maxlength="8" value="<?= $g('CANCEL_PREFIX', 'ST') ?>"></label>
  <label>Zahlungsziel (Tage) <input type="number" min="0" name="PAYMENT_DAYS" value="<?= $g('PAYMENT_DAYS', '14') ?>"></label></div>
  <label style="color:inherit"><input type="checkbox" name="KLEINUNTERNEHMER" value="1" style="width:auto" <?= $ku ? 'checked' : '' ?>> Kleinunternehmer (§ 19 UStG, keine Umsatzsteuer)</label>
  <label style="color:inherit"><input type="checkbox" name="GIROCODE" value="1" style="width:auto" <?= ($posted ? isset($old['GIROCODE']) : \App\GiroCode::enabled()) ? 'checked' : '' ?>> GiroCode (QR-Code zum Überweisen) auf Rechnung und Statusseite</label>
  <p class="hint">Banking-Apps lesen daraus Empfänger, IBAN, Betrag und Verwendungszweck. Erscheint nur bei gültiger IBAN (Prüfsumme) und Rechnungsbetrag in Euro; gilt für neu ausgestellte Rechnungen.</p>

  <h2>E-Mail-Versand</h2>
  <p class="hint">SMTP-Server leer lassen = Mailversand über den Webspace (PHP <code>mail()</code>).</p>
  <div class="grid3"><label>SMTP-Server <input name="SMTP_HOST" value="<?= $g('SMTP_HOST') ?>"></label>
  <label>Port <input name="SMTP_PORT" value="<?= $g('SMTP_PORT', '587') ?>"></label>
  <label>Verschlüsselung <select name="SMTP_SECURE">
    <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'keine'] as $k => $l): ?>
      <option value="<?= $k ?>" <?= ($posted ? ($old['SMTP_SECURE'] ?? 'tls') : env('SMTP_SECURE', 'tls')) === $k ? 'selected' : '' ?>><?= h($l) ?></option>
    <?php endforeach; ?></select></label></div>
  <div class="grid2"><label>SMTP-Benutzer <input name="SMTP_USER" value="<?= $g('SMTP_USER') ?>"></label>
  <label>SMTP-Passwort <input type="password" name="SMTP_PASS" placeholder="<?= env('SMTP_PASS') !== '' ? '(unverändert)' : '' ?>"></label></div>
  <div class="grid3"><label>Absenderadresse <input type="email" name="MAIL_FROM" value="<?= $g('MAIL_FROM') ?>"></label>
  <label>Absendername <input name="MAIL_FROM_NAME" value="<?= $g('MAIL_FROM_NAME') ?>"></label>
  <label>BCC an dich (optional) <input type="email" name="MAIL_BCC" value="<?= $g('MAIL_BCC') ?>"></label></div>

  <h2>Adresse der App</h2>
  <label>Öffentliche Adresse (optional)
    <input name="APP_URL" value="<?= $g('APP_URL') ?>" placeholder="https://deine-domain.de/rechnung/">
    <span class="hint">Wird für den Statuslink in den Mails verwendet. Leer = automatisch aus der aktuellen Adresse.</span></label>

  <button class="btn primary">Einstellungen speichern</button>
</form>

<div class="card"><h2>PDF-Design</h2><p class="muted">Schrift, Größe, Akzentfarbe und Tabellenstil der Rechnungs-PDFs; Vorlagen und Vorschau.</p><a class="btn" href="<?= BASE ?>?p=pdf_design">PDF-Design anpassen</a></div>
<div class="card"><h2>Protokoll</h2><p class="muted">Wer hat wann was geändert: Rechnungen, Kunden, Einstellungen, Anmeldungen. Die Einträge sind durch eine Prüfsummen-Kette gegen nachträgliche Änderungen geschützt.</p><a class="btn" href="<?= BASE ?>?p=audit">Protokoll öffnen</a></div>
<div class="card"><h2>Darstellung</h2><p class="muted">Eigenes CSS für die Oberfläche (Farben, Rundungen, Schriftgröße) mit Editor und Live-Vorschau.</p><a class="btn" href="<?= BASE ?>?p=design">CSS-Editor öffnen</a></div>

<?php $lh = \App\Letterhead::info(); $lv = fn(string $k, float $d) => h($lh[$k] ?? $d); ?>
<div class="card">
  <h2>Briefpapier</h2>
  <p class="hint">Hintergrund für neu ausgestellte Rechnungen (A4 hoch): <b>PDF</b> (als „PDF 1.4/kompatibel“ exportiert) oder <b>PNG/JPG</b> (empfohlen 2480 × 3508 px, höchstens 5 MB; Server-Limit <?= h(ini_get('upload_max_filesize')) ?>).
  Bereits ausgestellte Rechnungen bleiben unverändert. Pflichtangaben (Steuernummer/USt-IdNr., Bankverbindung) müssen auf dem Briefpapier oder in der Fußzeile der Rechnung stehen.</p>
  <?php if ($lh): ?>
    <p>Aktuell: <b><?= h($lh['file']) ?></b>
    <?php if ($lh['type'] === 'image'): ?><br><img src="<?= BASE ?>?p=letterhead_file" alt="Briefpapier" style="width:140px;border:1px solid var(--line);margin-top:6px">
    <?php else: ?> · <a href="<?= BASE ?>?p=letterhead_file" target="_blank">PDF ansehen</a><?php endif; ?></p>
  <?php else: ?><p class="muted">Noch kein Briefpapier hinterlegt – es wird das eingebaute Layout mit Absenderblock verwendet.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="letterhead">
    <label><?= $lh ? 'Neues Briefpapier hochladen (ersetzt das vorhandene)' : 'Briefpapier hochladen' ?>
      <input type="file" name="letterhead" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,application/pdf,image/*"></label>
    <p class="hint">Seitenränder für den Rechnungstext in mm. 45 mm oben entspricht dem Adressfeld nach DIN 5008.</p>
    <div class="grid2" style="grid-template-columns:repeat(4,1fr)">
      <label>oben <input type="number" step="0.5" min="0" max="120" name="mt" value="<?= $lv('mt', 45) ?>"></label>
      <label>unten <input type="number" step="0.5" min="0" max="120" name="mb" value="<?= $lv('mb', 30) ?>"></label>
      <label>links <input type="number" step="0.5" min="0" max="120" name="ml" value="<?= $lv('ml', 25) ?>"></label>
      <label>rechts <input type="number" step="0.5" min="0" max="120" name="mr" value="<?= $lv('mr', 20) ?>"></label>
    </div>
    <label style="color:inherit"><input type="checkbox" name="hide_sender" value="1" style="width:auto" <?= !$lh || $lh['hideSender'] ? 'checked' : '' ?>> Absenderblock oben rechts ausblenden (steht auf dem Briefpapier)</label>
    <label style="color:inherit"><input type="checkbox" name="hide_footer" value="1" style="width:auto" <?= $lh && $lh['hideFooter'] ? 'checked' : '' ?>> Fußzeile mit Steuer-/Bankdaten ausblenden (steht auf dem Briefpapier)</label>
    <button class="btn primary">Speichern</button>
    <?php if ($lh): ?><a class="btn" href="<?= BASE ?>?p=letterhead_preview" target="_blank">Vorschau (Beispielrechnung)</a><?php endif; ?>
  </form>
  <?php if ($lh): ?>
  <form method="post" style="margin-top:10px" data-confirm="Briefpapier wirklich entfernen?">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="letterhead_delete">
    <button class="btn danger">Briefpapier entfernen</button>
  </form>
  <?php endif; ?>
</div>

<?php $lg = \App\Logo::info(); ?>
<div class="card">
  <h2>Logo</h2>
  <p class="hint">Alternative zum Briefpapier: Ihr Logo erscheint oben auf neu ausgestellten Rechnungen, darunter der Absenderblock. <b>PNG oder JPG</b>, höchstens 2 MB und 3000 × 3000 px
  (transparente Flächen werden weiß). SVG wird aus Sicherheitsgründen nicht angenommen. Ist ein Briefpapier hinterlegt, wird das Logo nicht verwendet.</p>
  <?php if ($lg): ?>
    <p>Aktuell: <b><?= h($lg['file']) ?></b><br><img src="<?= BASE ?>?p=logo_file" alt="Aktuelles Firmenlogo" style="max-width:200px;max-height:100px;border:1px solid var(--line);margin-top:6px;background:#fff"></p>
    <?php if (\App\Letterhead::info()): ?><p class="muted">Hinweis: Das Briefpapier ist aktiv, daher wird das Logo derzeit nicht gedruckt.</p><?php endif; ?>
  <?php else: ?><p class="muted">Noch kein Logo hinterlegt.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="logo">
    <label><?= $lg ? 'Neues Logo hochladen (ersetzt das vorhandene)' : 'Logo hochladen' ?>
      <input type="file" name="logo" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp"></label>
    <div class="grid2">
      <label>Breite im PDF (mm) <input type="number" step="1" min="10" max="120" name="logo_width" value="<?= h($lg['width'] ?? 50) ?>"></label>
      <label>Ausrichtung <select name="logo_align"><option value="right" <?= ($lg['align'] ?? 'right') === 'right' ? 'selected' : '' ?>>rechts</option><option value="left" <?= ($lg['align'] ?? '') === 'left' ? 'selected' : '' ?>>links</option></select></label>
    </div>
    <button class="btn primary">Speichern</button>
    <?php if ($lg): ?><a class="btn" href="<?= BASE ?>?p=letterhead_preview" target="_blank">Vorschau (Beispielrechnung)</a><?php endif; ?>
  </form>
  <?php if ($lg): ?>
  <form method="post" style="margin-top:10px" data-confirm="Logo wirklich entfernen?">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="logo_delete">
    <button class="btn danger">Logo entfernen</button>
  </form>
  <?php endif; ?>
</div>

<form method="post" class="card" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="account">
  <h2>Zugang</h2>
  <label>Aktuelles Passwort * <input type="password" name="current_pass" autocomplete="current-password" required></label>
  <label>Benutzername <input name="new_user" value="<?= h($posted && ($old['action'] ?? '') === 'account' ? ($old['new_user'] ?? '') : env('APP_USER', 'admin')) ?>" required></label>
  <div class="grid2"><label>Neues Passwort (min. 10 Zeichen, leer = unverändert) <input type="password" name="new_pass" autocomplete="new-password"></label>
  <label>Neues Passwort wiederholen <input type="password" name="new_pass2" autocomplete="new-password"></label></div>
  <button class="btn primary">Zugang ändern</button>
</form>

<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="testmail">
  <h2>E-Mail-Versand testen</h2>
  <label>Empfänger <input type="email" name="to" value="<?= h(env('COMPANY_EMAIL')) ?>" required></label>
  <button class="btn">Testmail senden</button>
  <span class="hint">Erst die Einstellungen speichern, dann testen.</span>
</form>

<?php if (env('TEST_MODE') === '1'): ?>
<form method="post" class="card warnbox" autocomplete="off" data-confirm="Wirklich alle Testdaten löschen?">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="reset">
  <h2>Testbetrieb: Daten zurücksetzen</h2>
  <p>Löscht <b>alle Rechnungen</b> samt PDFs (optional auch alle Kunden) unwiderruflich. Einstellungen und Login bleiben erhalten. Die Rechnungsnummern beginnen danach wieder bei 1.</p>
  <label style="color:inherit"><input type="checkbox" name="keep_customers" value="1" style="width:auto" checked> Kunden behalten</label>
  <div class="grid2"><label>Dein Passwort <input type="password" name="reset_pass" autocomplete="current-password" required></label>
  <label>Zur Bestätigung <b>RESET</b> eingeben <input name="confirm" autocomplete="off" required></label></div>
  <button class="btn danger">Testdaten zurücksetzen</button>
  <p class="hint">Diese Funktion ist nur sichtbar, solange <code>'TEST_MODE' => '1'</code> in <code>config.php</code> steht. Vor dem Produktivbetrieb auf <code>'0'</code> setzen.</p>
</form>
<?php endif; ?>

<div class="card">
  <h2>System</h2>
  <p class="muted">Version <b><?= h(APP_VERSION) ?></b> · Testbetrieb: <?= env('TEST_MODE') === '1' ? '<b class="err">AN</b>' : 'aus' ?> ·
  Datenbank: <?= h(env('DB_NAME')) ?> auf <?= h(strtok(env('DB_HOST'), ';')) ?> (nur über <code>config.php</code> änderbar) ·
  storage/ beschreibbar: <?= is_writable(dirname(__DIR__, 2) . '/storage') ? 'ja' : '<b class="err">nein</b>' ?> · PHP <?= h(PHP_VERSION) ?></p>
</div>
