const L = require('./lib.js');
(async () => { const [verify] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await b.newContext({baseURL:L.BASE}); const p = await ctx.newPage();
 await p.goto(verify || '/account.php?p=login'); const rej = p.locator('#cc-banner [value=banner_reject_all]'); if (await rej.isVisible().catch(()=>false)) { await rej.click(); await p.waitForLoadState(); }
 const f = p.locator('form:has([name=action][value=login])'); console.log(JSON.stringify(await f.locator('input').evaluateAll(es=>es.map(e=>e.name+'='+e.value.slice(0,20)))));
 await f.locator('[name=email]').fill('peter@example.sk'); await f.locator('[name=password]').fill('Zuzana-Passw0rd-2026');
 await Promise.all([p.waitForLoadState(), f.locator('button').first().click()]); console.log('LOGIN', p.url(), (await p.innerText('main')).slice(0,300).replace(/\s+/g,' '));
 await p.goto('/account.php?p=orders'); console.log((await p.innerText('main')).slice(0,600).replace(/\s+/g,' '));
 await ctx.storageState({path: L.S + '/cust-' + L.INST + '.json'}); await b.close(); })();
