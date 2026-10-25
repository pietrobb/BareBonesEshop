const { execFileSync } = require('node:child_process');
const { test, expect, state, adminSignIn } = require('./support');
const AxeBuilder = require('@axe-core/playwright').default;

// R10 UX10: admin productivity — the rich-text editor, Duplicate, report charts and a phone-sized admin.
test.describe.configure({ mode: 'serial' });

function db(...args) {
  return JSON.parse(execFileSync('php', ['tests/e2e/admin-state.php', ...args], { cwd: process.cwd(), encoding: 'utf8', env: process.env }));
}

async function noSeriousViolations(page, label) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  const violations = result.violations.filter(({ impact }) => ['critical', 'serious'].includes(impact));
  expect(violations, `${label}: ${JSON.stringify(violations, null, 2)}`).toEqual([]);
}

test('admin rich-text editor formats text, filters pasted markup and saves HTML', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  const product = db('product');
  await adminSignIn(page);
  await page.goto(`/admin.php?p=product&id=${product.row.id}`);
  const form = page.locator('#form-admin-product');
  const field = form.locator('#field-description');
  // The main description's editor sits right after its toolbar; each translation has its own.
  const editor = form.locator('[data-rich-for="field-description"] + .rich-editor');
  await expect(editor).toHaveAttribute('role', 'textbox');
  await expect(editor).toHaveAccessibleName(/\S/);
  await expect(field).toBeHidden();
  await expect(editor).toBeVisible();
  await expect(form.getByRole('toolbar').first()).toHaveAccessibleName(/\S/);
  await noSeriousViolations(page, 'product editor');

  await editor.click();
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.press('Delete');
  await page.keyboard.type('Plain ');
  await form.locator('[data-rich-for="field-description"]').getByRole('button', { name: 'Bold' }).click();
  await page.keyboard.type('bold');
  await expect(field).toHaveValue(/<b>bold<\/b>/);

  // Pasted HTML keeps text and safe tags only (the server sanitizer stays authoritative).
  await editor.evaluate(el => {
    el.focus();
    const range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    getSelection().removeAllRanges();
    getSelection().addRange(range);
    const data = new DataTransfer();
    data.setData('text/html', '<p onclick="x()" style="color:red">Pasted <img src=x onerror="window.__pwned=1"><script>window.__pwned=2</script><a href="javascript:alert(1)">bad</a> <a href="https://example.com/x">good</a></p>');
    el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
  });
  await expect(field).toHaveValue(/Pasted/);
  const value = await field.inputValue();
  expect(value).not.toMatch(/onclick|onerror|<script|<img|javascript:|style=/i);
  expect(value).toContain('href="https://example.com/x"');
  expect(await page.evaluate(() => window.__pwned)).toBeUndefined();

  // The HTML view shows the same source, and switching back keeps it.
  const toggle = form.locator('[data-rich-for="field-description"] button[aria-pressed]');
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  await expect(field).toBeVisible();
  await expect(editor).toBeHidden();
  await toggle.click();
  await expect(editor).toContainText('Pasted');

  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.flash-ok')).toHaveText('Product updated.');
  const saved = db('product', product.sku).row.description;
  expect(saved).toContain('<b>bold</b>');
  expect(saved).toContain('Pasted');
  expect(saved).not.toMatch(/script|onerror|javascript:/i);
});

test('admin Duplicate opens a draft copy with a new SKU and no stock', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  const product = db('product');
  await adminSignIn(page);
  await page.goto(`/admin.php?p=product&id=${product.row.id}`);
  await page.getByRole('button', { name: 'Duplicate' }).click();
  await expect(page.locator('.flash-ok')).toContainText('draft');
  await expect(page).toHaveURL(/p=product&id=\d+$/);
  expect(page.url()).not.toMatch(new RegExp(`id=${product.row.id}$`));
  const form = page.locator('#form-admin-product');
  await expect(form.locator('#field-sku')).toHaveValue(`${product.sku}-COPY`);
  await expect(form.locator('#field-status')).toHaveValue('draft');
  await expect(form.locator('#field-stock')).toHaveValue('0');
  expect(Number(db('product', product.sku).row.stock)).toBe(Number(product.row.stock));
});

test('admin reports draw a revenue chart per currency with a text summary', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
  await page.goto('/admin.php?p=reports&days=90');
  const sections = page.locator('main details');
  const charts = page.locator('main svg[data-report-chart]');
  await expect(charts).toHaveCount(await sections.count());
  for (let i = 0; i < await sections.count(); i++) {
    const section = sections.nth(i);
    await section.evaluate(el => { el.open = true; });
    const chart = section.locator('svg[data-report-chart]');
    await expect(chart).toHaveAccessibleName(/Daily revenue/);
    expect(await chart.locator('rect').count()).toBe(await section.locator('tr[data-report="trend"]').count());
  }
});

test('admin pages fit a 360px phone without sideways scrolling or serious axe violations', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
  await page.setViewportSize({ width: 360, height: 800 });
  const product = db('product');
  await page.goto('/admin.php?p=orders');
  const order = await page.locator('main a[href*="p=order&id="]').first().getAttribute('href');
  for (const path of ['/admin.php?p=dashboard', '/admin.php?p=orders', `/admin.php?${order.split('?')[1]}`, '/admin.php?p=order_new',
    '/admin.php?p=products', `/admin.php?p=product&id=${product.row.id}`, '/admin.php?p=reports', '/admin.php?p=customers', '/admin.php?p=page_edit']) {
    await page.goto(path);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, `${path} scrolls sideways by ${overflow}px at 360px`).toBeLessThanOrEqual(0);
    await noSeriousViolations(page, `${path} at 360px`);
  }
  // The navigation stays reachable: one scrollable row of links above the page.
  const nav = page.getByRole('navigation');
  await expect(nav.getByRole('link', { name: /Orders/ }).first()).toBeVisible();
});
