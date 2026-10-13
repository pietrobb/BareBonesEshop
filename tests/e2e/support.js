const base = require('@playwright/test');
const { expect } = base;

// Global CSP guard (P0 2026-10-13): any Content-Security-Policy violation in any spec fails the test.
// CSP is enforced by the browser, not PHP — a blocked form-action redirect is otherwise silent.
// Violations are reported out of the page immediately, so a document that navigates away still counts.
const CSP_CONSOLE = /content[- ]security[- ]policy|form-action|refused to (send form|load|execute|apply|connect|frame)/i;
const test = base.test.extend({
  page: async ({ page }, use, testInfo) => {
    const violations = [];
    await page.exposeBinding('__bbeCspViolation', (_source, text) => { violations.push(text); });
    await page.addInitScript(() => {
      document.addEventListener('securitypolicyviolation', (event) => {
        window.__bbeCspViolation(`${event.violatedDirective} blocked ${event.blockedURI} on ${event.documentURI}`);
      });
    });
    page.on('console', (message) => { if (CSP_CONSOLE.test(message.text())) violations.push(message.text()); });
    await use(page);
    if (!testInfo.annotations.some(a => a.type === 'csp-violation-expected')) {
      expect(violations, 'Content-Security-Policy violations').toEqual([]);
    }
  },
});
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const statePath = process.env.E2E_STATE_PATH || path.join(process.cwd(), 'tests', 'e2e', '.state.json');
const state = fs.existsSync(statePath) ? JSON.parse(fs.readFileSync(statePath, 'utf8')) : {};

function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/[=\s-]/g, '').toUpperCase()) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.alloc(Math.floor(bits.length / 8));
  for (let i = 0; i < key.length; i++) key[i] = parseInt(bits.slice(i * 8, i * 8 + 8), 2);
  const counter = BigInt(Math.floor(Date.now() / 1000 / 30));
  const message = Buffer.alloc(8); message.writeBigUInt64BE(counter);
  const digest = crypto.createHmac('sha1', key).update(message).digest();
  const offset = digest[19] & 0x0f;
  const value = (digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;
  return String(value).padStart(6, '0');
}

async function adminSignIn(page) {
  await page.goto('/admin.php');
  await page.locator('input[name="admin_username"]').fill(state.admin.username);
  await page.locator('input[name="admin_password"]').fill(state.admin.password);
  await page.locator('input[name="admin_mfa"]').fill(totp(state.admin.secret));
  await page.locator('button[type="submit"]').click();
  await expect(page.locator('nav')).toContainText(state.admin.username);
}

module.exports = { test, expect, state, adminSignIn };
