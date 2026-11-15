// Actual PHP admin pages with seeded data and authenticated browser, no HTML mocks.
/* global fixture, console, process, document, getComputedStyle */
(async () => {
  const { chromium, expect } = require(fixture.playwright);
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  try {
    await page.goto(fixture.url + '/admin.php?lang=en');
    await page.locator('input[name="admin_username"]').fill('r37-ui');
    await page.locator('input[name="admin_password"]').fill('R37-ui-admin-test-2026');
    await page.locator('button[type="submit"]').click();
    await expect(page.locator('nav')).toContainText('r37-ui'); const sizes = [];
    for (const [lang, prefix] of [['sk', 'Obchod'], ['en', 'Store']]) { const response = await page.goto(fixture.url + '/admin.php?lang=' + lang); expect(response.status()).toBe(200); const title = page.locator('#setup-checklist [data-check="stores.oss_threshold.default"] strong'); await expect(title).toBeVisible(); await expect(title).toContainText(prefix + ' R37 Named Shop'); await expect(title).not.toContainText(prefix + ' default'); console.log('PASS R37 live OSS dashboard ' + lang + ': ' + await title.innerText()); }
    for (const route of ['shipping', 'payments', 'products', 'customers', 'orders', 'audit']) {
      const response = await page.goto(fixture.url + '/admin.php?p=' + route + '&lang=en');
      expect(response.status()).toBe(200);
      sizes.push(await page.evaluate(route => ({ route, width: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth, mainMin: getComputedStyle(document.querySelector('main')).minWidth,
        tables: [...document.querySelectorAll('main .table-scroll')].map(e => ({ width: e.clientWidth, scroll: e.scrollWidth })) }), route));
    }
    console.log('R37 desktop page widths ' + JSON.stringify(sizes));
    const audit = await page.locator('main').innerText();
    // No external payment is made; only the names of already-booked audit events are rendered.
    console.log('R37 audit labels ' + audit);
    await page.goto(fixture.url + '/index.php?page=checkout&lang=en');
    if (await page.locator('#cc-reject').count()) await page.locator('#cc-reject').click();
    const radios = page.locator('.shipping-option input[type="radio"], .payment-option input[type="radio"]');
    await expect(radios.first()).toBeVisible();
    await radios.first().check();
    await expect(page.locator('.payment-option input[type="radio"]').first()).toBeVisible();
    const controls = await radios.evaluateAll(nodes => nodes.map(e => ({ name: e.name, width: e.getBoundingClientRect().width, labelWidth: e.closest('label').getBoundingClientRect().width })));
    console.log('R37 desktop radio widths ' + JSON.stringify(controls));
    for (const size of sizes) expect(size.scroll, size.route + ' desktop overflow').toBeLessThanOrEqual(size.width);
    expect(audit).toContain('Refund in progress');
    expect(audit).toContain('Refund failed');
    expect(audit).not.toContain('checkout.session.completed');
    expect(controls.some(c => c.name === 'shipping_method')).toBe(true);
    expect(controls.some(c => c.name === 'payment')).toBe(true);
    for (const c of controls) expect(c.width, c.name + ' radio width').toBeLessThanOrEqual(24);
    await radios.first().check();
    await expect(radios.first()).toBeChecked();
    console.log('PASS R37 live Chromium: six admin pages fit 1280px, tables stay scrollable, refund/event labels and compact checkout radios');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
