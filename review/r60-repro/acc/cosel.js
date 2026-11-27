const L = require('./lib.js');
(async () => { const pre = process.argv[2] || ''; const b = await L.chromium.launch(); const p = await b.newPage(); 
 await p.goto(L.BASE + pre + '/product/' + process.argv[3]); await p.locator('form button[type=submit].btn-primary, form button[type=submit]').filter({hasText:/ko[sš][ií]k|cart|Warenkorb|kosár|koszyk/i}).first().click(); await p.waitForTimeout(800);
 await p.goto(L.BASE + pre + '/?page=checkout');
 console.log(JSON.stringify(await p.locator('form:has(.btn-checkout) [name=country] option').evaluateAll(os => os.slice(0, 50).map(o => o.value + '=' + o.textContent.trim()))));
 console.log((await p.locator('.cart-summary').innerText()).replace(/\s+/g, ' '));
 await b.close(); })();
