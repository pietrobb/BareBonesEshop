const L = require('./lib.js');
(async () => { const [u] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await b.newContext({baseURL:L.BASE, storageState:L.S+'/cust-'+L.INST+'.json'}); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 await p.goto(u); console.log((await p.innerText('main')).slice(0, Number(process.env.LEN||2500)));
 console.log(JSON.stringify(await p.locator('main a').evaluateAll(as=>as.map(a=>a.innerText.trim()+' -> '+a.getAttribute('href')).filter(x=>/invoice|pdf|CN|download|faktúr|dobropis/i.test(x)))));
 console.log(log.join('\n')); await b.close(); })();
