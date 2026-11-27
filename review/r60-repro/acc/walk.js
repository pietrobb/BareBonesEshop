// INST=xx PRE=/at LANG=en node walk.js  -> storefront walk at 390 and 1280
const L = require('./lib.js');
(async () => { const pre = process.env.PRE || ''; const lq = process.env.LANG2 ? 'lang=' + process.env.LANG2 : ''; const host = process.env.HOSTB || L.BASE;
 const b = await L.chromium.launch({ args: ['--host-resolver-rules=MAP shop.lipa.test 127.0.0.1'] });
 const slug = L.sql(`select slug from products where status='active' order by id limit 1`).trim().split('\n')[1];
 const cat = L.sql(`select slug from categories limit 1`).trim().split('\n')[1];
 const urls = ['/', '/category/' + cat, '/product/' + slug, '/?page=search&q=a', '/?page=cart', '/?page=checkout', '/account.php?p=login', '/account.php?p=register', '/?page=terms', '/?page=privacy', '/?page=imprint', '/?page=withdrawal', '/?page=complaint', '/?page=order-status', '/?page=cookies', '/wishlist.php', '/nonexistent-xyz'];
 for (const w of [390, 1280]) { const ctx = await b.newContext({ viewport: { width: w, height: 844 } }); const p = await ctx.newPage(); const log = []; L.watch(p, log);
  await p.goto(host + pre + '/' + (lq ? '?' + lq : '')); const rej = p.locator('#cc-banner [value=banner_reject_all]'); if (await rej.isVisible().catch(()=>false)) { await rej.click(); await p.waitForLoadState(); }
  await p.goto(host + pre + '/product/' + slug + (lq ? '?' + lq : '')); await p.locator('form button[type=submit]').filter({hasText:/ko[sš][ií]k|cart|Warenkorb|kosár|koszyk/i}).first().click().catch(e=>log.push('ADD FAIL '+e.message.slice(0,80))); await p.waitForTimeout(700);
  for (const u of urls) { const full = host + pre + u + (lq ? (u.includes('?') ? '&' : '?') + lq : ''); const r = await p.goto(full); const st = r ? r.status() : 0;
    const ov = await L.overflow(p); const rk = await L.rawKeys(p); const lang = await p.getAttribute('html', 'lang');
    const t = await p.evaluate(() => document.body.innerText); const eng = /\b(Add to cart|Checkout|Your cart|Sign in|Required)\b/.test(t) && !/^en/.test(lang);
    if (st >= 400 || ov.length || rk.length || eng || (process.env.LANG2 && !lang.startsWith(process.env.LANG2.slice(0,2)))) console.log(`[${w}] ${u} st=${st} lang=${lang} ov=${JSON.stringify(ov)} raw=${JSON.stringify(rk)} eng=${eng}`);
    if (process.env.SHOT && u === process.env.SHOT && w === 390) await p.screenshot({ path: L.S + '/shots/' + L.INST + (process.env.LANG2||'') + '-' + u.replace(/\W+/g, '_') + '-390.png', fullPage: true }); }
  console.log(`[${w}] log:\n` + [...new Set(log)].filter(x => !/nonexistent-xyz|http 429/.test(x)).join('\n')); await ctx.close(); }
 await b.close(); })();
