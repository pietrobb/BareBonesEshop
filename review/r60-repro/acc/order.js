// node order.js '{"prefix":"","items":[["/product/x",variantIndex|null,qty]],"ship":"gls","pay":"cod","country":"SK","email":"a@b.sk","name":"Jana Kováčová","coupon":"","company":{},"state":"file","shot":"x","lang":""}'
const L = require('./lib.js');
(async () => { const o = JSON.parse(process.argv[2]); const pre = o.prefix || ''; const b = await L.chromium.launch({ args: ['--host-resolver-rules=MAP shop.lipa.test 127.0.0.1'] }); 
 const ctx = await b.newContext({baseURL:(o.base||L.BASE), ...(o.state && L.fs.existsSync(o.state) ? {storageState:o.state} : {}), ...(o.viewport?{viewport:o.viewport}:{})}); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 for (const [url, vi, qty] of o.items) { await p.goto(pre + url + (o.lang?('?lang='+o.lang):'')); const rej = p.locator('#cc-banner [value=banner_reject_all]'); if (await rej.isVisible().catch(()=>false)) { await rej.click(); await p.waitForLoadState(); }
   if (vi !== null && vi !== undefined) await p.locator('input[type=radio][name^=variant]').nth(vi).check();
   if (qty && qty>1) await p.locator('input[name=quantity]').first().fill(String(qty));
   await p.locator('form button[type=submit]').filter({hasText: /košík|košíku|cart|Warenkorb|kosár|koszyk|košík/i}).first().click(); await p.waitForTimeout(900); }
 await p.goto(pre + '/?page=checkout' + (o.lang?('&lang='+o.lang):''));
 const f = p.locator('form:has(.btn-checkout)');
 console.log('PRESELECT country=', await f.locator('[name=country]').inputValue().catch(()=>'?'), 'html lang=', await p.getAttribute('html','lang'));
 console.log('LEGAL', (await p.locator('[data-checkout-legal]').innerText().catch(()=>'MISSING')).replace(/\s+/g,' '), JSON.stringify(await p.locator('[data-checkout-legal] a').evaluateAll(a=>a.map(x=>x.getAttribute('href')))));
 console.log('LEGAL-before-button', await p.evaluate(()=>{const l=document.querySelector('[data-checkout-legal]'), b=document.querySelector('.btn-checkout'); if(!l||!b) return 'n/a'; return !!(l.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) && l.getBoundingClientRect().bottom <= b.getBoundingClientRect().top + 2;}));
 if (!o.loggedIn) { await f.locator('[name=name]').fill(o.name||'Jana Kováčová'); await f.locator('[name=email]').fill(o.email); }
 await f.locator('[name=phone]').fill(o.phone||'+421905123456').catch(()=>{});
 await f.locator('[name=street]').fill(o.street||'Hlavná 5'); await f.locator('[name=city]').fill(o.city||'Košice'); await f.locator('[name=zip]').fill(o.zip||'04001');
 if (o.country) { await f.locator('[name=country]').selectOption(o.country); await p.waitForTimeout(1200); }
 for (const [k,v] of Object.entries(o.company||{})) await f.locator(`[name=${k}]`).fill(v);
 if (o.company && o.company.vat_id) { await f.locator('[name=vat_id]').blur(); await p.waitForTimeout(1500); }
 if (o.coupon) { await f.locator('[name=coupon_code]').fill(o.coupon); await Promise.all([p.waitForLoadState(), f.locator('[name=coupon_action]').click()]); await p.waitForTimeout(800); console.log('after coupon:', await L.flash(p)); }
 console.log('SHIP OPTS', JSON.stringify(await p.locator('input[name=shipping_method]').evaluateAll(es=>es.map(e=>e.value))), 'PAY OPTS', JSON.stringify(await p.locator('input[name=payment]').evaluateAll(es=>es.map(e=>e.value))));
 await p.locator(`input[name=shipping_method][value=${o.ship}]`).check(); await p.waitForTimeout(900);
 const pp = p.locator('[name=pickup_point], #checkout-pickup-point'); if (await pp.count() && await pp.first().isVisible()) { console.log('pickup field', await pp.first().evaluate(e=>e.outerHTML.slice(0,300))); }
 await p.locator(`input[name=payment][value=${o.pay}]`).check(); await p.waitForTimeout(900);
 for (const c of await p.locator('.checkout-consents input[type=checkbox]').all()) await c.check();
 const summary = (await p.locator('.cart-summary').innerText()).replace(/\s+/g,' '); console.log('SUMMARY', summary);
 console.log('SHIP/PAY labels', JSON.stringify(await p.locator('.shipping-option, .payment-option').evaluateAll(es=>es.map(e=>e.innerText.replace(/\s+/g,' ').trim()))));
 const shownTotal = await p.locator('#checkout-total').inputValue().catch(()=>null); console.log('SHOWN total_cents', shownTotal, 'summary-total', await p.locator('#summary-total').innerText());
 if (o.overflow) console.log('OVERFLOW', JSON.stringify(await L.overflow(p)), 'RAWKEYS', JSON.stringify(await L.rawKeys(p)));
 if (o.shot) await p.screenshot({path:L.S+'/shots/'+o.shot+'.png', fullPage:true});
 if (o.dry) { await b.close(); return; }
 await Promise.all([p.waitForLoadState(), p.locator('.btn-checkout').click()]); await p.waitForTimeout(1500);
 console.log('AFTER SUBMIT URL', p.url());
 if (p.url().includes('18752/pay/')) { await p.click('#success'); await p.waitForLoadState(); await p.waitForTimeout(1500); console.log('after pay URL', p.url()); }
 console.log((await p.innerText('main').catch(()=>p.innerText('body'))).slice(0, o.len||2500));
 try { console.log('DB LAST ORDER', L.sh(`DB=${L.PRIV}/db/shop.sqlite php ${L.S}/scripts/q.php "select id,order_number,store_id,locale,total,currency,shipping_method,payment_method,vat_scenario,status from orders order by id desc limit 1"`)); } catch(e) { console.log('db err', e.message); }
 if (o.saveState) await ctx.storageState({path:o.saveState});
 console.log(log.join('\n')); await b.close(); })();
