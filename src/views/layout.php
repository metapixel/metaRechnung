<?php
$cur = (string)($_GET['p'] ?? 'invoices');
$cur = ['invoice' => 'invoices', 'invoice_edit' => 'invoices', 'customer_edit' => 'customers'][$cur] ?? $cur;
$nav = fn(string $p, string $label, string $icon) =>
    '<a href="' . h(BASE) . '?p=' . $p . '"' . ($cur === $p ? ' class="active" aria-current="page"' : '') . '><svg class="ic"><use href="#i-' . $icon . '"/></svg>' . h($label) . '</a>';
$brand = '<svg class="logo" viewBox="0 0 512 512" aria-hidden="true"><use href="#logo"/></svg><span class="nm"><i>meta</i><b>Rechnung</b></span>';
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>metaRechnung</title>
<?php if (empty($public)): ?><link rel="manifest" href="<?= BASE ?>manifest.webmanifest">
<?php endif; ?><meta name="theme-color" content="#4f46e5">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="<?= BASE ?>icons/icon.svg">
<link rel="icon" type="image/png" href="<?= BASE ?>icons/icon-192.png">
<link rel="apple-touch-icon" href="<?= BASE ?>icons/icon-192.png">
<link rel="stylesheet" href="<?= BASE ?>style.css">
</head>
<body data-kl="<?= \App\Invoices::kleinunternehmer() ? '1' : '0' ?>" data-sw="<?= empty($public) ? h(BASE) . 'sw.js' : '' ?>">
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <defs><linearGradient id="lg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4f46e5"/><stop offset="1" stop-color="#7c3aed"/></linearGradient></defs>
  <symbol id="logo" viewBox="0 0 512 512">
    <rect width="512" height="512" rx="112" fill="url(#lg)"/>
    <path d="M144 116h160l64 64v216a28 28 0 0 1-28 28H144a28 28 0 0 1-28-28V144a28 28 0 0 1 28-28z" fill="#fff" fill-opacity=".96"/>
    <path d="M304 116v48a16 16 0 0 0 16 16h48z" fill="#c7c9f5"/>
    <path d="M168 330v-84m0 28c0-22 14-34 32-34s30 12 30 34v56m0-56c0-22 14-34 32-34s30 12 30 34v56" fill="none" stroke="#4f46e5" stroke-width="26" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
  <symbol id="i-invoices" viewBox="0 0 24 24"><path d="M7 3h7l5 5v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></symbol>
  <symbol id="i-customers" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/></symbol>
  <symbol id="i-settings" viewBox="0 0 24 24"><path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2.2"/><circle cx="8" cy="17" r="2.2"/></symbol>
  <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M15 8l4 4-4 4M19 12H9"/></symbol>
</svg>
<?php if (env('TEST_MODE') === '1' && empty($public)): ?><div class="testbanner">TESTBETRIEB – Daten können in den Einstellungen zurückgesetzt werden</div><?php endif; ?>
<?php if (empty($bare)): ?>
<header>
  <a class="brand" href="<?= BASE ?>"><?= $brand ?></a>
  <nav aria-label="Hauptmenü">
    <?= $nav('invoices', 'Rechnungen', 'invoices') ?>
    <?= $nav('customers', 'Kunden', 'customers') ?>
    <?= $nav('settings', 'Einstellungen', 'settings') ?>
    <form method="post" action="<?= BASE ?>?p=logout" class="inline"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="navbtn"><svg class="ic"><use href="#i-logout"/></svg>Abmelden</button></form>
  </nav>
</header>
<?php else: ?>
<div class="bare-brand"><span class="brand"><?= $brand ?></span></div>
<?php endif; ?>
<main>
<?php if ($m = flash()): ?><div class="flash" role="status"><?= h($m) ?></div><?php endif; ?>
<?= $content ?>
</main>
<footer class="foot">
  <span><b>metaRechnung</b></span>
<?php if (empty($public) && !empty($_SESSION['auth'])): ?>
  <span class="dot">•</span><span class="ver">Version <?= h(APP_VERSION) ?></span>
  <span class="dot">•</span><span>GPL-2.0-or-later</span>
<?php endif; ?>
</footer>
<script src="<?= BASE ?>app.js" defer></script>
</body>
</html>
