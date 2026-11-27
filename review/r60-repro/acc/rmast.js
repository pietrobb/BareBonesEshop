// INST=sk node rmast.js rmaId buttonText  -> click button in RMA row
const L = require('./lib.js');
(async () => { const [id, txt] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 await p.goto('/admin.php?p=rma'); const f = p.locator(`form:has(input[name=rma_id][value="${id}"])`).filter({has: p.getByRole('button', {name: txt})}).first();
 await Promise.all([p.waitForLoadState(), f.getByRole('button', {name: txt}).click()]);
 console.log('FLASH', await L.flash(p)); console.log((await p.innerText('main')).slice(0, 1500));
 if (process.env.SHOT) await p.screenshot({path: L.S + '/shots/' + process.env.SHOT + '.png', fullPage: true});
 console.log(log.join('\n')); await b.close(); })();
