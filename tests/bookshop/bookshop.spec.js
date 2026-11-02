// Bookshop package acceptance (#240): a freshly unpacked bareboneseshop-bookshop.zip on Apache + PHP (no shell),
// driven only through the browser: the web installer, three purchases (eBook, paperback cash on delivery,
// hardcover bank transfer), the owner handling them in the administration, and the eBook download.
// Run by tests/bookshop/run.sh against a php:8.x-apache container; screenshots go to BOOKSHOP_SHOTS.
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

const SHOTS = process.env.BOOKSHOP_SHOTS || path.join(process.cwd(), 'test-results', 'bookshop-screenshots');
const PRIVATE_PARENT = process.env.BOOKSHOP_PRIVATE_PARENT || '/srv/bookshop-data';
const OWNER = { username: 'owner', password: 'correct horse battery staple' };
const CUSTOMER = { name: 'Ada Reader', email: `ada.${Date.now()}@books.test`, password: 'reader-pass-2026' };
const orders = {};

test.describe.configure({ mode: 'serial' });

async function shot(page, name) {
  fs.mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true });
}

function totp(secret, step) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/[=\s-]/g, '').toUpperCase()) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.alloc(Math.floor(bits.length / 8));
  for (let i = 0; i < key.length; i++) key[i] = parseInt(bits.slice(i * 8, i * 8 + 8), 2);
  const message = Buffer.alloc(8);
  message.writeBigUInt64BE(BigInt(step));
  const digest = crypto.createHmac('sha1', key).update(message).digest();
  const offset = digest[19] & 0x0f;
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

async function dismissCookies(page) {
  const reject = page.locator('#cc-reject');
  if (await reject.isVisible()) await reject.click();
}

async function addFormat(page, title, format) {
  await page.goto('/?page=category&slug=classics');
  await dismissCookies(page);
  await page.locator('.book-card__link', { hasText: title }).first().click();
  await expect(page.locator('h1')).toContainText(title);
  await page.locator('.bk-format', { hasText: format }).click();
  await page.locator('[data-storefront-action="cart-add"]').click();
  await expect(page.locator('.cart-badge')).toHaveText(/^[1-9]/);
}

// Checkout with the given payment method; physical carts try the shipping methods until that payment is offered.
async function checkout(page, payment, shotName) {
  await page.goto('/?page=checkout');
  await page.locator('input[name="name"]').fill(CUSTOMER.name);
  await page.locator('input[name="email"]').fill(CUSTOMER.email);
  for (const [field, value] of [['phone', '+421900000240'], ['street', 'Library Lane 7'], ['city', 'Bratislava'], ['zip', '81101']]) {
    const input = page.locator(`input[name="${field}"]`);
    if (await input.isVisible()) await input.fill(value);
  }
  const method = page.locator(`input[name="payment"][value="${payment}"]`);
  const shipping = page.locator('input[name="shipping_method"]');
  for (let i = 0; i < await shipping.count() && !(await method.isVisible()); i++) {
    await shipping.nth(i).check();
    await page.waitForTimeout(500);
  }
  await expect(method).toBeVisible();
  await method.check();
  for (const consent of await page.locator('.checkout-consents input[required]').all()) await consent.check();
  await shot(page, `${shotName}-checkout`);
  await page.locator('form.checkout-form button.btn-checkout').click();
  await expect(page).toHaveURL(/page=order-done/);
  const number = (await page.locator('.order-done > p strong').first().textContent()).trim();
  expect(number).not.toBe('');
  await shot(page, `${shotName}-done`);
  return number;
}

async function adminSignIn(page, secret) {
  // Each TOTP step is accepted once: wait for a step the server has not seen.
  const step = Math.floor(Date.now() / 30_000);
  await page.waitForTimeout((step + 1) * 30_000 - Date.now() + 500);
  await page.goto('/admin.php');
  await page.locator('input[name="admin_username"]').fill(OWNER.username);
  await page.locator('input[name="admin_password"]').fill(OWNER.password);
  await page.locator('input[name="admin_mfa"]').fill(totp(secret, Math.floor(Date.now() / 30_000)));
  await page.locator('button[type="submit"]').click();
  await expect(page.locator('nav')).toContainText(OWNER.username);
}

async function openAdminOrder(page, number) {
  await page.goto('/admin.php?p=orders');
  await page.getByRole('link', { name: number, exact: true }).first().click();
  await expect(page.locator('body')).toContainText(number);
}

// A received bank transfer is confirmed on "Awaiting payment": that records the money and moves the order to Paid.
async function confirmTransfer(page, number) {
  await page.goto('/admin.php?p=awaiting_payment');
  const row = page.locator('tr', { hasText: number });
  page.once('dialog', (dialog) => dialog.accept());
  await row.locator('form[action*="action=confirm"] button[type="submit"]').click();
  await expect(page.locator('.alert, .flash').first()).toBeVisible();
  await expect(page.locator('tr', { hasText: number })).toHaveCount(0);
}

async function setStatus(page, status) {
  await page.locator('select[name="new_status"]').selectOption(status);
  const ack = page.locator('[data-withdrawal-ack]');
  if (await ack.count()) await ack.check();
  await page.locator('select[name="new_status"]').locator('xpath=ancestor::form').locator('button[type="submit"]').click();
  await expect(page.locator('.alert, .flash').first()).toBeVisible();
}

test('first visit runs the web installer and the shop is ready', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/install\.php/);
  const fields = {
    shop_name: 'BareBones Books', company: 'Demo Books Ltd', street: 'Main Street 1', city: 'Bratislava', zip: '811 01',
    country: 'SK', email: 'owner@books.test', iban: 'SK3112000000198742637541', admin_user: OWNER.username,
    admin_password: OWNER.password, admin_password_repeat: OWNER.password, private_parent: PRIVATE_PARENT,
  };
  for (const [name, value] of Object.entries(fields)) await page.locator(`[name="${name}"]`).fill(value);
  for (const name of ['pay_cod', 'pay_bank']) await page.locator(`input[name="${name}"]`).check();
  await shot(page, '01-installer');
  await page.locator('button[type="submit"]').click();
  await expect(page.locator('h1')).toHaveText('Your shop is ready');
  await expect(page.locator('body')).toContainText(/ 0 failures\./);
  orders.secret = (await page.locator('p.hint code').first().textContent()).trim();
  expect(orders.secret).toMatch(/^[A-Z2-7]{16,}$/);
  await shot(page, '02-installed');

  await page.goto('/');
  await dismissCookies(page);
  await expect(page.locator('.book-card').first()).toBeVisible();
  await shot(page, '03-home');
  expect((await page.request.get('/install.php')).status()).toBe(404);
  for (const privateFile of ['/config.php', '/demo/bookshop/dist/shop.sqlite']) {
    expect((await page.request.get(privateFile)).status(), privateFile).not.toBe(200);
  }
});

test('a customer buys an eBook, a paperback (cash on delivery) and a hardcover (bank transfer)', async ({ page }) => {
  await page.goto('/account.php?p=register');
  await dismissCookies(page);
  await page.locator('#account-register-name').fill(CUSTOMER.name);
  await page.locator('#account-register-email').fill(CUSTOMER.email);
  await page.locator('#account-register-password').fill(CUSTOMER.password);
  // The sign-up form rejects submissions faster than a person types (anti-bot minimum of 4 seconds).
  await page.waitForTimeout(4_500);
  await page.locator('#account-register-password').locator('xpath=ancestor::form').locator('button').click();
  await expect(page.locator('.flash-error')).toHaveCount(0);
  await expect(page.locator('#account-register-email')).toHaveCount(0);
  await expect(page.locator('body')).toContainText(CUSTOMER.email);

  await addFormat(page, 'Moby-Dick', 'eBook');
  await shot(page, '04-product');
  orders.ebook = await checkout(page, 'bank_transfer', '05-ebook');

  await addFormat(page, 'Wuthering Heights', 'Paperback');
  orders.paperback = await checkout(page, 'cod', '06-paperback-cod');

  await addFormat(page, 'Middlemarch', 'Hardcover');
  orders.hardcover = await checkout(page, 'bank_transfer', '07-hardcover-bank');
  await expect(page.locator('body')).toContainText(/SK31\s?1200/);
  fs.mkdirSync(SHOTS, { recursive: true });
  fs.writeFileSync(path.join(SHOTS, 'orders.json'), JSON.stringify({ ebook: orders.ebook, paperback: orders.paperback, hardcover: orders.hardcover }, null, 2));
});

test('the owner handles the orders and the customer downloads the eBook', async ({ page, browser }) => {
  test.setTimeout(240_000);
  await adminSignIn(page, orders.secret);
  await confirmTransfer(page, orders.ebook);
  await openAdminOrder(page, orders.ebook);
  await expect(page.locator('h3', { hasText: 'Status:' })).toContainText('Paid');
  await shot(page, '08-admin-ebook-paid');
  await confirmTransfer(page, orders.hardcover);
  await openAdminOrder(page, orders.hardcover);
  await setStatus(page, 'shipped');
  await shot(page, '09-admin-hardcover-shipped');
  await openAdminOrder(page, orders.paperback);
  await setStatus(page, 'shipped');
  await shot(page, '10-admin-paperback-shipped');

  // The paid eBook is granted by the worker, which runs from page requests on this hosting (no cron).
  const customer = await browser.newPage({ baseURL: test.info().project.use.baseURL, acceptDownloads: true });
  await customer.goto('/account.php?p=login');
  await customer.locator('input[name="email"]').fill(CUSTOMER.email);
  await customer.locator('input[name="password"]').fill(CUSTOMER.password);
  await customer.locator('input[name="password"]').locator('xpath=ancestor::form').locator('button').click();
  await expect(customer.locator('.flash-error')).toHaveCount(0);
  const download = customer.locator('form input[name="action"][value="download"]').locator('xpath=ancestor::form').locator('button');
  await expect(async () => {
    await customer.goto('/account.php?p=downloads');
    await expect(download.first()).toBeVisible({ timeout: 1_000 });
  }).toPass({ timeout: 150_000, intervals: [5_000] });
  await shot(customer, '11-downloads');
  const [file] = await Promise.all([customer.waitForEvent('download'), download.first().click()]);
  expect(file.suggestedFilename()).toMatch(/\.epub$/i);
  const saved = path.join(SHOTS, file.suggestedFilename());
  await file.saveAs(saved);
  const bytes = fs.readFileSync(saved);
  expect(bytes.subarray(0, 2).toString()).toBe('PK');
  expect(bytes.toString('latin1')).toContain('application/epub+zip');
});
