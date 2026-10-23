const { execFileSync } = require('node:child_process');
const { test, expect } = require('./support');

// Audit round 4: the /cs/ store (stores.json cz) shares the default store's catalog and warehouses but has
// its own url_prefix and currency. A guest purchase there failed with "stock changed", and the cart showed
// the EUR product prices as CZK.
test.beforeEach(() => {
  execFileSync('php', ['tests/e2e/reset-rate-limits.php'], { cwd: process.cwd(), encoding: 'utf8' });
});

function orderFacts(orderNumber, store) {
  return JSON.parse(execFileSync('php', ['tests/e2e/order-facts.php', orderNumber, store], { cwd: process.cwd(), encoding: 'utf8' }));
}

test('guest purchase by bank transfer completes in the /cs/ store', async ({ page }, testInfo) => {
  await page.goto('/cs/');
  const rejectConsent = page.locator('#cc-reject');
  if (await rejectConsent.isVisible()) await rejectConsent.click();
  const button = page.locator('[data-storefront-action="cart-add"]').first();
  await expect(button).toBeVisible();
  await button.click();
  await expect(page.locator('.cart-badge')).toHaveText(/^[1-9]/);

  // The EUR catalog is sold in the cz store at its CZK price list (seed.php): the cart is in koruny.
  await page.goto('/cs/?page=cart');
  await expect(page.locator('main')).toContainText('Kč');
  await expect(page.locator('main')).not.toContainText('€');

  // The store-prefixed JSON API quotes shipping and payment for the cz cart.
  const apiCalls = ['shipping_rates', 'payment_methods'].map(action => page.waitForResponse(
    response => new URL(response.url()).pathname === `/cs/api/v1/storefront/${action}`));
  await page.goto('/cs/?page=checkout');
  for (const call of apiCalls) expect((await call).status()).toBe(200);
  await page.locator('input[name="name"]').fill('Round4 Cz Customer');
  await page.locator('input[name="email"]').fill(`multistore-cz-${testInfo.project.name}@example.test`);
  await page.locator('input[name="phone"]').fill('+420777000444');
  await page.locator('input[name="street"]').fill('Vinohradska 4');
  await page.locator('input[name="city"]').fill('Praha');
  await page.locator('input[name="zip"]').fill('12000');
  await expect(page.locator('input[name="shipping_method"]').first()).toBeVisible();
  await page.locator('input[name="payment"][value="bank_transfer"]').check();
  await expect(page.locator('#summary-total')).toContainText('Kč');
  await page.locator('form.checkout-form button.btn-checkout').click();

  await expect(page).toHaveURL(/\/cs\/\?page=order-done/);
  const orderNumber = (await page.locator('.order-done > p strong').textContent()).trim();
  expect(orderNumber).toMatch(/^ORD-/);
  const facts = orderFacts(orderNumber, 'cz');
  expect(facts.store_id).toBe('cz');
  expect(facts.currency).toBe('czk');
  expect(facts.status).toBe('pending');
});
