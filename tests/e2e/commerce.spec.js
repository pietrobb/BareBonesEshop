const { test, expect } = require('@playwright/test');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { state, adminSignIn } = require('./support');


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
  await page.goto('/?page=checkout');
  await page.locator('input[name="name"]').fill('G12 Browser Customer');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="phone"]').fill('+421900000012');
  await page.locator('input[name="street"]').fill('Qualification 12');
  await page.locator('input[name="city"]').fill('Bratislava');
  await page.locator('input[name="zip"]').fill('81101');
  await expect(page.locator('input[name="shipping_method"]').first()).toBeVisible();
  await expect(page.locator('input[name="payment"]').first()).toBeVisible();
  await page.locator('form.checkout-form button[type="submit"]').click();
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

test('online payment redirect and signed webhook complete the bound checkout', async ({ page }) => {
  test.skip(!state.mock_webhook_secret, 'Run tests/e2e/seed.php first');
  await addFirstProduct(page);
  await page.goto('/?page=checkout');
  await expect(page.locator('input[name="payment"][value="card"]')).toBeVisible();
  const csrf = await page.locator('input[name="csrf_token"]').inputValue();
  const shippingMethod = await page.locator('input[name="shipping_method"]:checked').inputValue();
  const result = await page.evaluate(async ({ csrf, shippingMethod }) => {
    const response = await fetch('/api.php?action=checkout', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({
        name: 'G12 Online Customer', email: 'online-g12@example.test', phone: '+421900000014',
        street: 'Qualification 14', city: 'Bratislava', zip: '81101', country: 'SK',
        shipping_method: shippingMethod, payment_method: 'card'
      })
    });
    return response.json();
  }, { csrf, shippingMethod });
  expect(result.success).toBeTruthy();
  expect(result.payment_url).toContain('page=order-done');
  expect(result.session_id).toMatch(/^mock_/);

  const payload = JSON.stringify({
    event_id: `g12-paid-${result.order_id}`,
    order_id: result.order_id,
    session_id: result.session_id,
    amount: result.total,
    currency: 'eur'
  });
  const signature = crypto.createHmac('sha256', state.mock_webhook_secret).update(payload).digest('hex');
  const webhook = await page.evaluate(async ({ payload, signature }) => {
    const response = await fetch('/api.php?action=webhook_payment&provider=mock', {
      method: 'POST', body: payload,
      headers: { 'Content-Type': 'application/json', 'X-Mock-Signature': signature }
    });
    return response.json();
  }, { payload, signature });
  expect(webhook.status).toBe('ok');
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
