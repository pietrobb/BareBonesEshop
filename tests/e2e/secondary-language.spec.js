const { execFileSync } = require('node:child_process');
const { test, expect } = require('./support');

// Review round 19: in a further language of a store (?lang=es on the default store, see seed.php) the page hands the
// script an API base that already carries "?lang=es". The script glued the action after it
// (/api/v1/storefront?lang=es/shipping_rates), every storefront API call answered 404, and the checkout kept showing
// the total without the chosen shipping (23,33 € shown, 26,82 € charged).
test.beforeEach(() => {
  execFileSync('php', ['tests/e2e/reset-rate-limits.php'], { cwd: process.cwd(), encoding: 'utf8' });
});

function orderFacts(orderNumber) {
  return JSON.parse(execFileSync('php', ['tests/e2e/order-facts.php', orderNumber], { cwd: process.cwd(), encoding: 'utf8' }));
}

const apiCall = (page, action) => page.waitForResponse(response => {
  const url = new URL(response.url());
  return url.pathname === `/api/v1/storefront/${action}` && url.searchParams.get('lang') === 'es';
});

test('a further store language reaches the storefront API and checkout shows the total it charges', async ({ page }, testInfo) => {
  await page.goto('/?lang=es');
  await expect(page.locator('html')).toHaveAttribute('lang', 'es');
  const rejectConsent = page.locator('#cc-reject');
  if (await rejectConsent.isVisible()) {
    const consent = apiCall(page, 'cookie_consent');
    await rejectConsent.click();
    expect((await consent).status()).toBe(200);
  }

  const search = page.locator('input[data-search-suggest]').first();
  if (await search.isVisible()) {
    const suggest = apiCall(page, 'search_suggest');
    await search.fill('ab');
    expect((await suggest).status()).toBe(200);
    await search.fill('');
  }

  const button = page.locator('[data-storefront-action="cart-add"]').first();
  await expect(button).toBeVisible();
  await button.click();
  await expect(page.locator('.cart-badge')).toHaveText(/^[1-9]/);

  const rates = apiCall(page, 'shipping_rates');
  await page.goto('/?page=checkout&lang=es');
  expect((await rates).status()).toBe(200);
  await page.locator('input[name="name"]').fill('R19 Lang Customer');
  await page.locator('input[name="email"]').fill(`r19-lang-${testInfo.project.name}@example.test`);
  await page.locator('input[name="phone"]').fill('+421900000019');
  await page.locator('input[name="street"]').fill('Jazykova 19');
  await page.locator('input[name="city"]').fill('Bratislava');
  await page.locator('input[name="zip"]').fill('81101');
  await expect(page.locator('#shipping-options .shipping-error')).toHaveCount(0);

  // Every shipping choice re-prices the summary: its shipping line shows that method's price.
  const shippingInputs = page.locator('input[name="shipping_method"]');
  expect(await shippingInputs.count()).toBeGreaterThan(1);
  for (let i = 0; i < await shippingInputs.count(); i++) {
    const option = shippingInputs.nth(i);
    if (!await option.isChecked()) {
      const payments = apiCall(page, 'payment_methods');
      await option.check();
      expect((await payments).status()).toBe(200);
    }
    await expect(page.locator('input[name="payment"]').first()).toBeVisible();
    const price = (await page.locator('.shipping-option').nth(i).locator('strong').textContent()).trim();
    const paymentShipping = await page.locator('#summary-shipping').textContent();
    // Cash on delivery may add the carrier's surcharge; any other first payment charges the method's price.
    if (!await page.locator('input[name="payment"][value="cod"]:checked').count()) expect(paymentShipping.trim()).toBe(price);
  }
  await expect(page.locator('#payment-options .payment-error')).toHaveCount(0);

  const bankTransfer = page.locator('input[name="payment"][value="bank_transfer"]');
  if (await bankTransfer.count()) await bankTransfer.check();
  else {
    const offline = page.locator('input[name="payment"]:not([data-online])').first();
    await expect(offline).toHaveCount(1);
    await offline.check();
  }
  const shown = (await page.locator('#summary-total').textContent()).replace(/\D/g, '');
  await page.locator('form.checkout-form button.btn-checkout').click();
  await expect(page).toHaveURL(/page=order-done/);
  const orderNumber = (await page.locator('.order-done > p strong').textContent()).trim();
  const facts = orderFacts(orderNumber);
  expect(String(facts.total)).toBe(shown);
});
