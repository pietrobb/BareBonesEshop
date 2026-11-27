// INST=sk node fid.js page actionPart id '{"field":"v"}'  -> form whose action contains actionPart and elements id == id
const L = require('./lib.js');
(async () => { const [u, act, id, fj] = process.argv.slice(2); const vals = JSON.parse(fj); const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 await p.goto('/' + u);
 const idx = await p.evaluate(([act, id]) => [...document.forms].findIndex(f => (f.getAttribute('action')||'').includes(act) && (id === '' ? !(f.elements.namedItem('id')) || f.elements.namedItem('id').value === '' || f.elements.namedItem('id').value === '0' : f.elements.namedItem('id') && f.elements.namedItem('id').value === id)), [act, id]);
 if (idx < 0) { console.log('NO FORM'); await b.close(); return; }
 const before = await p.evaluate(i => Object.fromEntries([...document.forms[i].elements].filter(e=>e.name).map(e=>[e.name, e.type==='checkbox'?e.checked:e.value])), idx);
 await p.evaluate(([i, vals]) => { const f = document.forms[i]; for (const [k, v] of Object.entries(vals)) { const el = f.elements.namedItem(k); if (!el) { console.error('NO FIELD ' + k); continue; } if (el.type === 'checkbox') el.checked = !!v; else el.value = String(v); } }, [idx, vals]);
 await Promise.all([p.waitForNavigation(), p.evaluate(i => { const f = document.forms[i]; f.noValidate = true; f.requestSubmit(); }, idx)]);
 console.log('FLASH', await L.flash(p));
 if (process.env.BEFORE) console.log('BEFORE', JSON.stringify(before));
 console.log(log.join('\n')); await b.close(); })();
