// INST=xx node install.js lang jsonfields
const L = require('./lib.js');
(async () => { const [lang, fj] = process.argv.slice(2); const b = await L.chromium.launch(); const p = await b.newPage(); const log=[]; L.watch(p,log);
 await p.goto(L.BASE + '/install.php?lang=' + lang);
 const pre = await p.locator('input,select').evaluateAll(es=>es.filter(e=>e.type!=='hidden'&&e.type!=='password').map(e=>e.name+'='+e.value).join(' | '));
 console.log('PRESETS: ' + pre);
 const key = L.fs.readFileSync(L.APP + '/install-key.php', 'utf8').match(/[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}/)[0];
 const f = Object.assign({ install_key: key, private_parent: L.S + '/priv-' + L.INST, admin_user: 'owner', admin_password: 'OwnerPassw0rd!2026', admin_password_repeat: 'OwnerPassw0rd!2026', smtp_host: '127.0.0.1', smtp_port: '18639', smtp_encryption: 'none' }, JSON.parse(fj));
 for (const [k, v] of Object.entries(f)) { const el = p.locator(`[name=${k}]`); if (await el.evaluate(e=>e.tagName)==='SELECT') await el.selectOption(v); else await el.fill(v); }
 await p.click('button[type=submit]'); await p.waitForLoadState();
 L.fs.writeFileSync(L.S + '/install-' + L.INST + '.html', await p.content());
 const body = await p.innerText('body'); const m = body.match(/\b[A-Z2-7]{32}\b/); if (m) L.fs.writeFileSync(L.S + '/secret-' + L.INST, m[0]);
 console.log(await p.title()); console.log(body.slice(0, 1500)); console.log(log.join('\n')); await b.close(); })();
