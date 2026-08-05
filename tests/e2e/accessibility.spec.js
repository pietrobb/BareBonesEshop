const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

async function assertNoSeriousViolations(page) {
  const result = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  const violations = result.violations.filter(({ impact }) => ['critical', 'serious'].includes(impact));
  expect(violations, JSON.stringify(violations, null, 2)).toEqual([]);
}

test.describe('rendered WCAG 2.2 AA qualification', () => {
  for (const [name, path] of [
    ['storefront', '/'],
    ['cart', '/?page=cart'],
    ['account login', '/account.php?p=login'],
  ]) {
    test(`${name} has no serious axe violations`, async ({ page }) => {
      await page.goto(path);
      await assertNoSeriousViolations(page);
    });
  }

  test('checkout fields have programmatic names and no serious axe violations', async ({ page }) => {
    await page.goto('/');
    await page.locator('[data-storefront-action="cart-add"]').first().click();
    await page.goto('/?page=checkout');
    await assertNoSeriousViolations(page);
    for (const id of ['name', 'email', 'phone', 'street', 'city', 'zip', 'country', 'notes']) {
      const field = page.locator(`#checkout-${id}`);
      await expect(field).toBeVisible();
      await expect(field).toHaveAccessibleName(/\S/);
    }
  });

  test('320px storefront has no horizontal overflow and loads theme assets', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 });
    const responses = [];
    page.on('response', response => {
      if (response.url().includes('/themes/default/assets/')) responses.push(response.status());
    });
    await page.goto('/');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBeTruthy();
    expect(responses.length).toBeGreaterThanOrEqual(3);
    expect(responses.every(status => status === 200)).toBeTruthy();
  });

  test('keyboard bypass, focus visibility, and 200% zoom remain usable', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    const focused = page.locator(':focus');
    await expect(focused).toHaveAttribute('href', '#main-content');
    await focused.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();

    await page.evaluate(() => { document.body.style.zoom = '2'; });
    await expect(page.locator('#main-content')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 2)).toBeTruthy();
  });
});
