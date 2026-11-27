const L = require('./lib.js');
(async () => { const [email, name] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await b.newContext({baseURL:L.BASE}); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 await p.goto('/account.php?p=register'); const rej = p.locator('#cc-banner [value=banner_reject_all]'); if (await rej.isVisible().catch(()=>false)) { await rej.click(); await p.waitForLoadState(); }
 const f = p.locator('form:has([name=action][value=register])');
 console.log(JSON.stringify(await f.locator('input,select').evaluateAll(es=>es.map(e=>e.name+':'+e.type))));
 for (const [k,v] of Object.entries({name, email, password:'Zuzana-Passw0rd-2026', password_confirm:'Zuzana-Passw0rd-2026', password_repeat:'Zuzana-Passw0rd-2026'})) { const el=f.locator(`[name=${k}]`); if (await el.count()) await el.fill(v); }
 for (const c of await f.locator('input[type=checkbox]').all()) await c.check(); await p.waitForTimeout(4000);
 await Promise.all([p.waitForLoadState(), f.locator('button').first().click()]);
 console.log(p.url(), (await p.innerText('main')).slice(0,600).replace(/\s+/g,' '));
 await ctx.storageState({path: L.S + '/cust-' + L.INST + '.json'});
 console.log(log.join('\n')); await b.close(); })();
