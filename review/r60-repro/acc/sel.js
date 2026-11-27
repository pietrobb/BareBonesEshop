const L = require('./lib.js');
(async () => { const [u, sel] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await L.adminContext(b); const p = await ctx.newPage();
 await p.goto('/' + u); console.log(JSON.stringify(await p.locator(sel).evaluateAll(es => es.map(e => e.tagName === 'OPTION' ? e.value + '=' + e.textContent.trim() : (e.innerText || e.textContent).trim().replace(/\s+/g,' ').slice(0,120))))); await b.close(); })();
