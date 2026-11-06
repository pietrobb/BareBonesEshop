const { execFileSync } = require('node:child_process');
const { test, expect } = require('./support');

// Review round 19: in a further language of a store (?lang=es on the default store, see seed.php) the page hands the
// script an API base that already carries "?lang=es". The script glued the action after it
// (/api/v1/storefront?lang=es/shipping_rates), every storefront API call answered 404, and the checkout kept showing
// the total without the chosen shipping (23,33 € shown, 26,82 € charged).
test.beforeEach(() => {
  execFileSync('php', ['tests/e2e/reset-rate-limits.php'], { cwd: process.cwd(), encoding: 'utf8' });
});

function orderFacts(orderNumber, store) {
  return JSON.parse(execFileSync('php', ['tests/e2e/order-facts.php', orderNumber, store], { cwd: process.cwd(), encoding: 'utf8' }));
}

// apiLang: the ?lang= the API calls carry — null when the language is another store's own one (Bootstrap::secondaryLocale).
async function shopInFurtherLanguage(page, testInfo, { prefix, lang, apiLang = lang, store, currency, symbol, minorFactor, contact }) {
  const apiCall = action => page.waitForResponse(response => {
    const url = new URL(response.url());
    return url.pathname === `${prefix}/api/v1/storefront/${action}` && url.searchParams.get('lang') === apiLang;
  });

  await page.goto(`${prefix}/?lang=${lang}`);
  await expect(page.locator('html')).toHaveAttribute('lang', lang);
  const rejectConsent = page.locator('#cc-reject');
  if (await rejectConsent.isVisible()) {
    const consent = apiCall('cookie_consent');
    await rejectConsent.click();
    expect((await consent).status()).toBe(200);
  }

  const search = page.locator('input[data-search-suggest]').first();
  if (await search.isVisible()) {
    const suggest = apiCall('search_suggest');
    await search.fill('ab');
    expect((await suggest).status()).toBe(200);
    await search.fill('');
  }

  const button = page.locator('[data-storefront-action="cart-add"]').first();
  await expect(button).toBeVisible();
  await button.click();
  await expect(page.locator('.cart-badge')).toHaveText(/^[1-9]/);

  const rates = apiCall('shipping_rates');
  await page.goto(`${prefix}/?page=checkout&lang=${lang}`);
  expect((await rates).status()).toBe(200);
  await page.locator('input[name="name"]').fill(contact.name);
  await page.locator('input[name="email"]').fill(`${contact.mailbox}-${testInfo.project.name}@example.test`);
  await page.locator('input[name="phone"]').fill(contact.phone);
  await page.locator('input[name="street"]').fill(contact.street);
  await page.locator('input[name="city"]').fill(contact.city);
  await page.locator('input[name="zip"]').fill(contact.zip);
  await expect(page.locator('#shipping-options .shipping-error')).toHaveCount(0);

  // Every shipping choice re-prices the summary: its shipping line shows that method's price.
  const shippingInputs = page.locator('input[name="shipping_method"]');
  expect(await shippingInputs.count()).toBeGreaterThan(1);
  for (let i = 0; i < await shippingInputs.count(); i++) {
    const option = shippingInputs.nth(i);
    if (!await option.isChecked()) {
      const payments = apiCall('payment_methods');
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
  // The script formats with Intl in the page language: es/en show the code (7243 CZK, CZK 7,243), cs the symbol.
  await expect(page.locator('#summary-total')).toContainText(new RegExp(`${symbol}|${currency.toUpperCase()}`));
  const shown = (await page.locator('#summary-total').textContent()).replace(/\D/g, '');
  await page.locator('form.checkout-form button.btn-checkout').click();
  await expect(page).toHaveURL(new RegExp(`^[^?]*${prefix}/\\?page=order-done`));
  const orderNumber = (await page.locator('.order-done > p strong').textContent()).trim();
  const facts = orderFacts(orderNumber, store);
  expect(facts.store_id).toBe(store);
  expect(facts.currency).toBe(currency);
  // The shown total is in the currency's shown units; the order stores minor units (cents).
  expect(facts.total).toBe(Number(shown) * minorFactor);
}

test('a further store language reaches the storefront API and checkout shows the total it charges', async ({ page }, testInfo) => {
  await shopInFurtherLanguage(page, testInfo, {
    prefix: '', lang: 'es', store: 'default', currency: 'eur', symbol: '€', minorFactor: 1,
    contact: { name: 'R19 Lang Customer', mailbox: 'r19-lang', phone: '+421900000019', street: 'Jazykova 19', city: 'Bratislava', zip: '81101' },
  });
});

// The same in a store with a URL prefix (/cs/, the API must strip it next to ?lang=) and a currency shown without
// decimals (CZK) — the shape of the review's HU numbers (5 490 Ft shown, 7 180 Ft charged).
const czContact = suffix => ({ name: `R19 Lang Cz ${suffix}`, mailbox: `r19-lang-cz-${suffix}`, phone: '+420777000019', street: 'Vinohradska 19', city: 'Praha', zip: '12000' });

test('a further language of a prefixed store with a 0-decimal currency shows the total it charges', async ({ page }, testInfo) => {
  await shopInFurtherLanguage(page, testInfo, {
    prefix: '/cs', lang: 'es', store: 'cz', currency: 'czk', symbol: 'Kč', minorFactor: 100, contact: czContact('es'),
  });
});

// English is the /en/ store's own language: in /cs/ it renders, but links and the API stay on the store's URL
// without ?lang=en (Bootstrap::secondaryLocale). The checkout still prices every choice and charges what it shows.
test('English in the prefixed CZK store shows the total it charges', async ({ page }, testInfo) => {
  await shopInFurtherLanguage(page, testInfo, {
    prefix: '/cs', lang: 'en', apiLang: null, store: 'cz', currency: 'czk', symbol: 'Kč', minorFactor: 100, contact: czContact('en'),
  });
});
