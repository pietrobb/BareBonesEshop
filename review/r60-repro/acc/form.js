// INST=sk node form.js 'admin.php?p=stores' 'form[action*="action=add"]' '{"id":"at",...}' [submitSelector]
const L = require('./lib.js');
(async () => { const [u, sel, fj, btn] = process.argv.slice(2); const vals = JSON.parse(fj); const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 p.on('dialog', d => d.accept()); p.setDefaultTimeout(8000);
 await p.goto('/' + u); const f = p.locator(sel).first(); if (!(await f.count())) { console.log('NO FORM', sel); await b.close(); return; }
 for (const [k, v] of Object.entries(vals)) {
   if (k.startsWith('+')) { await f.evaluate((form, [n, v]) => { const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); }, [k.slice(1), v]); continue; }
   const ok = await f.evaluate((form, [k, v]) => { let el = form.elements.namedItem(k); if (!el) return false; if (el instanceof RadioNodeList) el = el[el.length - 1];
     if (el.type === 'checkbox') el.checked = !!v; else el.value = String(v); el.dispatchEvent(new Event('change', {bubbles:true})); return true; }, [k, v]);
   if (!ok) console.log('NO FIELD', k); }
 const t0 = Date.now();
 await Promise.all([p.waitForNavigation({timeout:20000}), btn ? f.locator(btn).first().click() : f.evaluate(x => { x.noValidate = true; x.requestSubmit(); })]);
 console.log('ms', Date.now() - t0, 'URL', p.url()); console.log('FLASH', await L.flash(p));
 if (process.env.BODY) console.log((await p.innerText('main')).slice(0, Number(process.env.BODY)));
 console.log(log.join('\n')); await b.close(); })();
