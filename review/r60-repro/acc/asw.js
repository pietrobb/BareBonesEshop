// INST=sk node asw.js store page
const L = require('./lib.js');
(async () => { const [store, page] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 await p.goto('/admin.php?p=orders'); const f = p.locator('form:has([name=admin_switch_store])').first();
 console.log('switch options', JSON.stringify(await f.locator('[name=store] option').evaluateAll(os => os.map(o => o.value + '=' + o.textContent.trim()))));
 await f.locator('[name=store]').selectOption(store); await Promise.all([p.waitForNavigation(), f.evaluate(x => x.requestSubmit())]);
 await p.goto('/admin.php?p=' + page); console.log('lang', await p.getAttribute('html','lang')); console.log((await p.innerText('main')).slice(0, Number(process.env.LEN || 2500)));
 console.log(log.join('\n')); await b.close(); })();
