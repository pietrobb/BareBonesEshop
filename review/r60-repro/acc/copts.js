// INST=xx node copts.js -> checkout country options
const L = require('./lib.js');
(async () => { const b = await L.chromium.launch(); const p = await b.newPage(); const slug = L.sql("select slug from products where status='active' and slug not like '%ebook%' limit 1").trim().split('\n')[1];
 await p.goto(L.BASE + '/product/' + slug); await p.locator('form.cart-add-form button[type=submit]').first().click(); await p.waitForTimeout(800);
 await p.goto(L.BASE + '/?page=checkout'); console.log(JSON.stringify(await p.locator('[name=country] option').evaluateAll(o=>o.map(x=>x.value)))); await b.close(); })();
