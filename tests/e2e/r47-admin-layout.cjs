// R47: real admin pages in Chromium at phone, tablet and desktop widths; nothing may widen the page.
/* global fixture, console, process, document */
(async () => {
  const { chromium, expect } = require(fixture.playwright);
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  let failed = 0;
  try {
    await page.goto(fixture.url + '/admin.php?lang=en');
    await page.locator('input[name="admin_username"]').fill('r47-ui');
    await page.locator('input[name="admin_password"]').fill('R47-ui-admin-test-2026');
    await page.locator('button[type="submit"]').click();
    await expect(page.locator('nav')).toContainText('r47-ui');
    await page.goto(fixture.url + '/admin.php?p=stores&lang=en');
    // The long "example data" badge listing every placeholder is what overflowed (review kolo 17).
    await expect(page.locator('[data-store-status="example"]').first()).toBeVisible();
    for (const width of [390, 1024, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      for (const route of ['stores', 'dashboard', 'orders', 'products', 'customers', 'payments', 'shipping']) {
        const response = await page.goto(fixture.url + '/admin.php?p=' + route + '&lang=en');
        expect(response.status()).toBe(200);
        const size = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
        const ok = size.scroll <= size.client;
        if (!ok) failed++;
        console.log((ok ? 'PASS' : 'FAIL') + ` R47 layout ${route} @${width}px: page ${size.scroll}px in ${size.client}px`);
      }
    }
  } finally { await browser.close(); }
  if (failed) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
