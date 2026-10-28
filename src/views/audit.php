<?php
use App\Audit;
$rows = $res['rows']; $total = (int)$res['total']; $pages = max(1, (int)ceil($total / $per));
$link = function (array $r): string {
    $id = (int)$r['entity_id'];
    if (!$id || str_ends_with((string)$r['action'], '.delete')) { return ''; }
    $map = ['invoice' => 'invoice', 'customer' => 'customer_edit', 'article' => 'article_edit', 'recurring' => 'recurring_edit'];
    $p = $map[$r['entity']] ?? '';
    return $p ? '<a href="' . h(BASE) . '?p=' . $p . '&amp;id=' . $id . '">#' . $id . '</a>' : '';
};
$qs = fn(int $p) => h(BASE) . '?p=audit&amp;g=' . urlencode($grp) . '&amp;q=' . urlencode($q) . '&amp;page=' . $p;
?>
<div class="row">
  <h1>Protokoll</h1>
  <form method="post" class="actions"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><button class="btn">Integrität prüfen</button></form>
</div>
<p class="muted">Jede Änderung wird mit Zeit, Benutzer und IP-Adresse festgehalten. Einträge lassen sich in der App weder ändern noch löschen; sie sind per Prüfsummen-Kette verknüpft („Integrität prüfen“). Passwörter und Einstellungswerte werden nicht gespeichert, bei Einstellungen nur die Feldnamen. Lesezugriffe werden nicht protokolliert.</p>
<form method="get" class="card" role="search" aria-label="Protokoll filtern">
  <input type="hidden" name="p" value="audit">
  <div class="grid3">
    <label>Bereich
      <select name="g"><option value="">Alle</option><?php foreach (Audit::GROUPS as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $grp === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select>
    </label>
    <label>Suche <span class="hint">in Details, Benutzer, IP</span><input name="q" maxlength="100" value="<?= h($q) ?>"></label>
    <div style="align-self:end"><button class="btn primary">Filtern</button></div>
  </div>
</form>
<?php if (!$rows): ?>
  <p class="muted">Keine Einträge<?= ($grp !== '' || $q !== '') ? ' für diesen Filter' : '' ?>.</p>
<?php else: ?>
<p class="muted"><?= $total ?> Einträge<?= $pages > 1 ? ' – Seite ' . (int)$pg . ' von ' . $pages : '' ?></p>
<div class="scroll"><table>
  <caption class="sr">Änderungsprotokoll</caption>
  <tr><th scope="col">Zeit</th><th scope="col">Benutzer</th><th scope="col">Aktion</th><th scope="col">Objekt</th><th scope="col">Details</th><th scope="col">IP</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td class="r"><?= h(date('d.m.Y H:i:s', strtotime((string)$r['created_at']))) ?></td>
    <td><?= h($r['actor']) ?></td>
    <td><?= h(Audit::label((string)$r['action'])) ?></td>
    <td><?= $link($r) ?></td>
    <td><?= h($r['summary']) ?></td>
    <td class="muted"><?= h($r['ip']) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php if ($pages > 1): ?>
<p class="actions" style="margin-top:12px">
  <?php if ($pg > 1): ?><a class="btn" href="<?= $qs($pg - 1) ?>">← Neuere</a><?php endif; ?>
  <?php if ($pg < $pages): ?><a class="btn" href="<?= $qs($pg + 1) ?>">Ältere →</a><?php endif; ?>
</p>
<?php endif; endif; ?>
