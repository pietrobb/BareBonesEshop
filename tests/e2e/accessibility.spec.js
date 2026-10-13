const { test, expect, state } = require('./support');
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
    ['storefront', () => '/'],
    // Seeded paths are read inside the test: the state file is written by global setup after collection.
    ['category', () => state.a11y.category_path],
    ['product', () => state.responsive.product_path],
    ['search results', () => state.a11y.search_path],
    ['cart', () => '/?page=cart'],
    ['account login', () => '/account.php?p=login'],
  ]) {
    test(`${name} has no serious axe violations`, async ({ page }) => {
      await page.goto(path());
      await assertNoSeriousViolations(page);
    });
  }

  // A14.2: search, quantity and remove controls are named; product photos stored with alt:"" still get a text alternative.
  test('search, quantity and remove controls are named and product photos have alt text', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('input[type="search"][name="q"]').first()).toHaveAccessibleName(/\S/);
    await page.goto(state.responsive.product_path);
    await expect(page.locator('.product-detail__hero')).toHaveAttribute('alt', /\S/);
    for (const thumb of await page.locator('.product-detail__thumb').all()) await expect(thumb).toHaveAccessibleName(/\S/);
    await expect(page.locator('.product-detail__actions .qty-input')).toHaveAccessibleName(/\S/);
    await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST'),
      page.locator('.product-detail__actions [data-storefront-action="cart-add"]').click(),
    ]);
    await page.goto('/?page=cart');
    await assertNoSeriousViolations(page);
    const name = (await page.locator('.cart-table .prod-name').first().textContent()).trim();
    await expect(page.locator('.cart-table input[name="quantity"]').first()).toHaveAccessibleName(new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    await expect(page.locator('.cart-table .btn-remove').first()).toHaveAccessibleName(new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  });

  test('search suggestions work as a keyboard combobox and the results page sorts', async ({ page }) => {
    const term = new URL(state.a11y.search_path, 'http://x').searchParams.get('q');
    await page.goto('/');
    const input = page.locator('input[data-search-suggest]').first();
    await expect(input).toHaveAttribute('role', 'combobox');
    await input.fill(term.slice(0, Math.max(3, Math.min(term.length, 4))));
    const listbox = page.locator(`#${await input.getAttribute('aria-controls')}`);
    await expect(listbox.locator('[role="option"]').first()).toBeVisible();
    await expect(input).toHaveAttribute('aria-expanded', 'true');
    await expect(listbox).toHaveAccessibleName(/\S/);
    await input.press('ArrowDown');
    const first = listbox.locator('[role="option"]').first();
    await expect(first).toHaveAttribute('aria-selected', 'true');
    await expect(input).toHaveAttribute('aria-activedescendant', await first.getAttribute('id'));
    await assertNoSeriousViolations(page);
    await input.press('Escape');
    await expect(listbox).toBeHidden();
    await expect(input).toHaveAttribute('aria-expanded', 'false');
    // Enter on a highlighted suggestion opens that product (same-origin link from the API).
    await input.press('Backspace');
    await expect(first).toBeVisible();
    await input.press('ArrowDown');
    const target = await listbox.locator('[role="option"][aria-selected="true"]').getAttribute('data-href');
    expect(new URL(target).origin).toBe(new URL(page.url()).origin);
    await Promise.all([page.waitForURL(target), input.press('Enter')]);

    await page.goto(state.a11y.search_path);
    await assertNoSeriousViolations(page);
    await Promise.all([page.waitForURL(/sort=price_desc/), page.locator('#search-sort').selectOption('price_desc')]);
    await expect(page.locator('#search-sort')).toHaveValue('price_desc');
  });

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
