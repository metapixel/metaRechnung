<?php
$cur = (string)($_GET['p'] ?? 'invoices');
$cur = ['invoice' => 'invoices', 'invoice_edit' => 'invoices', 'customer_edit' => 'customers', 'customer_import' => 'customers', 'article_edit' => 'articles', 'recurring_edit' => 'recurring', 'design' => 'settings', 'pdf_design' => 'settings', 'audit' => 'settings'][$cur] ?? $cur;
$nav = fn(string $p, string $label, string $icon) =>
    '<a href="' . h(BASE) . '?p=' . $p . '"' . ($cur === $p ? ' class="active" aria-current="page"' : '') . '><svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' . $icon . '"/></svg>' . h($label) . '</a>';
$brand = '<svg class="logo" viewBox="0 0 512 512" aria-hidden="true"><use href="#logo"/></svg><span class="nm"><i>meta</i><b>Rechnung</b></span>';
$ptitle = 'metaRechnung';
if (preg_match('~<h1[^>]*>(.*?)</h1>~s', (string)($content ?? ''), $hm)) {
    $ht = trim(html_entity_decode(strip_tags($hm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($ht !== '' && $ht !== 'metaRechnung') { $ptitle = $ht . ' – metaRechnung'; }
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($ptitle) ?></title>
<?php if (empty($public)): ?><link rel="manifest" href="<?= BASE ?>manifest.webmanifest">
<?php endif; ?><meta name="theme-color" content="#7a4589">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="<?= BASE ?>icons/icon.svg">
<link rel="icon" type="image/png" href="<?= BASE ?>icons/icon-192.png">
<link rel="apple-touch-icon" href="<?= BASE ?>icons/icon-192.png">
<link rel="stylesheet" href="<?= h(asset('style.css')) ?>">
<?php if (\App\Theme::active() && ($customCss = \App\Theme::get()) !== ''): ?><style id="custom-css"><?= str_replace('<', '', $customCss) ?></style>
<?php elseif (($_GET['p'] ?? '') === 'design'): ?><style id="custom-css"></style>
<?php endif; ?>
</head>
<body data-kl="<?= \App\Invoices::kleinunternehmer() ? '1' : '0' ?>" data-sw="<?= empty($public) ? h(asset('sw.js')) : '' ?>">
<a class="skip" href="#main">Zum Inhalt springen</a>
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <defs><linearGradient id="lg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#9a5db0"/><stop offset="1" stop-color="#5c3069"/></linearGradient></defs>
  <symbol id="logo" viewBox="0 0 512 512">
    <rect width="512" height="512" rx="112" fill="url(#lg)"/>
    <path d="M144 116h160l64 64v216a28 28 0 0 1-28 28H144a28 28 0 0 1-28-28V144a28 28 0 0 1 28-28z" fill="#fff" fill-opacity=".96"/>
    <path d="M304 116v48a16 16 0 0 0 16 16h48z" fill="#e6d3ee"/>
    <path d="M168 330v-84m0 28c0-22 14-34 32-34s30 12 30 34v56m0-56c0-22 14-34 32-34s30 12 30 34v56" fill="none" stroke="#6f3d7e" stroke-width="26" stroke-linecap="round" stroke-linejoin="round"/>
  </symbol>
  <symbol id="i-invoices" viewBox="0 0 24 24"><path d="M7 3h7l5 5v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></symbol>
  <symbol id="i-customers" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/></symbol>
  <symbol id="i-articles" viewBox="0 0 24 24"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/></symbol>
  <symbol id="i-recurring" viewBox="0 0 24 24"><path d="M4 12a8 8 0 0 1 13.7-5.6L20 8.5M20 4v4.5h-4.5M20 12a8 8 0 0 1-13.7 5.6L4 15.5M4 20v-4.5h4.5"/></symbol>
  <symbol id="i-reports" viewBox="0 0 24 24"><path d="M5 20V11M12 20V4M19 20v-6"/></symbol>
  <symbol id="i-settings" viewBox="0 0 24 24"><path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2.2"/><circle cx="8" cy="17" r="2.2"/></symbol>
  <symbol id="i-github" viewBox="0 0 16 16"><path fill="currentColor" stroke="none" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></symbol>
  <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M15 8l4 4-4 4M19 12H9"/></symbol>
</svg>
<?php if (env('TEST_MODE') === '1' && empty($public)): ?><div class="testbanner" role="region" aria-label="Testbetrieb">TESTBETRIEB – Daten können in den Einstellungen zurückgesetzt werden</div><?php endif; ?>
<?php if (empty($bare)): ?>
<header>
  <a class="brand" href="<?= BASE ?>" aria-label="metaRechnung – Startseite"><?= $brand ?></a>
  <nav aria-label="Hauptmenü">
    <?= $nav('invoices', 'Rechnungen', 'invoices') ?>
    <?= $nav('customers', 'Kunden', 'customers') ?>
    <?= $nav('articles', 'Artikel', 'articles') ?>
    <?= $nav('recurring', 'Wiederkehrend', 'recurring') ?>
    <?= $nav('reports', 'Auswertung', 'reports') ?>
    <?= $nav('settings', 'Einstellungen', 'settings') ?>
    <form method="post" action="<?= BASE ?>?p=logout" class="inline"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="navbtn"><svg class="ic" aria-hidden="true" focusable="false"><use href="#i-logout"/></svg>Abmelden</button></form>
  </nav>
</header>
<?php else: ?>
<header class="bare-brand"><span class="brand"><?= $brand ?></span></header>
<?php endif; ?>
<main id="main" tabindex="-1">
<div id="live" class="sr" role="status" aria-live="polite"></div>
<?php if ($m = flash()): ?><div class="flash" role="status"><?= h($m) ?></div><?php endif; ?>
<?= $content ?>
</main>
<footer class="foot">
  <span><b>metaRechnung</b></span>
<?php if (empty($public) && !empty($_SESSION['auth'])): ?>
  <span class="dot" aria-hidden="true">•</span><span class="ver">Version <?= h(APP_VERSION) ?></span>
  <span class="dot" aria-hidden="true">•</span><span>GPL-2.0-or-later</span>
<?php endif; ?>
<?php if (empty($public)): ?>
  <a class="gh" href="https://github.com/metapixel/metaRechnung" target="_blank" rel="noopener noreferrer" title="metaRechnung auf GitHub" aria-label="metaRechnung auf GitHub"><svg viewBox="0 0 16 16" aria-hidden="true"><use href="#i-github"/></svg></a>
<?php endif; ?>
</footer>
<script src="<?= h(asset('app.js')) ?>" defer></script>
</body>
</html>
