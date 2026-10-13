const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect, state, adminSignIn } = require('./support');

// Hosted payment page on its own origin (tests/e2e/mock-gateway.php, prepare.php mock_checkout_url).
// The browser must be allowed to follow the checkout POST redirect there: CSP form-action
// applies to it, and page.route() cannot stand in because it does not see redirect hops (P0 2026-10-13).
const GATEWAY = new URL(process.env.E2E_GATEWAY_URL || 'http://127.0.0.1:8099').origin;

test.beforeEach(() => {
  execFileSync('php', ['tests/e2e/reset-rate-limits.php'], { cwd: process.cwd(), encoding: 'utf8' });
});

async function fillCheckout(page, email) {
  await page.goto('/?page=checkout');
  await page.locator('input[name="name"]').fill('G12 Browser Customer');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="phone"]').fill('+421900000012');
  await page.locator('input[name="street"]').fill('Qualification 12');
  await page.locator('input[name="city"]').fill('Bratislava');
  await page.locator('input[name="zip"]').fill('81101');
  await expect(page.locator('input[name="shipping_method"]').first()).toBeVisible();
  await expect(page.locator('input[name="payment"]').first()).toBeVisible();
}


async function addFirstProduct(page) {
  await page.goto('/');
  const rejectConsent = page.locator('#cc-reject');
  if (await rejectConsent.isVisible()) {
    const consentResponse = page.waitForResponse(response => response.url().includes('action=cookie_consent'));
    await rejectConsent.click();
    expect((await consentResponse).status()).toBe(200);
  }
  const button = page.locator('[data-storefront-action="cart-add"]').first();
  await expect(button).toBeVisible();
  await button.click();
  await expect(page.locator('.cart-badge')).toHaveText(/^[1-9]/);
}

async function checkout(page, email) {
  await fillCheckout(page, email);
  await page.locator('form.checkout-form button[type="submit"]').click();
  await page.waitForURL(url => url.origin === GATEWAY || url.searchParams.get('page') === 'order-done');
  if (new URL(page.url()).origin === GATEWAY) await page.locator('#gateway-pay').click();
  await expect(page).toHaveURL(/page=order-done/);
  await expect(page.locator('.order-done > p strong')).not.toBeEmpty();
  const successUrl = new URL(page.url());
  expect(successUrl.searchParams.get('confirmation_token')).toMatch(/^[A-Za-z0-9_-]{40,}$/);
  await expect(page.locator('.payment-summary')).toBeVisible();

  const orderNumber = (await page.locator('.order-done > p strong').textContent()).trim();
  const qualification = JSON.parse(execFileSync('php', ['tests/e2e/assert-checkout.php', orderNumber], {
    cwd: process.cwd(), encoding: 'utf8'
  }));
  expect(qualification.snapshot_fingerprint).toMatch(/^[a-f0-9]{64}$/);
  expect(qualification.vat).toBeGreaterThan(0);
  expect(qualification.net).toBeGreaterThan(0);
  expect(qualification.invoice_id).toBeGreaterThan(0);

  successUrl.searchParams.delete('confirmation_token');
  await page.goto(successUrl.toString());
  await expect(page.locator('.payment-summary')).toHaveCount(0);
}

test('guest cart and checkout complete in a real browser', async ({ page }) => {
  await addFirstProduct(page);
  await checkout(page, 'guest-g12@example.test');
});

test('account login preserves authenticated checkout ownership', async ({ page }) => {
  test.skip(!state.customer, 'Run tests/e2e/seed.php first');
  await page.goto('/account.php?p=login');
  await page.locator('input[name="email"]').fill(state.customer.email);
  await page.locator('input[name="password"]').fill(state.customer.password);
  await page.locator('form').filter({ has: page.locator('input[value="login"]') }).locator('button').click();
  await expect(page).toHaveURL(/account\.php(?:$|\?)/);
  await addFirstProduct(page);
  await checkout(page, state.customer.email);
});

test('HTML card checkout reaches the hosted gateway and resumes after cancel', async ({ page }) => {
  const blocked = [];
  page.on('console', (message) => { if (/form-action/i.test(message.text())) blocked.push(message.text()); });
  await addFirstProduct(page);
  await fillCheckout(page, 'card-redirect@example.test');
  await page.locator('input[name="payment"][value="card"]').check();
  const button = page.locator('form.checkout-form button[type="submit"]');
  await Promise.all([page.waitForURL(url => url.origin === GATEWAY), button.click()]);
  expect(blocked).toEqual([]);
  const paymentUrl = page.url();
  expect(new URL(paymentUrl).searchParams.get('cancel')).toContain('page=checkout-cancel');

  // Gateway "back": the order is kept and can be paid, never "your cart is empty".
  await page.locator('#gateway-back').click();
  await expect(page).toHaveURL(/page=checkout-cancel/);
  const payLink = page.locator('[data-pending-payment-link]');
  await expect(payLink).toHaveAttribute('href', paymentUrl);
  await page.goto('/?page=checkout');
  await expect(page.locator('[data-pending-payment-link]')).toHaveAttribute('href', paymentUrl);
  await Promise.all([page.waitForURL(url => url.origin === GATEWAY), page.locator('[data-pending-payment-link]').click()]);

  // Paying ends the pending state.
  await page.locator('#gateway-pay').click();
  await expect(page).toHaveURL(/page=order-done/);
  await page.goto('/?page=checkout');
  await expect(page.locator('[data-pending-payment-link]')).toHaveCount(0);
});

test('checkout button locks while submitting and unlocks when the page is shown again', async ({ page }) => {
  await addFirstProduct(page);
  await fillCheckout(page, 'card-lock@example.test');
  await page.locator('input[name="payment"][value="card"]').check();
  const button = page.locator('form.checkout-form button[type="submit"]');
  const label = await button.textContent();
  const redirecting = await page.locator('form[data-checkout-form]').getAttribute('data-redirecting-label');
  // A listener added after the storefront one keeps the document, so the locked state is observable.
  await page.evaluate(() => document.addEventListener('submit', event => event.preventDefault()));
  await button.click();
  await expect(button).toBeDisabled();
  await expect(button).toHaveText(redirecting);
  await expect(button).toHaveAttribute('aria-busy', 'true');
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  await expect(button).toBeEnabled();
  await expect(button).toHaveText(label);
});

function orderFacts(arg) {
  return JSON.parse(execFileSync('php', ['tests/e2e/order-facts.php', arg], { cwd: process.cwd(), encoding: 'utf8' }));
}

// Real customer path: click "submit" in the HTML form, follow the cross-origin redirect to the
// hosted gateway, gateway calls the signed webhook server-to-server, customer returns paid.
test('online payment redirect and signed webhook complete the bound checkout', async ({ page, request }) => {
  test.skip(!state.mock_webhook_secret, 'Run tests/e2e/seed.php first');
  await addFirstProduct(page);
  await fillCheckout(page, 'online-g12@example.test');
  await page.locator('input[name="payment"][value="card"]').check();
  await Promise.all([
    page.waitForURL(url => url.origin === GATEWAY),
    page.locator('form.checkout-form button[type="submit"]').click(),
  ]);
  const gatewayUrl = new URL(page.url());
  expect(gatewayUrl.searchParams.get('success')).toContain('page=order-done');
  const sessionId = gatewayUrl.searchParams.get('session');
  expect(sessionId).toMatch(/^mock_ORD-/);
  const facts = orderFacts(sessionId.slice('mock_'.length));
  expect(facts.payment_status).not.toBe('paid');

  const payload = JSON.stringify({
    event_id: `g12-paid-${facts.order_id}`,
    order_id: facts.order_id,
    session_id: sessionId,
    amount: facts.total,
    currency: 'eur'
  });
  const signature = crypto.createHmac('sha256', state.mock_webhook_secret).update(payload).digest('hex');
  const webhook = await request.post('/api.php?action=webhook_payment&provider=mock', {
    data: payload, headers: { 'Content-Type': 'application/json', 'X-Mock-Signature': signature }
  });
  expect((await webhook.json()).status).toBe('ok');

  await page.locator('#gateway-pay').click();
  await expect(page).toHaveURL(/page=order-done/);
  await expect(page.locator('.payment-summary')).toBeVisible();
  expect(orderFacts(sessionId.slice('mock_'.length)).payment_status).toBe('paid');
});

test('double click on the order button creates exactly one order and reaches the gateway', async ({ page }, testInfo) => {
  const email = `card-double-${testInfo.project.name}@example.test`;
  await addFirstProduct(page);
  await fillCheckout(page, email);
  await page.locator('input[name="payment"][value="card"]').check();
  await Promise.all([
    page.waitForURL(url => url.origin === GATEWAY),
    page.locator('form.checkout-form button[type="submit"]').dblclick(),
  ]);
  expect(orderFacts(`--email=${email}`).count).toBe(1);
});

test('admin identity requires password plus current TOTP', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
});

test('admin product export and bulk controls use one valid form', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
  await page.goto('/admin.php?p=products');
  await expect(page.locator('form form')).toHaveCount(0);
  await expect(page.locator('#bulk-form')).toHaveAttribute('data-confirm', 'OK?');

  const exportButton = page.locator('#bulk-form button[formaction*="action=export"]');
  await expect(exportButton).toHaveAttribute('formmethod', 'post');
  await expect(exportButton).toHaveAttribute('data-skip-confirm', '');
  await expect(exportButton).not.toHaveAttribute('data-confirm', /.+/);
  const [download] = await Promise.all([page.waitForEvent('download'), exportButton.click()]);
  expect(download.suggestedFilename()).toMatch(/products.*\.csv$/);
  const csv = fs.readFileSync(await download.path(), 'utf8');
  expect(csv).toMatch(/^sku,name,slug,product_type,price,compare_price,stock,status,summary,weight_g,stock_low_threshold,stock_critical_threshold\r?\n/);
  expect(csv).not.toContain('Deprecated');

  const firstProduct = page.locator('#bulk-form .product-check').first();
  const productId = await firstProduct.getAttribute('value');
  await firstProduct.check();
  await page.locator('#bulk-form select[name="bulk_action"]').selectOption('activate');
  const bulkButton = page.locator('#bulk-form button[type="submit"]:not([formaction])');
  await expect(bulkButton).not.toHaveAttribute('data-skip-confirm', '');
  page.once('dialog', dialog => dialog.accept());
  await bulkButton.click();
  const row = page.locator('tr').filter({ has: page.locator(`.product-check[value="${productId}"]`) });
  await expect(row.locator('.badge-active')).toHaveText('active');
});

test('admin fulfillment ships a paid order through the warehouse ledger', async ({ page }) => {
  test.skip(!state.fulfillment, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
  await page.goto(`/admin.php?p=order&id=${state.fulfillment.order_id}`);
  const statusForm = page.locator('form[action*="action=status"]');
  await statusForm.locator('select[name="new_status"]').selectOption('processing');
  await statusForm.locator('button[type="submit"]').click();
  await expect(page.locator('h3 .badge-processing')).toHaveText('processing');
  await page.locator('form[action*="action=status"] select[name="new_status"]').selectOption('shipped');
  await page.locator('form[action*="action=status"] button[type="submit"]').click();
  await expect(page.locator('h3 .badge-shipped')).toHaveText('shipped');
});

test('admin completes a partial RMA receipt, credit note, and refund', async ({ page }) => {
  test.skip(!state.rma, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
  await page.goto('/admin.php?p=rma');
  const row = page.locator('tr').filter({ has: page.locator(`input[name="rma_id"][value="${state.rma.rma_id}"]`) });
  await row.locator('form').filter({ has: page.locator('input[name="status"][value="approved"]') }).locator('button').click();
  await expect(page.locator('tr').filter({ hasText: 'G12 partial browser return' }).locator('.badge-approved')).toHaveText('approved');
  const approvedRow = page.locator('tr').filter({ hasText: 'G12 partial browser return' });
  await approvedRow.locator('select[name="disposition"]').selectOption('restock');
  await approvedRow.locator('form').filter({ has: page.locator('input[name="status"][value="received"]') }).locator('button').click();
  await expect(page.locator('tr').filter({ hasText: 'G12 partial browser return' }).locator('.badge-received')).toHaveText('received');
  await page.locator('tr').filter({ hasText: 'G12 partial browser return' }).locator('form').filter({ has: page.locator('input[name="status"][value="refunded"]') }).locator('button').click();
  await expect(page.locator('tr').filter({ hasText: 'G12 partial browser return' }).locator('.badge-refunded')).toHaveText('refunded');
});

test('safe updater smoke remains executable from the qualification runner', async () => {
  const output = execFileSync('php', ['cli/test-update.php'], { cwd: process.cwd(), encoding: 'utf8', env: { ...process.env, BAREBONES_REQUIRE_UPDATE_SIGNATURE_TEST: '1' } });
  expect(output).toContain('Safe versioned updater');
  expect(output).not.toContain('[FAIL]');
});
