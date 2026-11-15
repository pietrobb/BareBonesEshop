const { execFileSync } = require('node:child_process');
const { test, expect } = require('./support');

// R37 #467 on the live server, nothing routed: an OSS store that keeps the net abroad (default cross_border_pricing)
// prices the 123 € mug (SK 23 %) at 120 € for Austria (20 %) and 127 € for Hungary (27 %). Switching AT → HU
// re-prices the form on the server once, and the order placed in the buyer's own session is stored at that price.
const php = args => execFileSync('php', args, { cwd: process.cwd(), encoding: 'utf8' });

test.describe.configure({ mode: 'serial' });
test.beforeAll(() => { php(['tests/e2e/oss-store.php', 'on']); });
test.afterAll(() => { php(['tests/e2e/oss-store.php', 'off']); });
test.beforeEach(() => { php(['tests/e2e/reset-rate-limits.php']); });

test('OSS kept net: AT 120 € → HU 127 € re-priced once and ordered at 127 €', async ({ page }, testInfo) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  let refreshes = 0;
  page.on('request', request => {
    if (request.method() === 'POST' && new URLSearchParams(request.postData() || '').get('checkout_refresh') === '1') refreshes++;
  });

  await page.goto('/product.php?slug=e2e-oss-mug');
  const rejectConsent = page.locator('#cc-reject');
  if (await rejectConsent.isVisible()) await rejectConsent.click();
  await page.locator('form.product-detail__actions [data-storefront-action="cart-add"]').click();
  await expect(page.locator('.cart-badge')).toHaveText(/^1$/);

  await page.goto('/?page=checkout');
  const country = page.locator('#checkout-country');
  const total = page.locator('#summary-total');
  const settled = async (code, amount) => {
    await expect(country).toHaveValue(code);
    await expect(page.locator('#checkout-subtotal')).toHaveValue(amount);
    const shipping = page.locator('input[name="shipping_method"][value="e2e-oss-post"]');
    await expect(shipping).toBeVisible();
    await shipping.check();
    const payment = page.locator('input[name="payment"][value="e2e-oss-pay"]');
    await expect(payment).toBeVisible();
    await payment.check();
  };

  await country.selectOption('AT');
  await settled('AT', '12000');
  await expect(total).toContainText(/120[,.]00/);
  const beforeHu = refreshes;
  await country.selectOption('HU');
  await settled('HU', '12700');
  await expect(total).toContainText(/127[,.]00/);
  await page.waitForTimeout(800);
  expect(refreshes - beforeHu).toBe(1);

  await page.locator('input[name="name"]').fill('R37 OSS Buyer');
  await page.locator('input[name="email"]').fill(`oss-r37-${testInfo.project.name}@example.test`);
  await page.locator('input[name="phone"]').fill('+36300000037');
  await page.locator('input[name="street"]').fill('Andrassy ut 37');
  await page.locator('input[name="city"]').fill('Budapest');
  await page.locator('input[name="zip"]').fill('1061');
  await expect(total).toContainText(/127[,.]00/);
  await page.locator('form.checkout-form button.btn-checkout').click();

  await expect(page).toHaveURL(/page=order-done/);
  const orderNumber = (await page.locator('.order-done > p strong').textContent()).trim();
  const facts = JSON.parse(php(['tests/e2e/order-facts.php', orderNumber]));
  expect([facts.total, facts.net_total, facts.vat_scenario, facts.shipping_addr?.country]).toEqual([12700, 10000, 'oss', 'HU']);
  expect(refreshes - beforeHu).toBe(1);
  expect(errors).toEqual([]);
});
