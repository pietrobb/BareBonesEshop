// INST=sk node rawpost.js page actionPart id '{"name":"..."}' -> POST whole form data with overrides via request (raw values)
const L = require('./lib.js');
(async () => { const [u, act, id, fj] = process.argv.slice(2); const vals = JSON.parse(fj); const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage();
 await p.goto('/' + u);
 const r = await p.evaluate(async ([act, id, vals]) => { const f = [...document.forms].find(f => (f.getAttribute('action')||'').includes(act) && f.elements.namedItem('id') && f.elements.namedItem('id').value === id);
   if (!f) return 'NO FORM'; const fd = new FormData(f); for (const [k, v] of Object.entries(vals)) fd.set(k, v);
   const res = await fetch(f.action, {method:'POST', body: new URLSearchParams(fd), redirect:'follow'}); const t = await res.text(); const d = new DOMParser().parseFromString(t, 'text/html');
   return [...d.querySelectorAll('.flash, .alert, [role=alert], [role=status]')].map(e=>e.textContent.trim()).join(' | ').slice(0, 300); }, [act, id, vals]);
 console.log('FLASH', r); await b.close(); })();
