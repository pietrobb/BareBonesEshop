const { test, expect, state, adminSignIn } = require('./support');
const AxeBuilder = require('@axe-core/playwright').default;

// A15.3: the store switcher offers only the granted stores, switches the admin to the chosen one and refuses a foreign store.
test('admin store switcher offers granted stores only and refuses a foreign store', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
  await page.goto('/admin.php?p=orders');
  const switcher = page.getByLabel(/^(Store|Obchod)$/);
  await expect(switcher.locator('option')).toHaveText(['Slovenský obchod', 'Český obchod']);
  await expect(switcher).toHaveValue('default');
  const result = await new AxeBuilder({ page }).include('nav').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  const violations = result.violations.filter(({ impact }) => ['critical', 'serious'].includes(impact));
  expect(violations, JSON.stringify(violations, null, 2)).toEqual([]);

  await switcher.selectOption('cz');
  await page.getByRole('button', { name: /^(Switch|Prepnúť)$/ }).click();
  await expect(page).toHaveURL(/p=orders/);
  await expect(page.getByLabel(/^(Store|Obchod)$/)).toHaveValue('cz');

  const refused = await page.goto('/admin.php?p=orders&store=hu');
  expect(refused.status()).toBe(403);
});
