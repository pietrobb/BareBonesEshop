// INST=xx node dumpa.js 'admin.php?p=shipping' [shotname] [w]
const L = require('./lib.js');
(async () => { const [u, shot, w] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await L.adminContext(b, w ? {viewport:{width:Number(w),height:900}} : {}); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 const t0 = Date.now(); await p.goto('/' + u); const dt = Date.now() - t0;
 console.log('URL', p.url(), 'ms', dt, 'lang', await p.getAttribute('html','lang'));
 console.log((await p.innerText('main').catch(()=>p.innerText('body'))).slice(0, Number(process.env.LEN||5000)));
 if (process.env.FORMS) console.log(JSON.stringify(await p.locator('form').evaluateAll(fs=>fs.map(f=>[f.getAttribute('action'), [...f.elements].filter(e=>e.name).map(e=>e.name+'('+e.type+')='+String(e.type==='checkbox'?e.checked:e.value).slice(0,40))].join(' ')))).replace(/\],/g,'],\n'));
 console.log('OVERFLOW', JSON.stringify(await L.overflow(p)), 'RAW', JSON.stringify(await L.rawKeys(p)));
 if (shot) await p.screenshot({path: L.S + '/shots/' + shot + '.png', fullPage: true});
 console.log(log.join('\n')); await b.close(); })();
