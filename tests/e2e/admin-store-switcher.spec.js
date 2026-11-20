const { test, expect, state, adminSignIn } = require('./support');
const AxeBuilder = require('@axe-core/playwright').default;

// A15.3: the store switcher offers only the granted stores, switches the admin to the chosen one and refuses a foreign store.
test('admin store switcher offers granted stores only and refuses a foreign store', async ({ page }) => {
  test.skip(!state.manager, 'Run tests/e2e/seed.php first');
  await adminSignIn(page, state.manager);
  await page.goto('/admin.php?p=orders');
  const switcher = page.getByLabel(/^(Store|Obchod)$/);
  // R50: each option names the store with its code and where it is served ("Český obchod (cz · /cs)").
  await expect(switcher.locator('option')).toHaveText([/^Slovenský obchod \(default\b/, /^Český obchod \(cz · \/cs\)$/]);
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

// R9: the owner manages every configured store without per-store grants.
test('admin store switcher offers the owner every store', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
  await page.goto('/admin.php?p=orders');
  await expect(page.getByLabel(/^(Store|Obchod)$/).locator('option')).toHaveText([
    /^Slovenský obchod \(default\b/, /^Český obchod \(cz\b/, /^Sklep polski \(pl\b/, /^Magyar üzlet \(hu\b/, /^Deutscher Shop \(de\b/, /^US Store \(us\b/,
  ]);
  const opened = await page.goto('/admin.php?p=orders&store=hu');
  expect(opened.status()).toBe(200);
});
