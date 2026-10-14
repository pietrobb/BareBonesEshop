const { test, expect, state, adminSignIn } = require('./support');
const AxeBuilder = require('@axe-core/playwright').default;

// A15.1: dashboard and the sales reports page render per-currency figures and stay accessible.
test('admin dashboard and sales reports render per currency without serious axe violations', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  await adminSignIn(page);
  for (const path of ['/admin.php?p=dashboard', '/admin.php?p=reports', '/admin.php?p=reports&days=90']) {
    await page.goto(path);
    await expect(page.locator('main [data-revenue-today], main #report-days').first()).toBeVisible();
    const result = await new AxeBuilder({ page }).include('main').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    const violations = result.violations.filter(({ impact }) => ['critical', 'serious'].includes(impact));
    expect(violations, `${path}: ${JSON.stringify(violations, null, 2)}`).toEqual([]);
  }
  await page.goto('/admin.php?p=reports');
  await page.getByLabel(/Period|Obdobie/).selectOption('7');
  await page.getByRole('button', { name: /Show|Zobraziť/ }).click();
  await expect(page).toHaveURL(/days=7/);
  await expect(page.getByRole('navigation')).toContainText(/Reports|Reporty/);
});
