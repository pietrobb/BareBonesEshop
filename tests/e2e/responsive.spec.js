const { test, expect, state } = require('./support');

// A14.1 / usability 5: cart, checkout and product detail must not scroll sideways on a phone, keep their
// two-column layout on a desktop, and subcategories must open by touch and keyboard, not only on hover.
// The product (tests/e2e/seed.php) has two 1920px photos.

async function noHorizontalOverflow(page) {
  return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
}

async function fillCart(page) {
  await page.goto('/');
  await page.locator('[data-storefront-action="cart-add"]').first().click();
  await expect(page.locator('.cart-badge')).toBeVisible();
}

test.describe('responsive cart, checkout and product detail', () => {
  for (const [label, width] of [['phone 360px', 360], ['desktop 1280px', 1280]]) {
    test(`${label}: no sideways scroll and a usable column layout`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await fillCart(page);
      for (const [name, path, main, aside] of [
        ['product', state.responsive.product_path, '.product-detail__hero', '.product-detail__info h1'],
        ['cart', '/?page=cart', '.cart-table', '.cart-summary'],
        ['checkout', '/?page=checkout', '#checkout-name', '.cart-summary'],
      ]) {
        await page.goto(path);
        expect(await noHorizontalOverflow(page), `${name} at ${width}px scrolls sideways`).toBeTruthy();
        const a = await page.locator(main).first().boundingBox();
        const b = await page.locator(aside).first().boundingBox();
        expect(a && b, `${name}: ${main} and ${aside} are rendered`).toBeTruthy();
        if (width >= 1024) {
          expect(b.x, `${name}: ${aside} sits beside ${main} on a desktop`).toBeGreaterThan(a.x + 100);
        } else {
          expect(b.y, `${name}: ${aside} stacks below ${main} on a phone`).toBeGreaterThanOrEqual(a.y + a.height - 1);
          expect(b.x + b.width, `${name}: ${aside} fits the screen`).toBeLessThanOrEqual(width + 1);
        }
      }
    });
  }
});

// R52 (idea 50): the home page's Lighthouse CLS reached 0.113 in CI once the admin-catalog spec had pointed a
// listed product at missing photos (/images/e2e-a.jpg): a broken card image dropped its square to the height of
// its alt text and pulled the cards below it up. And on a phone the cookie banner grew when the script showed its
// "Settings" button after the first paint.
test.describe('home page layout does not shift', () => {
  test('a product photo that fails to load keeps its square', async ({ page }) => {
    await page.route(/\/(assets\/img|images|media)\//, route => route.fulfill({ status: 404, body: '' }));
    await page.goto('/');
    const image = page.locator('#home-latest .card-img').first();
    await image.scrollIntoViewIfNeeded();
    await image.evaluate(el => el.complete || new Promise(done => el.addEventListener('error', done, { once: true })));
    expect(await image.evaluate(el => el.naturalWidth), 'the photo really failed to load').toBe(0);
    const box = await image.boundingBox();
    expect(Math.abs(box.height - box.width), `the broken card image is ${box.width}×${box.height}, not square`).toBeLessThanOrEqual(1);
  });

  test('the cookie banner on a phone keeps its height when the script shows its Settings button', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    // The storefront script is held back until the banner has been painted without it, as on a slow connection.
    let release;
    const held = new Promise(done => { release = done; });
    await page.route(/storefront\.js/, async route => { await held; await route.continue(); });
    await page.goto('/', { waitUntil: 'commit' });
    const banner = page.locator('#cc-banner');
    await expect(banner).toBeVisible();
    await expect(page.locator('#cc-prefs-toggle')).toBeHidden();
    const before = await banner.boundingBox();
    release();
    await expect(page.locator('#cc-prefs-toggle')).toBeVisible();
    const after = await banner.boundingBox();
    expect([after.y, after.height], 'the banner does not grow or move when the Settings button appears').toEqual([before.y, before.height]);
  });
});

test.describe('category navigation without hover', () => {
  test('subcategories open by keyboard and close with Escape', async ({ page, isMobile }) => {
    test.skip(isMobile, 'iOS Safari does not Tab to links');
    await page.goto('/');
    const toggle = page.locator('.header-nav .nav-item > .nav-sub > summary').first();
    await expect(toggle).toHaveAccessibleName(/\S/);
    let reached = false;
    for (let i = 0; i < 40 && !reached; i++) {
      await page.keyboard.press('Tab');
      reached = await toggle.evaluate(el => el === document.activeElement);
    }
    expect(reached, 'the subcategory toggle is in the Tab order').toBeTruthy();
    const firstChild = page.locator('.header-nav .nav-item').first().locator('.nav-dropdown a').first();
    await expect(firstChild).toBeHidden();
    await page.keyboard.press('Enter');
    await expect(firstChild).toBeVisible();
    await page.keyboard.press('Tab');
    await expect(firstChild).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(firstChild).toBeHidden();
    await expect(toggle).toBeFocused();
  });

  test('subcategories open by tap and the link works on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/');
    const item = page.locator('.header-nav .nav-item').first();
    const firstChild = item.locator('.nav-dropdown a').first();
    await expect(firstChild).toBeHidden();
    await item.locator('.nav-sub > summary').click();
    await expect(firstChild).toBeVisible();
    expect(await noHorizontalOverflow(page), 'the open menu fits a 360px screen').toBeTruthy();
    const href = await firstChild.getAttribute('href');
    await firstChild.click();
    await expect(page).toHaveURL(new RegExp(href.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$'));
  });

  test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });
    test('subcategories still open from the toggle', async ({ page }) => {
      await page.goto('/');
      const item = page.locator('.header-nav .nav-item').first();
      const firstChild = item.locator('.nav-dropdown a').first();
      await expect(firstChild).toBeHidden();
      await item.locator('.nav-sub > summary').click();
      await expect(firstChild).toBeVisible();
    });
  });
});
