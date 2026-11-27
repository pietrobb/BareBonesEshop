// INST=sk node ret.js '{"5":2,"6":1}' reason
const L = require('./lib.js');
(async () => { const [items, reason] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await b.newContext({baseURL:L.BASE, storageState:L.S+'/cust-'+L.INST+'.json'}); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 await p.goto('/account.php?p=returns');
 console.log(JSON.stringify(await p.locator('main input:not([type=hidden]), main select, main textarea').evaluateAll(es=>es.map(e=>e.name+'|'+e.type+'|'+e.value))));
 const it = JSON.parse(items); const first = Object.keys(it)[0]; const f = p.locator(`main form:has([name="items[${first}]"])`);
 for (const [k, v] of Object.entries(it)) await f.locator(`[name="items[${k}]"]`).fill(String(v));
 const r = f.locator('[name=reason]'); if (await r.count()) { if (await r.evaluate(e=>e.tagName)==='SELECT') await r.selectOption({index:1}); else await r.fill(reason || 'Nesedí'); }
 await Promise.all([p.waitForLoadState(), f.locator('button').last().click()]); console.log('RMA', (await p.innerText('main')).slice(0,900).replace(/\s+/g,' '));
 console.log(log.join('\n')); await b.close(); })();
