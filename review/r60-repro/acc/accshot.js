const L = require('./lib.js');
(async () => { const [u, shot, w] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await b.newContext({baseURL:L.BASE, storageState:L.S+'/cust-'+L.INST+'.json', viewport:{width:Number(w||1280),height:900}}); const p = await ctx.newPage();
 await p.goto(u); console.log('OVERFLOW', JSON.stringify(await L.overflow(p))); await p.screenshot({path:L.S+'/shots/'+shot+'.png', fullPage:true});
 console.log((await p.innerText('main')).slice(0, 1200)); await b.close(); })();
