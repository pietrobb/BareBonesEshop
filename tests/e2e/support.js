const base = require('@playwright/test');
const { expect } = base;

// Global CSP guard (P0 2026-10-13): any Content-Security-Policy violation in any spec fails the test.
// CSP is enforced by the browser, not PHP — a blocked form-action redirect is otherwise silent.
// Violations are reported out of the page immediately, so a document that navigates away still counts.
const CSP_CONSOLE = /content[- ]security[- ]policy|form-action|refused to (send form|load|execute|apply|connect|frame)/i;
// Only the shop's own CSP is ours to guard, so a violation counts by the ORIGIN OF THE DOCUMENT it
// happened on, never by what was blocked: the 1.9.0 bug is `form-action` on the shop's checkout
// document blocking the 302 to checkout.stripe.com (blockedURI = Stripe, documentURI = shop).
// A hosted gateway page enforces its own policy; its violations say nothing about the shop.
const test = base.test.extend({
  cspViolations: [async ({ page }, use, testInfo) => {
    const shopOrigin = new URL(testInfo.project.use.baseURL || process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').origin;
    const onShop = (url) => { try { return new URL(url).origin === shopOrigin; } catch { return false; } };
    const violations = [];
    // Seen on other origins' documents; kept (non-enumerable) so the regression can prove the filter.
    Object.defineProperty(violations, 'ignored', { value: [] });
    await page.exposeBinding('__bbeCspViolation', ({ frame }, text, documentURI) => {
      (onShop(documentURI || frame.url()) ? violations : violations.ignored).push(text);
    });
    await page.addInitScript(() => {
      document.addEventListener('securitypolicyviolation', (event) => {
        window.__bbeCspViolation(`${event.violatedDirective} blocked ${event.blockedURI} on ${event.documentURI}`, event.documentURI);
      });
    });
    // Console fallback for engines that skip the event: attributed to the message's document, or the
    // page that was showing when it was logged (a blocked navigation leaves the shop page in place).
    page.on('console', (message) => {
      if (CSP_CONSOLE.test(message.text())) (onShop(message.location().url || page.url()) ? violations : violations.ignored).push(message.text());
    });
    await use(violations);
    if (!testInfo.annotations.some(a => a.type === 'csp-violation-expected')) {
      expect(violations, 'Content-Security-Policy violations on shop documents').toEqual([]);
    }
  }, { auto: true }],
});
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const statePath = process.env.E2E_STATE_PATH || path.join(process.cwd(), 'tests', 'e2e', '.state.json');
const state = fs.existsSync(statePath) ? JSON.parse(fs.readFileSync(statePath, 'utf8')) : {};

// The server accepts each TOTP time step once (replay protection), so every sign-in needs a step newer than
// the last one used. The last step is kept on disk because Playwright restarts the worker after a failure.
const totpStepPath = `${statePath}.totp-step`;

async function freshTotpStep() {
  const last = fs.existsSync(totpStepPath) ? Number(fs.readFileSync(totpStepPath, 'utf8')) : -1;
  const waitMs = (last + 1) * 30_000 - Date.now();
  if (waitMs > 0) {
    test.info().setTimeout(test.info().timeout + waitMs + 1_000);
    await new Promise((resolve) => setTimeout(resolve, waitMs + 250));
  }
  const step = Math.floor(Date.now() / 30_000);
  fs.writeFileSync(totpStepPath, String(step));
  return step;
}

function totp(secret, step) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/[=\s-]/g, '').toUpperCase()) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.alloc(Math.floor(bits.length / 8));
  for (let i = 0; i < key.length; i++) key[i] = parseInt(bits.slice(i * 8, i * 8 + 8), 2);
  const counter = BigInt(step);
  const message = Buffer.alloc(8); message.writeBigUInt64BE(counter);
  const digest = crypto.createHmac('sha1', key).update(message).digest();
  const offset = digest[19] & 0x0f;
  const value = (digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;
  return String(value).padStart(6, '0');
}

async function adminSignIn(page, account = state.admin) {
  await page.goto('/admin.php');
  await page.locator('input[name="admin_username"]').fill(account.username);
  await page.locator('input[name="admin_password"]').fill(account.password);
  await page.locator('input[name="admin_mfa"]').fill(totp(account.secret, await freshTotpStep()));
  await page.locator('button[type="submit"]').click();
  await expect(page.locator('nav')).toContainText(account.username);
}

module.exports = { test, expect, state, adminSignIn };
