const { chromium } = require('playwright');
const crypto = require('node:crypto'); const fs = require('node:fs');
const S = '/tmp/claude-0/-home-user-BareBonesEshop/0d748840-4251-5787-841e-a11f82aad9b2/scratchpad/r60/acc';
const INST = process.env.INST || 'sk';
const PORTS = { sk: 18631, cs: 18632, hu: 18633, de: 18634 };
const BASE = 'http://127.0.0.1:' + PORTS[INST];
const APP = S + '/app-' + INST; const PRIV = S + '/priv-' + INST + '/bareboneseshop-private';
function secret() { return fs.readFileSync(S + '/secret-' + INST, 'utf8').trim(); }
function totp(secret, step) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
  for (const c of secret.replace(/[=\s-]/g, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.alloc(Math.floor(bits.length / 8));
  for (let i = 0; i < key.length; i++) key[i] = parseInt(bits.slice(i * 8, i * 8 + 8), 2);
  const msg = Buffer.alloc(8); msg.writeBigUInt64BE(BigInt(step));
  const d = crypto.createHmac('sha1', key).update(msg).digest(); const o = d[19] & 15;
  return String((d.readUInt32BE(o) & 0x7fffffff) % 1e6).padStart(6, '0');
}
async function freshStep() {
  const f = S + '/totp-step-' + INST; const last = fs.existsSync(f) ? Number(fs.readFileSync(f, 'utf8')) : -1;
  const wait = (last + 1) * 30000 - Date.now(); if (wait > 0) await new Promise(r => setTimeout(r, wait + 300));
  const step = Math.floor(Date.now() / 30000); fs.writeFileSync(f, String(step)); return step;
}
const ADMIN_STATE = S + '/admin-state-' + INST + '.json';
async function adminContext(browser, opts = {}) {
  const base = opts.baseURL || BASE;
  if (fs.existsSync(ADMIN_STATE)) {
    const ctx = await browser.newContext({ baseURL: base, storageState: ADMIN_STATE, ...opts });
    const p = await ctx.newPage(); await p.goto('/admin.php');
    if (await p.locator('input[name="admin_username"]').count() === 0) { await p.close(); return ctx; }
    await ctx.close();
  }
  const ctx = await browser.newContext({ baseURL: base, ...opts }); const p = await ctx.newPage();
  await p.goto('/admin.php');
  await p.fill('input[name="admin_username"]', 'owner'); await p.fill('input[name="admin_password"]', 'OwnerPassw0rd!2026');
  await p.fill('input[name="admin_mfa"]', totp(secret(), await freshStep()));
  await p.click('button[type="submit"]'); await p.waitForLoadState();
  if (await p.locator('input[name="admin_username"]').count()) throw new Error('admin login failed: ' + (await p.innerText('body')).slice(0, 500));
  await ctx.storageState({ path: ADMIN_STATE }); await p.close(); return ctx;
}
function watch(page, log) {
  page.on('console', m => { if ((m.type() === 'error' || m.type() === 'warning') && !/Failed to load resource: the server responded with a status of 404/.test(m.text())) log.push(`[console.${m.type()}] ${page.url()} ${m.text()}`); });
  page.on('pageerror', e => log.push(`[pageerror] ${page.url()} ${e.message}`));
  page.on('response', r => { if (r.status() >= 400 && !/\.(jpe?g|png|webp)(\?|$)/.test(r.url())) log.push(`[http ${r.status()}] ${r.url()}`); });
}
async function overflow(page) {
  return page.evaluate(() => {
    const w = document.documentElement.clientWidth; const out = [];
    if (document.documentElement.scrollWidth > w + 1) out.push('PAGE scrollWidth ' + document.documentElement.scrollWidth + ' > ' + w);
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect(); if (r.width === 0 || r.height === 0) continue;
      const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || cs.position === 'fixed') continue;
      let p = el.parentElement, clipped = false; while (p) { const pc = getComputedStyle(p); if (/(auto|scroll|hidden|clip)/.test(pc.overflowX)) { clipped = true; break; } p = p.parentElement; }
      if (!clipped && r.right > w + 1) out.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.className && typeof el.className === 'string' ? '.' + el.className.split(' ').slice(0, 2).join('.') : ''} right=${Math.round(r.right)} "${(el.textContent || '').trim().slice(0, 40)}"`);
    }
    return out.slice(0, 8);
  });
}
async function rawKeys(page) {
  const t = await page.evaluate(() => document.body.innerText);
  return [...new Set((t.match(/\b[a-z][a-z0-9_]+(?:\.[a-z0-9_]+){1,}\b/g) || []).filter(k => /_/.test(k) || k.split('.').length > 2).filter(k => !/\.(sk|cz|com|hu|pl|de|at|php|html|js|css|pdf|png|jpg|test|example|json|xml|txt|sqlite)$/.test(k) && !/^\d/.test(k) && !k.includes('@')))].slice(0, 10);
}
function sh(cmd) { return require('node:child_process').execSync(cmd, { encoding: 'utf8', env: { ...process.env, TMPDIR: S + '/tmp' } }); }
function php(code) { const f = S + '/tmp/snippet-' + INST + '.php'; fs.writeFileSync(f, '<?php\nchdir("' + APP + '");\nrequire "' + APP + '/src/autoload.php";\n' + code); return sh(`php ${f}`); }
function sql(...q) { return sh(`DB=${PRIV}/db/shop.sqlite php ${S}/scripts/q.php ` + q.map(x=>JSON.stringify(x)).join(" ")); }
async function flash(p) { return (await p.locator('.flash, .alert, [role=status], [role=alert], .notice, .error, .errors').allInnerTexts()).join(' | ').replace(/\s+/g,' ').slice(0,600); }
module.exports = { APP, PRIV, chromium, BASE, S, INST, adminContext, watch, overflow, rawKeys, sh, sql, php, fs, totp, flash };
