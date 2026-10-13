// @ts-check
// Layer 3 of the checkout critical path (P0 2026-10-13): the real Stripe Checkout in TEST mode.
// The mock gateway proves the browser may leave the shop; this proves the real adapter creates a
// real session and the customer's browser lands on checkout.stripe.com. Runs only with
// E2E_STRIPE_TEST_SECRET_KEY=sk_test_... (prepare.php + seed.php add the "card_stripe_test" method).
const { execFileSync } = require('node:child_process');
const { test, expect, state } = require('./support');

const STRIPE = 'https://checkout.stripe.com';

test.beforeEach(() => {
  execFileSync('php', ['tests/e2e/reset-rate-limits.php'], { cwd: process.cwd(), encoding: 'utf8' });
});

test('HTML card checkout opens a real Stripe test-mode Checkout session', async ({ page }) => {
  test.skip(!state.stripe_test, 'Set E2E_STRIPE_TEST_SECRET_KEY=sk_test_... before tests/e2e/prepare.php and seed.php');
  const violations = [];
  page.on('console', (message) => { if (/content security policy|form-action/i.test(message.text())) violations.push(message.text()); });

  await page.goto('/');
  const rejectConsent = page.locator('#cc-reject');
  if (await rejectConsent.isVisible()) await rejectConsent.click();
  await page.locator('[data-storefront-action="cart-add"]').first().click();
  await expect(page.locator('.cart-badge')).toHaveText(/^[1-9]/);

  await page.goto('/?page=checkout');
  await page.locator('input[name="name"]').fill('Stripe Test Customer');
  await page.locator('input[name="email"]').fill('stripe-test-e2e@example.test');
  await page.locator('input[name="street"]').fill('Gateway 3');
  await page.locator('input[name="city"]').fill('Bratislava');
  await page.locator('input[name="zip"]').fill('81101');
  await page.locator('input[name="payment"][value="card_stripe_test"]').check();

  await Promise.all([
    page.waitForURL(url => url.origin === STRIPE, { timeout: 30_000 }),
    page.locator('form.checkout-form button.btn-checkout').click(),
  ]);
  expect(new URL(page.url()).pathname).toMatch(/^\/c\/pay\/cs_test_/);
  // Stripe rendered its hosted page (amount and a pay button), not an error page.
  await expect(page.locator('body')).toContainText(/€|EUR/, { timeout: 30_000 });
  expect(violations).toEqual([]);
});
