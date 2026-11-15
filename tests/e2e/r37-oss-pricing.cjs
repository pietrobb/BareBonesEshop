// Run by AuditRound37OssPricingRegression.php with its isolated real-handler HTML/API responses (AT and HU, kept net).
/* global fixture, console, URL, URLSearchParams, process, document */
(async () => {
  const { chromium, expect } = require(fixture.playwright);
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  let refreshes = 0;
  let submitted = null;
  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const country = url.searchParams.get('country') || 'AT';
    if (url.pathname.endsWith('/shipping_rates')) return route.fulfill({ json: fixture.countries[country].rates });
    if (url.pathname.endsWith('/payment_methods')) return route.fulfill({ json: fixture.countries[country].payments });
    if (url.pathname.endsWith('/storefront.js')) return route.fulfill({ contentType: 'application/javascript', body: fixture.source });
    if (request.isNavigationRequest()) {
      if (request.method() !== 'POST') return route.fulfill({ contentType: 'text/html', body: fixture.countries.AT.page });
      const post = new URLSearchParams(request.postData());
      if (post.get('checkout_refresh') !== '1') {
        submitted = request.postData();
        return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Submitted</title>' });
      }
      refreshes++;
      return route.fulfill({ contentType: 'text/html', body: fixture.countries[post.get('country')].page });
    }
    return route.fulfill({ body: '' });
  });
  try {
    await page.goto('https://shop.example/index.php?page=checkout');
    const ready = () => expect(page.locator('#payment-options input')).not.toHaveCount(0);
    await ready();
    await expect(page.locator('#summary-total')).toContainText('120,00');
    expect(refreshes).toBe(0);
    await page.locator('#checkout-country').selectOption('HU');
    await expect(page.locator('#checkout-subtotal')).toHaveValue('12700', { timeout: 5000 });
    await ready();
    await expect(page.locator('#summary-total')).toContainText('127,00');
    await expect(page.locator('#checkout-country')).toHaveValue('HU');
    await page.waitForTimeout(800);
    expect(refreshes).toBe(1);
    expect(errors).toEqual([]);
    console.log('PASS Chromium: OSS AT 120 -> HU 127 EUR re-priced by the server once; no reload loop');
    // Buyer fills the form and places the order; the PHP side replays this exact POST through the real handler.
    await page.evaluate(() => {
      const values = { email: 'r37p@example.test', tel: '+36300000037', zip: '1011', city: 'Budapest' };
      for (const el of document.querySelectorAll('form.checkout-form input, form.checkout-form textarea')) {
        if (el.type === 'checkbox') { if (el.required || el.name.startsWith('consents[')) el.checked = true; continue; }
        if (!el.required || el.value || el.type === 'hidden' || el.type === 'radio') continue;
        el.value = values[el.type] || values[el.name] || 'R37P';
      }
    });
    await page.locator('form.checkout-form [type=submit]').last().click();
    await expect.poll(() => submitted, { timeout: 5000 }).not.toBeNull();
    const sent = new URLSearchParams(submitted);
    expect([sent.get('country'), sent.get('shipping_method'), sent.get('payment'), sent.get('checkout')]).toEqual(['HU', 'r37p-post', 'r37p-pay', '1']);
    expect(errors).toEqual([]);
    console.log('R37PPOST' + submitted);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
