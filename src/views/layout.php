<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rechnungen</title>
<?php if (empty($public)): ?><link rel="manifest" href="<?= BASE ?>manifest.webmanifest">
<?php endif; ?><meta name="theme-color" content="#1d4ed8">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="<?= BASE ?>icons/icon-192.png">
<link rel="apple-touch-icon" href="<?= BASE ?>icons/icon-192.png">
<link rel="stylesheet" href="<?= BASE ?>style.css">
</head>
<body data-kl="<?= \App\Invoices::kleinunternehmer() ? '1' : '0' ?>" data-sw="<?= empty($public) ? h(BASE) . 'sw.js' : '' ?>">
<?php if (env('TEST_MODE') === '1' && empty($public)): ?><div class="testbanner">TESTBETRIEB – Daten können in den Einstellungen zurückgesetzt werden</div><?php endif; ?>
<?php if (empty($bare)): ?>
<header>
  <a class="brand" href="<?= BASE ?>">Rechnungen</a>
  <nav>
    <a href="<?= BASE ?>?p=invoices">Rechnungen</a>
    <a href="<?= BASE ?>?p=customers">Kunden</a>
    <a href="<?= BASE ?>?p=settings">Einstellungen</a>
    <form method="post" action="<?= BASE ?>?p=logout" class="inline"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="navbtn">Abmelden</button></form>
  </nav>
</header>
<?php endif; ?>
<main>
<?php if ($m = flash()): ?><div class="flash"><?= h($m) ?></div><?php endif; ?>
<?= $content ?>
</main>
<?php if (empty($public)): ?><footer class="foot">Rechnungs-App v<?= h(APP_VERSION) ?></footer><?php endif; ?>
<script src="<?= BASE ?>app.js" defer></script>
</body>
</html>
