// INST=sk node dl.js statefile id...
const L = require('./lib.js');
(async () => { const [st, ...ids] = process.argv.slice(2); const b = await L.chromium.launch(); const ctx = await b.newContext({baseURL:L.BASE, storageState:st});
 for (const id of ids) { const r = await ctx.request.get('/account.php?p=invoice_pdf&id=' + id, {maxRedirects:0}); const body = await r.body(); console.log(id, r.status(), r.headers()['content-type'], r.headers()['content-disposition']||'', r.headers()['location']||'', body.length, body.slice(0,5).toString()); }
 await b.close(); })();
