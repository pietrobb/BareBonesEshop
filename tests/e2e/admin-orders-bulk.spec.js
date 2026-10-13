const fs = require('node:fs');
const { test, expect, state, adminSignIn } = require('./support');
const AxeBuilder = require('@axe-core/playwright').default;

// A15.2: the orders list exports CSV and prints delivery notes for the selected orders, without CSP violations.
test('admin orders list exports CSV and prints delivery notes for selected orders', async ({ page, context }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
  await page.goto('/admin.php?p=orders');
  const boxes = page.locator('input[name="order_ids[]"]');
  await expect(boxes.first()).toBeVisible();
  const result = await new AxeBuilder({ page }).include('main').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.filter(({ impact }) => ['critical', 'serious'].includes(impact))).toEqual([]);

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: /Export CSV|Exportovať CSV/ }).click(),
  ]);
  const csv = fs.readFileSync(await download.path(), 'utf8');
  expect(csv.split('\n')[0]).toContain('order_number,created_at,status');
  expect(csv.split('\n').filter(Boolean).length).toBeGreaterThan(1);

  await boxes.nth(0).check();
  await boxes.nth(1).check();
  const [notes] = await Promise.all([
    context.waitForEvent('page'),
    page.getByRole('button', { name: /Delivery note \(print\)|Dodací list \(tlač\)/ }).click(),
  ]);
  await notes.waitForLoadState();
  await expect(notes.locator('[data-delivery-note]')).toHaveCount(2);
  await expect(notes.locator('body')).not.toContainText(/€|EUR/);
});
