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
  <div class="grid2"><label>Zahlungsziel (Tage) <input type="number" min="0" name="PAYMENT_DAYS" value="<?= $g('PAYMENT_DAYS', '14') ?>"></label>
  <label>Angebote gültig (Tage) <input type="number" min="1" max="365" name="OFFER_VALID_DAYS" value="<?= $g('OFFER_VALID_DAYS', '30') ?>"></label></div>
  <label style="color:inherit"><input type="checkbox" name="KLEINUNTERNEHMER" value="1" style="width:auto" <?= $ku ? 'checked' : '' ?>> Kleinunternehmer (§ 19 UStG, keine Umsatzsteuer)</label>
  <label style="color:inherit"><input type="checkbox" name="GIROCODE" value="1" style="width:auto" <?= ($posted ? isset($old['GIROCODE']) : \App\GiroCode::enabled()) ? 'checked' : '' ?>> GiroCode (QR-Code zum Überweisen) auf Rechnung und Statusseite</label>
  <p class="hint">Banking-Apps lesen daraus Empfänger, IBAN, Betrag und Verwendungszweck. Erscheint nur bei gültiger IBAN (Prüfsumme) und Rechnungsbetrag in Euro; gilt für neu ausgestellte Rechnungen.</p>

  <label>PayPal.me-Name (optional) <input name="PAYPAL_ME" maxlength="60" value="<?= $g('PAYPAL_ME') ?>" placeholder="z. B. kai für paypal.me/kai"></label>
  <p class="hint">Ist ein Name eingetragen, zeigen Statusseite und PDF zusätzlich „Per PayPal zahlen“ mit vorausgefülltem Betrag (Link und QR-Code). Es gibt keine Verbindung zu PayPal – den Zahlungseingang buchst du wie gewohnt selbst. Gilt für neu ausgestellte Rechnungen.</p>

  <h2 id="nummernkreise">Nummernkreise</h2>
  <p class="hint">Das Format legt fest, wie Belegnummern aussehen. Variablen: <?php foreach (\App\Numbering::VARS as $v => $d): ?><code>{<?= h($v) ?>}</code> <?= h($d) ?>; <?php endforeach; ?>
    Sonst sind Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich erlaubt. Mit <code>{JAHR}</code> (oder <code>{JJ}</code>) im Format beginnt die Zählung jedes Jahr neu, ohne Datumsvariable läuft sie durch.
    Beispiele: <code>RE-{JAHR}-{NR}</code> → RE-2026-0341, <code>RE{JJ}{MONAT}-{NR}</code> → RE2611-0341, <code>{NR}</code> → 0341.</p>
  <div class="scroll"><table>
    <caption class="sr">Nummernkreise</caption>
    <tr><th scope="col">Belegart</th><th scope="col">Format</th><th scope="col">Stellen</th><th scope="col">Nächste Nummer festlegen (optional)</th><th scope="col">Nächste Nummer wäre</th></tr>
    <?php foreach (\App\Numbering::TYPES as $t => [$np, $nl]): $fmt = $posted ? ($old[$np . '_FORMAT'] ?? '') : \App\Numbering::format($t); ?>
    <tr>
      <th scope="row"><?= h($nl) ?></th>
      <td><input name="<?= $np ?>_FORMAT" maxlength="60" value="<?= h($fmt) ?>" aria-label="Format <?= h($nl) ?>" style="min-width:12rem"></td>
      <td><input type="number" min="1" max="10" name="<?= $np ?>_PAD" value="<?= h($posted ? ($old[$np . '_PAD'] ?? '4') : (string)\App\Numbering::pad($t)) ?>" aria-label="Stellen <?= h($nl) ?>" style="width:5rem"></td>
      <td><input type="number" min="1" max="<?= \App\Numbering::MAX_NR ?>" name="<?= $np ?>_NEXT" value="<?= h($posted ? ($old[$np . '_NEXT'] ?? '') : env($np . '_NEXT')) ?>" aria-label="Nächste Nummer <?= h($nl) ?>" placeholder="automatisch" style="width:9rem"></td>
      <td><b><?= h(\App\Numbering::preview($t)) ?></b></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="hint"><b>Umstieg aus einem laufenden Betrieb:</b> Hast du dieses Jahr schon Rechnungen in einem anderen System geschrieben, trage bei „Nächste Nummer festlegen“ die nächste freie Nummer ein (nach Rechnung Nr. 340 also <b>341</b>) und stelle das Format so ein, dass es zu deinen bisherigen Nummern passt. Die Vorschau rechts zeigt das Ergebnis nach dem Speichern. Die Angabe wird nach der nächsten ausgestellten Nummer verbraucht und kann den Zähler nur anheben, nie unter bereits vergebene Nummern setzen. Bereits ausgestellte Belege behalten ihre Nummer.</p>

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

  <h2 id="steuerberater">Steuerberater</h2>
  <p class="hint">Standard-Ansprechpartner für den <a href="<?= BASE ?>?p=exports">Export</a>: Die Adresse ist dort vorausgefüllt, der Name erscheint in der Anrede der Mail.</p>
  <div class="grid2"><label>Ansprechpartner:in <input name="TAX_ADVISOR_NAME" maxlength="120" value="<?= $g('TAX_ADVISOR_NAME') ?>" placeholder="Vorname Nachname"></label>
  <label>E-Mail <input type="email" name="TAX_ADVISOR_EMAIL" maxlength="190" value="<?= $g('TAX_ADVISOR_EMAIL') ?>" placeholder="steuerberater@example.com"></label></div>

  <h2>Adresse der App</h2>
  <label>Öffentliche Adresse (optional)
    <input name="APP_URL" value="<?= $g('APP_URL') ?>" placeholder="https://deine-domain.de/rechnung/">
    <span class="hint">Wird für den Statuslink in den Mails verwendet. Leer = automatisch aus der aktuellen Adresse.</span></label>

  <button class="btn primary">Einstellungen speichern</button>
</form>

<div class="card"><h2>Textvorlagen</h2><p class="muted">E-Mail-Texte (Rechnung, Angebot, Zahlungserinnerung, Export) und Texte auf dem PDF – mit Platzhaltern wie {nummer}, {faellig} oder {betrag}.</p><a class="btn" href="<?= BASE ?>?p=templates">Textvorlagen bearbeiten</a></div>
<div class="card"><h2>PDF-Design</h2><p class="muted">Schrift, Größe, Akzentfarbe und Tabellenstil der Rechnungs-PDFs; Vorlagen und Vorschau.</p><a class="btn" href="<?= BASE ?>?p=pdf_design">PDF-Design anpassen</a></div>
<div class="card"><h2>Export für den Steuerberater</h2><p class="muted">ZIP mit Rechnungen (PDF, XML) und CSV-Übersicht für einen Zeitraum, abrufbar über einen befristeten Link – auf Wunsch per E-Mail.</p><a class="btn" href="<?= BASE ?>?p=exports">Export erstellen</a></div>
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

<?php $fonts = \App\Fonts::all(); $uiFont = \App\Fonts::uiKey(); ?>
<section class="card" id="schriften" aria-labelledby="h-fonts">
  <h2 id="h-fonts">Schriften</h2>
  <p class="muted">Lade eigene Schriften (TrueType, <code>.ttf</code>) hoch, um sie im <a href="<?= BASE ?>?p=pdf_design">PDF-Design</a> und für die Oberfläche zu nutzen. Die Dateien bleiben auf deinem Server (keine externen Dienste). Bitte nur Schriften verwenden, deren Lizenz Einbetten in PDFs und Nutzung auf Webseiten erlaubt.</p>
  <?php if ($fonts): ?>
  <div class="scroll"><table>
    <caption class="sr">Hochgeladene Schriften</caption>
    <tr><th scope="col">Name</th><th scope="col">Schnitte</th><th scope="col">Verwendung</th><th scope="col"><span class="sr">Aktion</span></th></tr>
    <?php foreach ($fonts as $k => $f): $use = \App\Fonts::usage($k); ?>
    <tr>
      <th scope="row"><?= h($f['name']) ?></th>
      <td><?= h(implode(', ', array_map(static fn($s) => \App\Fonts::STYLES[$s], array_keys($f['files'])))) ?></td>
      <td><?= $use ? h(implode(', ', $use)) : '–' ?></td>
      <td><?php if (!$use): ?><form method="post" data-confirm="Schrift „<?= h($f['name']) ?>“ wirklich löschen?"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="font_delete"><input type="hidden" name="key" value="<?= h($k) ?>"><button class="btn danger">Löschen</button></form><?php else: ?><span class="muted">in Gebrauch</span><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <form method="post" class="actions" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="font_ui">
    <label for="ui_font">Schrift der Oberfläche</label>
    <select id="ui_font" name="ui_font" style="width:auto">
      <option value="">Standard (Systemschrift)</option>
      <?php foreach ($fonts as $k => $f): ?><option value="<?= h($k) ?>" <?= $uiFont === $k ? 'selected' : '' ?>><?= h($f['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn">Übernehmen</button>
  </form>
  <?php endif; ?>
  <?php if (count($fonts) < \App\Fonts::MAX_FAMILIES): ?>
  <form method="post" enctype="multipart/form-data" autocomplete="off" style="margin-top:16px">
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="font_add">
    <h3>Neue Schrift hinzufügen</h3>
    <label>Name der Schrift * <input name="font_name" maxlength="40" required placeholder="z. B. Source Sans"></label>
    <div class="grid2">
      <label>Regular (.ttf) * <input type="file" name="font_r" accept=".ttf,font/ttf" required></label>
      <label>Fett (optional) <input type="file" name="font_b" accept=".ttf,font/ttf"></label>
      <label>Kursiv (optional) <input type="file" name="font_i" accept=".ttf,font/ttf"></label>
      <label>Fett kursiv (optional) <input type="file" name="font_bi" accept=".ttf,font/ttf"></label>
    </div>
    <p class="hint">Je Datei bis <?= (int)(\App\Fonts::MAX_BYTES / 1048576) ?> MB. OpenType-Schriften mit PostScript-Umrissen (CFF) und Schriftsammlungen (.ttc) funktionieren im PDF nicht – dann bitte die TrueType-Datei nehmen. Fehlt ein Schnitt, ersetzt das PDF ihn durch den Regular-Schnitt.</p>
    <button class="btn primary">Schrift hochladen</button>
  </form>
  <?php endif; ?>
</section>

<form method="post" class="card" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="account">
  <h2>Zugang</h2>
  <label>Aktuelles Passwort * <input type="password" name="current_pass" autocomplete="current-password" required></label>
  <label>Benutzername <input name="new_user" value="<?= h($posted && ($old['action'] ?? '') === 'account' ? ($old['new_user'] ?? '') : env('APP_USER', 'admin')) ?>" required></label>
  <div class="grid2"><label>Neues Passwort (min. 10 Zeichen, leer = unverändert) <input type="password" name="new_pass" autocomplete="new-password"></label>
  <label>Neues Passwort wiederholen <input type="password" name="new_pass2" autocomplete="new-password"></label></div>
  <button class="btn primary">Zugang ändern</button>
</form>

<?php
$mfaOn = \App\Totp::enabled();
$mfaCodes = $_SESSION['mfa_codes'] ?? null; unset($_SESSION['mfa_codes']);
$mfaPend = $_SESSION['mfa_pending'] ?? null;
if (is_array($mfaPend) && (time() - (int)$mfaPend['time'] > 1800 || $mfaOn)) { $mfaPend = null; unset($_SESSION['mfa_pending']); }
?>
<section class="card" id="zwei-faktor" aria-labelledby="h-mfa">
  <h2 id="h-mfa">Zwei-Faktor-Anmeldung (2FA)</h2>
  <?php if (is_array($mfaCodes)): ?>
    <div class="warnbox card">
      <p><b>Deine Wiederherstellungscodes</b> – jeder Code funktioniert genau einmal, falls du dein Handy nicht zur Hand hast. Sie werden nur jetzt angezeigt: bitte ausdrucken oder im Passwortmanager speichern.</p>
      <ul class="codes"><?php foreach ($mfaCodes as $c): ?><li><code><?= h($c) ?></code></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($mfaOn): ?>
    <p><span class="badge paid">aktiv</span> Beim Anmelden wird zusätzlich ein Code aus der Authenticator-App abgefragt. Noch <b><?= count(\App\Totp::recoveryHashes()) ?></b> Wiederherstellungscode(s) übrig.</p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
      <label>Aktuelles Passwort (zur Bestätigung) <input type="password" name="current_pass" autocomplete="current-password" required></label>
      <div class="actions">
        <button class="btn" name="action" value="mfa_regen">Neue Wiederherstellungscodes erzeugen</button>
        <button class="btn danger" name="action" value="mfa_disable" data-confirm="Zwei-Faktor-Anmeldung wirklich deaktivieren?">2FA deaktivieren</button>
      </div>
    </form>
  <?php elseif (is_array($mfaPend)): $uri = \App\Totp::uri((string)$mfaPend['secret'], (string)env('APP_USER', 'admin')); ?>
    <p>1. Scanne den QR-Code mit einer Authenticator-App (z. B. Google Authenticator, Microsoft Authenticator, Aegis, 2FAS, 1Password).</p>
    <figure class="girocode"><img src="<?= h(\App\GiroCode::svgDataUri($uri, 200)) ?>" width="200" height="200" alt="QR-Code zum Einrichten der Authenticator-App">
      <figcaption>Ohne Kamera: Schlüssel von Hand eingeben (zeitbasiert): <code><?= h(trim(chunk_split((string)$mfaPend['secret'], 4, ' '))) ?></code></figcaption></figure>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="mfa_enable">
      <p>2. Gib zur Kontrolle den aktuellen 6-stelligen Code ein:</p>
      <label>Code <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required></label>
      <button class="btn primary">Bestätigen und aktivieren</button>
    </form>
  <?php else: ?>
    <p class="muted">Schützt die Anmeldung zusätzlich zum Passwort mit einem Code aus einer Authenticator-App (TOTP). Für den Notfall gibt es einmalig verwendbare Wiederherstellungscodes.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="mfa_start"><button class="btn primary">2FA einrichten</button></form>
  <?php endif; ?>
</section>

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
