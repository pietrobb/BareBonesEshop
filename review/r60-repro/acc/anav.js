const L = require('./lib.js');
(async () => { const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 await p.goto('/admin.php'); console.log('lang=' + await p.getAttribute('html','lang'), 'title=' + await p.title());
 const links = await p.locator('a[href*="admin.php?p="]').evaluateAll(as => [...new Set(as.map(a => a.getAttribute('href')))]);
 console.log(links.join('\n')); console.log((await p.innerText('body')).slice(0, 1500)); console.log(log.join('\n')); await b.close(); })();
