// Live isolated PHP account server; fixture is prepended by AuditRound37AccountRegression.php.
/* global fixture, console, process, document, innerWidth, getComputedStyle */
(async () => {
  const { chromium, expect } = require(fixture.playwright);
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, extraHTTPHeaders: { 'X-R37-Browser': '1' } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    await page.goto(fixture.url + '/account.php?p=register&lang=en');
    const rejectCookies = page.getByRole('button', { name: /reject all|necessary only/i });
    if (await rejectCookies.count()) await rejectCookies.first().click();
    await page.locator('#account-register-name').fill('Buyer\u007f');
    await page.locator('#account-register-email').fill('browser-invalid@example.test');
    await page.locator('#account-register-password').fill('R37-password');
    await page.waitForTimeout(4100); // The real anti-bot minimum age is four seconds.
    const response = page.waitForResponse(r => r.request().isNavigationRequest() && r.request().method() === 'POST');
    await page.locator('#account-register-name').locator('xpath=ancestor::form').locator('button.btn').click();
    expect((await response).status()).toBe(400);
    await expect(page.locator('#account-register-name-error')).toBeVisible();
    await expect(page.locator('#account-register-name')).toHaveAttribute('aria-describedby', 'account-register-name-error');
    await page.goto(fixture.url + '/account.php?p=login&lang=en');
    await page.locator('#account-login-email').fill('owner@example.test');
    await page.locator('#account-login-password').fill('R37-password');
    await page.locator('#account-login-email').locator('xpath=ancestor::form').locator('button.btn').click();
    const heading = page.locator('.account-page>h1');
    await expect(heading).toContainText('ž'.repeat(191));
    await page.evaluate(() => document.fonts.ready);
    const measure = () => page.evaluate(() => {
      const h = document.querySelector('.account-page>h1');
      return { viewport: innerWidth, document: document.documentElement.scrollWidth,
        heading: h.scrollWidth, width: h.clientWidth, wrap: getComputedStyle(h).overflowWrap,
        text: h.textContent, styles: [...document.styleSheets].map(s => s.href) };
    });
    const fixed = await measure();
    expect(fixed.styles.some(url => url && url.includes('storefront.css'))).toBe(true);
    expect(fixed.wrap).toBe('anywhere');
    expect(fixed.document).toBeLessThanOrEqual(390);
    expect(fixed.heading).toBeLessThanOrEqual(fixed.width + 1);
    // Reproduce the original defect on the same full page without changing persisted customer data.
    await heading.evaluate(h => { h.style.overflowWrap = 'normal'; });
    const before = await measure();
    expect(before.heading).toBeGreaterThan(before.width);
    await heading.evaluate(h => { h.style.removeProperty('overflow-wrap'); });
    expect((await measure()).document).toBeLessThanOrEqual(390);
    expect(errors).toEqual([]);
    console.log('PASS live Chromium account: invalid registration field error; actual owner login; full 191-character greeting at 390px; red/fixed dimensions ' + JSON.stringify({ before, fixed }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
