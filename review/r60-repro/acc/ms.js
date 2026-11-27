const L = require('./lib.js');
(async () => { const b = await L.chromium.launch({ args: ['--host-resolver-rules=MAP shop.lipa.test 127.0.0.1'] }); const ctx = await b.newContext(); const p = await ctx.newPage(); const log=[]; L.watch(p,log);
 const B = 'http://127.0.0.1:18911', D = 'http://shop.lipa.test:18911';
 const slug = 'super-helma-samsung-20';
 await p.goto(B + '/'); const rej = p.locator('#cc-banner [value=banner_reject_all]'); if (await rej.isVisible().catch(()=>false)) { await rej.click(); await p.waitForLoadState(); }
 console.log('SWITCHER default:', JSON.stringify(await p.locator('[data-store-switcher] a, .store-switcher a, nav[aria-label*=bchod] a, select[name=store] option').evaluateAll(as => as.map(a => (a.innerText||a.textContent).trim() + ' -> ' + (a.getAttribute('href')||a.value)))));
 const sw = await p.evaluate(() => [...document.querySelectorAll('a')].filter(a => /\/at\/|shop\.lipa/.test(a.href)).map(a => a.innerText.trim() + ' -> ' + a.href + ' [' + (a.closest('[class]')||{}).className + ']'));
 console.log('LINKS to other stores:', JSON.stringify(sw));
 await p.goto(B + '/product/' + slug); await p.locator('form button[type=submit]').filter({hasText:/košík/i}).first().click(); await p.waitForTimeout(800);
 await p.goto(B + '/?page=cart'); console.log('DEFAULT CART:', (await p.innerText('main')).replace(/\s+/g,' ').slice(0,200));
 await p.goto(B + '/at/?page=cart'); console.log('AT CART:', (await p.innerText('main')).replace(/\s+/g,' ').slice(0,200), 'lang', await p.getAttribute('html','lang'));
 const rej2 = p.locator('#cc-banner'); console.log('AT cookie banner visible:', await rej2.isVisible().catch(()=>false));
 await p.goto(D + '/?page=cart'); console.log('DE CART:', (await p.innerText('main')).replace(/\s+/g,' ').slice(0,200));
 console.log('DE cookie banner visible:', await p.locator('#cc-banner').isVisible().catch(()=>false));
 console.log('COOKIES', JSON.stringify((await ctx.cookies()).map(c => [c.domain, c.path, c.name, c.httpOnly, c.sameSite])));
 await p.goto(D + '/'); const sw2 = await p.evaluate(() => [...document.querySelectorAll('a')].filter(a => /127\.0\.0\.1|\/at\//.test(a.href)).map(a => a.innerText.trim().replace(/\s+/g,' ') + ' -> ' + a.href));
 console.log('DE links to other stores:', JSON.stringify(sw2));
 await p.screenshot({ path: L.S + '/shots/sk-store-de-domain.png' });
 // admin on store domain?
 const r = await p.goto(D + '/admin.php'); console.log('admin on DE domain:', r.status(), p.url(), (await p.innerText('body')).slice(0,100).replace(/\s+/g,' '));
 console.log(log.join('\n')); await b.close(); })();
