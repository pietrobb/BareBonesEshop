// INST=xx node coval.js '{"name":"..."}'  -> submit checkout with overrides, print errors
const L = require('./lib.js');
(async () => { const ov = JSON.parse(process.argv[2] || '{}'); const b = await L.chromium.launch(); const ctx = await b.newContext({baseURL:L.BASE}); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 const slug = L.sql(`select slug from products where status='active' order by price limit 1`).trim().split('\n')[1];
 await p.goto('/product/' + slug); const rej = p.locator('#cc-banner [value=banner_reject_all]'); if (await rej.isVisible().catch(()=>false)) { await rej.click(); await p.waitForLoadState(); }
 await p.locator('form button[type=submit]').filter({hasText:/ko[sš][ií]k|cart|Warenkorb|kosár|koszyk/i}).first().click(); await p.waitForTimeout(800);
 await p.goto('/?page=checkout'); const f = p.locator('form:has(.btn-checkout)');
 const cc = { sk: 'SK', cs: 'CZ', hu: 'HU', pl: 'PL' }[L.INST]; const zip = { sk: '81101', cs: '60200', hu: '1011', pl: '30-001' }[L.INST];
 const base = { name: 'Jana Test', email: 'jana@example.com', phone: '+421905123456', street: 'Ulica 5', city: 'Mesto', zip };
 const vals = Object.assign(base, ov);
 for (const [k, v] of Object.entries(vals)) { const el = f.locator(`[name=${k}]`); if (await el.count()) await el.evaluate((e, v) => { e.value = v; e.dispatchEvent(new Event('input', {bubbles:true})); }, v); }
 await f.evaluate(form => { form.noValidate = true; });
 const ship = f.locator('input[name=shipping_method]').first(); await ship.check(); await p.waitForTimeout(700);
 const pay = f.locator('input[name=payment][value=bank_transfer]'); if (await pay.count()) await pay.check(); await p.waitForTimeout(500);
 for (const c of await p.locator('.checkout-consents input[type=checkbox]').all()) await c.check();
 await Promise.all([p.waitForLoadState(), p.locator('.btn-checkout').click()]); await p.waitForTimeout(800);
 console.log('URL', p.url().replace(/confirmation_token=.*/, ''));
 const errs = await p.locator('.field-error, .error, [role=alert], .alert, .invalid-feedback, [id$=-error]').allInnerTexts();
 console.log('ERRORS', JSON.stringify([...new Set(errs.map(e=>e.trim()).filter(Boolean))]));
 if (p.url().includes('order-done')) console.log('DB', L.sql("select id,customer_name,hex(customer_name) h from orders order by id desc limit 1"));
 console.log(log.filter(x=>!/http 4/.test(x)).join('\n')); await b.close(); })();
