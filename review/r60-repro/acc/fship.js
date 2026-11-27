// INST=sk node fship.js method '{"rates[domestic][base]":"3.99",...,"active":true}'
const L = require('./lib.js');
(async () => { const [m, fj] = process.argv.slice(2); const vals = JSON.parse(fj); const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 await p.goto('/admin.php?p=shipping'); const f = p.locator(`form[data-shipping-file-method=${m}]`);
 for (const [k, v] of Object.entries(vals)) {
   if (k === 'reset') continue;
   if (k.startsWith('+')) { await f.evaluate((form, [n, v]) => { const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); }, [k.slice(1), v]); continue; }
   const el = f.locator(`[name="${k}"]`); if (typeof v === 'boolean') { await el.setChecked(v); } else await el.fill(v); }
 await Promise.all([p.waitForLoadState(), vals.reset ? f.locator('button[name=reset]').click() : f.locator('button.btn-primary').click()]);
 console.log('FLASH', await L.flash(p));
 console.log('FILE', L.fs.readFileSync(L.PRIV + '/shipping-methods.json', 'utf8').replace(/\s+/g, ' '));
 console.log(log.join('\n')); await b.close(); })();
