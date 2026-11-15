// Run by AuditRound37ShippingRefreshRegression.php with its isolated real-handler HTML/API responses.
/* global fixture, console, URL, URLSearchParams, setTimeout, process */
// `fixture` is prepended on stdin, so a Windows Chromium can exercise a WSL PHP fixture too.
(async () => {
  const { chromium, expect } = require(fixture.playwright);
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  let refreshes = 0;
  let selected = fixture.courier;
  let delayPickup = false;
  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.endsWith('/shipping_rates')) return route.fulfill({ json: fixture.rates });
    if (url.pathname.endsWith('/payment_methods')) {
      const method = url.searchParams.get('shipping_method');
      if (delayPickup && method === 'pickup') await new Promise(resolve => setTimeout(resolve, 500));
      return route.fulfill({ json: fixture.payments[method] });
    }
    if (url.pathname.endsWith('/storefront.js')) return route.fulfill({ contentType: 'application/javascript', body: fixture.source });
    if (request.isNavigationRequest()) {
      if (request.method() === 'POST') {
        const post = new URLSearchParams(request.postData());
        expect(post.get('checkout_refresh')).toBe('1');
        expect(post.get('checkout')).toBe('1'); // The form always carries this hidden field; checkout_refresh takes precedence.
        expect(post.get('company')).toBe('R37S GmbH');
        selected = post.get('shipping_method');
        refreshes++;
      }
      return route.fulfill({ contentType: 'text/html', body: fixture.pages[selected] });
    }
    return route.fulfill({ body: '' });
  });
  try {
    await page.goto('https://shop.example/index.php?page=checkout');
    const ready = () => expect(page.locator('#payment-options input')).not.toHaveCount(0);
    await ready();
    await expect(page.locator('#summary-total')).toContainText('100,00');
    expect(refreshes).toBe(0);
    await page.locator('input[name="shipping_method"][value="pickup"]').check();
    await expect(page.locator('#checkout-subtotal')).toHaveAttribute('data-vat-scenario', 'domestic', { timeout: 5000 });
    await ready();
    await expect(page.locator('#summary-total')).toContainText('123,00');
    await expect(page.locator('input[name="shipping_method"][value="pickup"]')).toBeChecked();
    expect(refreshes).toBe(1);
    await page.locator(`input[name="shipping_method"][value="${fixture.courier}"]`).check();
    await expect(page.locator('#checkout-subtotal')).toHaveAttribute('data-vat-scenario', 'reverse_charge');
    await ready();
    await expect(page.locator('#summary-total')).toContainText('100,00');
    expect(refreshes).toBe(2);
    // A superseded pickup response must not reload after the buyer has switched back to the courier.
    delayPickup = true;
    const pending = page.waitForRequest(request => request.url().includes('/payment_methods') && new URL(request.url()).searchParams.get('shipping_method') === 'pickup');
    await page.locator('input[name="shipping_method"][value="pickup"]').check();
    await pending;
    await page.locator(`input[name="shipping_method"][value="${fixture.courier}"]`).check();
    await page.waitForTimeout(800);
    await ready();
    expect(refreshes).toBe(2);
    await expect(page.locator('#summary-total')).toContainText('100,00');
    expect(errors).toEqual([]);
    console.log('PASS Chromium: visible 100 -> 123 -> 100 EUR; selected-method refresh POSTs; no initial/reload loop; stale response ignored');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
