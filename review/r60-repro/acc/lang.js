const L = require('./lib.js');
(async () => { const [store, ...langs] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage();
 await p.goto('/admin.php?p=stores'); const f = p.locator(`form[action$="action=save&store=${store}"]`);
 for (const l of langs) await f.locator(`input[type=checkbox][name="languages[]"][value=${l}]`).check();
 await Promise.all([p.waitForNavigation(), f.evaluate(x => x.requestSubmit())]); console.log('FLASH', await L.flash(p)); await b.close(); })();
