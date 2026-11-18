// R47: real admin pages in Chromium at phone, tablet and desktop widths; nothing may widen the page.
/* global fixture, console, process, document, getComputedStyle */
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
    // R47 a11y (review kolo 17, low 3): skip link, keyboard focus ring, links in tables, translated nav badge.
    const check = (ok, name) => { if (!ok) failed++; console.log((ok ? 'PASS' : 'FAIL') + ' R47 a11y: ' + name); };
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(fixture.url + '/admin.php?p=orders&lang=sk');
    await page.keyboard.press('Tab');
    const skip = await page.evaluate(() => {
      const a = document.activeElement, r = a.getBoundingClientRect();
      return { cls: a.className, href: a.getAttribute('href'), text: a.textContent.trim(), visible: r.left >= 0 && r.top >= 0 && r.width > 0 };
    });
    check(skip.cls === 'skip-link' && skip.href === '#main' && skip.visible && skip.text === 'Preskočiť na hlavný obsah', 'the first Tab shows a skip link to the content ' + JSON.stringify(skip));
    await page.keyboard.press('Enter');
    check(await page.evaluate(() => document.activeElement === document.querySelector('main')), 'the skip link moves focus to <main>');
    const badge = await page.evaluate(() => ({ text: (document.querySelector('nav h2 .nav-badge') || {}).textContent || '', after: getComputedStyle(document.querySelector('nav h2'), '::after').content }));
    check(badge.text === 'Správa' && ['none', 'normal'].includes(badge.after), 'the nav badge is translated text, not CSS content ' + JSON.stringify(badge));
    const link = await page.evaluate(() => {
      const a = document.querySelector('main td a:not(.btn)');
      if (!a) return null;
      const s = getComputedStyle(a);
      return { line: s.textDecorationLine, color: s.color, cell: getComputedStyle(a.closest('td')).color };
    });
    check(link !== null && link.line.includes('underline') && link.color !== link.cell, 'a link in a table is underlined and coloured unlike the cell text ' + JSON.stringify(link));
    const input = page.locator('main input:not([type=hidden]):not([type=checkbox]):not([type=radio])').first();
    await input.focus();
    const ring = await input.evaluate(el => { const s = getComputedStyle(el); return { style: s.outlineStyle, width: parseFloat(s.outlineWidth) }; });
    check(ring.style !== 'none' && ring.width >= 2, 'a focused input has a visible outline ' + JSON.stringify(ring));
  } finally { await browser.close(); }
  if (failed) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
