// INST=xx ALANG=de node awalk.js
const L = require('./lib.js');
(async () => { const b = await L.chromium.launch(); 
 for (const w of [390, 1280]) { const ctx = await L.adminContext(b, { viewport: { width: w, height: 900 } }); const p = await ctx.newPage(); const log = []; L.watch(p, log);
  await p.goto('/admin.php' + (process.env.ALANG ? '?lang=' + process.env.ALANG : ''));
  const links = await p.locator('nav a[href*="admin.php?p="], aside a[href*="admin.php?p="]').evaluateAll(as => [...new Set(as.map(a => a.getAttribute('href')))]);
  const extra = ['admin.php?p=order&id=1', 'admin.php?p=product&id=1', 'admin.php?p=product', 'admin.php?p=order_new'];
  let slow = [];
  for (const u of [...links, ...extra]) { const t0 = Date.now(); const r = await p.goto('/' + u); const dt = Date.now() - t0; const st = r ? r.status() : 0;
   const ov = await L.overflow(p); const rk = await L.rawKeys(p); const lang = await p.getAttribute('html', 'lang');
   if (dt > 1500) slow.push(u + ' ' + dt + 'ms');
   if (st >= 400 || ov.length || rk.length || (process.env.ALANG && lang !== process.env.ALANG)) console.log(`[${w}] ${u} st=${st} lang=${lang} ${dt}ms ov=${JSON.stringify(ov.slice(0,3))} raw=${JSON.stringify(rk)}`); }
  console.log(`[${w}] pages=${links.length + extra.length} slow=${JSON.stringify(slow)} log:\n` + [...new Set(log)].join('\n'));
  if (process.env.ALANG) await p.goto('/admin.php?lang=' + (process.env.BACK || L.INST)); await ctx.close(); }
 await b.close(); })();
