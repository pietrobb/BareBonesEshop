const L = require('./lib.js');
(async () => { const b = await L.chromium.launch(); const p = await b.newPage(); const log=[]; L.watch(p,log);
 for (const pg of ['privacy','imprint','terms','withdrawal','cookies','accessibility','complaint']) { await p.goto(L.BASE + '/?page=' + pg); const t = await p.innerText('main').catch(()=>p.innerText('body'));
  console.log(`== ${pg} len=${t.length} raw=${JSON.stringify(await L.rawKeys(p))} placeholders=${JSON.stringify((t.match(/\{[a-z_]+\}|\[[A-Z_ ]+\]|TODO|Lorem/g)||[]).slice(0,5))}`);
  if (process.env.SHOW && process.env.SHOW.split(',').includes(pg)) console.log(t.slice(0, Number(process.env.LEN||3000))); }
 console.log(log.join('\n')); await b.close(); })();
