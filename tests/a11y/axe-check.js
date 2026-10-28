// Barrierefreiheits-Prüfung (axe-core, WCAG 2.2 AA) gegen eine laufende Test-Instanz.
// Aufruf:  npm i axe-core playwright && A11Y_URL=http://127.0.0.1:8097/ A11Y_USER=kai A11Y_PASS=... [A11Y_STATUS=<public_token>] node axe-check.js
// Die Instanz sollte mindestens eine Rechnung (id=1) enthalten. Beendet mit Exit-Code 1 bei Verstößen.
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
const axe = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const B = process.env.A11Y_URL || 'http://127.0.0.1:8097/';
(async () => {
  const b = await chromium.launch(); let bad = 0;
  for (const scheme of ['light', 'dark']) {
    const ctx = await b.newContext({ colorScheme: scheme, bypassCSP: true, viewport: { width: 1100, height: 800 } }); const p = await ctx.newPage();
    const scan = async (name) => {
      await p.addScriptTag({ content: axe });
      const r = await p.evaluate(() => axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] } }));
      r.violations.forEach(v => { bad++; console.log(scheme, name, v.impact, v.id, v.nodes.map(n => n.target.join(' ')).slice(0, 3).join(' ; ')); });
      if (!r.violations.length) console.log(scheme, name, 'ok');
    };
    await p.goto(B); await scan('login');
    await p.fill('[name=user]', process.env.A11Y_USER || 'kai'); await p.fill('[name=pass]', process.env.A11Y_PASS || ''); await Promise.all([p.waitForNavigation(), p.click('button.primary')]);
    for (const q of ['invoices', 'invoice_edit', 'customers', 'customer_edit', 'customer_import', 'articles', 'article_edit', 'settings', 'invoice&id=1', 'invoice&id=2', 'invoice_edit&id=2', 'invoices', 'recurring', 'reports', 'design', 'pdf_design', 'recurring_edit', 'recurring_edit&id=' + (process.env.A11Y_RID || '1')]) { await p.goto(B + '?p=' + q); await scan(q); }
    if (process.env.A11Y_STATUS) { await p.goto(B + 'status.php?t=' + process.env.A11Y_STATUS); await scan('status (GiroCode)'); }
    await ctx.close();
  }
  await b.close(); process.exit(bad ? 1 : 0);
})();
