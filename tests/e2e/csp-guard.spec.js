// @ts-check
// Regression for the global CSP guard in support.js (P0 2026-10-13). In 1.9.0 the shop's checkout page
// sent `form-action 'self'` and the browser silently dropped the 302 to checkout.stripe.com. The
// violation happens on the SHOP's document (blockedURI is Stripe in WebKit, the shop's own form URL in
// Chromium), so the guard must count it — iPhone Safari (WebKit) above all — while ignoring violations
// on Stripe's own page. The shop side is a real 302 from tests/e2e/csp-guard-fixture.php (Playwright
// cannot fulfil redirects in every engine, nor route a redirect's target); no Stripe key is needed.
const { test, expect } = require('./support');

const FIXTURE = '/tests/e2e/csp-guard-fixture.php';

test('CSP guard: shop form-action without Stripe fails the Stripe checkout test', async ({ page, cspViolations }, testInfo) => {
  test.skip(testInfo.project.name === 'firefox', 'Firefox does not apply form-action to redirects, so the 1.9.0 drop cannot happen there');
  // Asserted explicitly below instead of failing in the guard's teardown.
  testInfo.annotations.push({ type: 'csp-violation-expected', description: "form-action 'self' must block the Stripe redirect and the guard must see it" });
  await page.goto(`${FIXTURE}?form_action=self`);
  await page.locator('form.checkout-form button').click();

  await expect.poll(() => cspViolations.filter(v => /form-action/i.test(v)).length, {
    message: 'the guard counts the form-action violation on the shop document',
    timeout: 10_000,
  }).toBeGreaterThan(0);
  expect(page.url(), 'the browser dropped the redirect').not.toContain('checkout.stripe.com');
});

test('CSP guard: violations on the Stripe page itself do not fail the shop', async ({ page, cspViolations }) => {
  await page.route('https://checkout.stripe.com/**', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    // Stripe's hosted page enforces its own, stricter policy; this inline script violates it on load.
    headers: { 'Content-Security-Policy': "default-src 'none'" },
    body: '<!doctype html><html lang="en"><title>Stripe stub</title><main>EUR 10.00</main><script>void 0</script></html>',
  }));
  await page.goto(`${FIXTURE}?form_action=stripe`);
  await page.goto('https://checkout.stripe.com/c/pay/cs_test_csp_guard_regression');

  await expect.poll(() => cspViolations.ignored.length, { message: "Stripe's own CSP fired on Stripe's document" }).toBeGreaterThan(0);
  expect(cspViolations, 'Stripe-page violations are not shop violations').toEqual([]);
});
