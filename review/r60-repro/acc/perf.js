const L = require('./lib.js');
(async () => { const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 for (const u of process.argv.slice(2)) { const t0 = Date.now(); const r = await p.goto('/admin.php?p=' + u, {timeout: 120000}); const dt = Date.now() - t0;
   const txt = (await p.innerText('main')).replace(/\s+/g, ' ');
   console.log(`${u}: ${r.status()} ${dt} ms | ${txt.slice(0, Number(process.env.LEN||220))}`); }
 console.log(log.join('\n')); await b.close(); })();
