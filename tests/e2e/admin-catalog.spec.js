const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { state, adminSignIn } = require('./support');

// The admin catalog forms must open with the stored values and save through admin.php,
// and an edited "Export CSV" file must import back without touching what it does not change.
test.describe.configure({ mode: 'serial' });

function db(...args) {
  return JSON.parse(execFileSync('php', ['tests/e2e/admin-state.php', ...args], { cwd: process.cwd(), encoding: 'utf8', env: process.env }));
}

const kept = ['name', 'slug', 'product_type', 'category_id', 'price', 'compare_price', 'status', 'summary', 'description',
  'images', 'tags', 'attrs', 'translations', 'weight_g', 'stock_low_threshold', 'stock_critical_threshold'];
const pick = (row, columns = kept) => Object.fromEntries(columns.map(c => [c, row[c] === null ? null : String(row[c])]));

let sku = '';

test('admin product form opens filled and saves stock and alert thresholds', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  const before = db('product');
  sku = before.sku;
  await adminSignIn(page);
  await page.goto(`/admin.php?p=product&id=${before.row.id}`);

  const form = page.locator('#form-admin-product');
  await expect(form).toHaveAttribute('action', new RegExp(`admin\\.php\\?p=product&action=save&id=${before.row.id}$`));
  await expect(form.locator('#field-sku')).toHaveValue(before.sku);
  await expect(form.locator('#field-name')).toHaveValue(before.row.name);
  await expect(form.locator('#field-price')).toHaveValue(String(before.row.price));
  await expect(form.locator('#field-stock')).toHaveValue(String(before.row.stock));
  await expect(form.locator('#field-status')).toHaveValue(before.row.status);
  await expect(form.locator('#field-category_id')).toHaveValue(String(before.row.category_id ?? ''));

  // Saving the untouched form keeps the product exactly as stored.
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.flash-ok')).toHaveText('Bez zmien.');
  const unchanged = db('product', sku);
  expect(pick(unchanged.row, [...kept, 'stock', 'updated_at'])).toEqual(pick(before.row, [...kept, 'stock', 'updated_at']));
  expect(unchanged.movements.length).toBe(before.movements.length);

  // BBF validates the save on the server: a status outside the list is refused and nothing is stored.
  await form.locator('#field-name').fill('E2E neuložený názov');
  await form.locator('#field-status').evaluate(select => {
    select.add(new Option('forged', 'forged'));
    select.value = 'forged';
  });
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.flash-err')).toContainText('Produkt nebol uložený');
  await expect(form.locator('#field-status-error')).not.toBeEmpty();
  await expect(form.locator('#field-status')).toHaveValue(before.row.status);
  await expect(form.locator('#field-name')).toHaveValue('E2E neuložený názov');
  expect(pick(db('product', sku).row, [...kept, 'stock', 'updated_at'])).toEqual(pick(before.row, [...kept, 'stock', 'updated_at']));
  await form.locator('#field-name').fill(before.row.name);

  const stock = Number(before.row.stock) + 3;
  await form.locator('#field-stock').fill(String(stock));
  await form.locator('#field-stock_low_threshold').fill('7');
  await form.locator('#field-stock_critical_threshold').fill('2');
  await form.locator('#field-description').fill('<p>E2E popis produktu</p>');
  await form.locator('#field-images').fill('/images/e2e-a.jpg\n/images/e2e-b.jpg');
  await form.locator('#field-tags').fill('e2e-a, e2e-b');
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.flash-ok')).toHaveText('Produkt aktualizovaný.');

  const after = db('product', sku);
  expect(Number(after.row.stock)).toBe(stock);
  expect(after.available).toBe(stock);
  expect(Number(after.row.stock_low_threshold)).toBe(7);
  expect(Number(after.row.stock_critical_threshold)).toBe(2);
  expect(after.row.description).toBe('<p>E2E popis produktu</p>');
  expect(JSON.parse(after.row.images)).toEqual(['/images/e2e-a.jpg', '/images/e2e-b.jpg']);
  expect(JSON.parse(after.row.tags)).toEqual(['e2e-a', 'e2e-b']);
  expect(pick(after.row, ['price', 'category_id', 'name', 'status'])).toEqual(pick(before.row, ['price', 'category_id', 'name', 'status']));
  expect(after.movements.slice(before.movements.length).map(m => ({ ...m, quantity_delta: Number(m.quantity_delta) })))
    .toEqual([{ movement_type: 'manual_adjustment', quantity_delta: 3, reference_type: 'admin_product' }]);

  // The reopened form shows what was saved.
  await page.goto(`/admin.php?p=product&id=${before.row.id}`);
  await expect(form.locator('#field-stock')).toHaveValue(String(stock));
  await expect(form.locator('#field-stock_low_threshold')).toHaveValue('7');
  await expect(form.locator('#field-stock_critical_threshold')).toHaveValue('2');
  await expect(form.locator('#field-tags')).toHaveValue('e2e-a, e2e-b');
});

test('admin Export CSV imports back through the admin Import form without damage', async ({ page }) => {
  test.skip(!state.admin || !sku, 'Needs the seeded admin and the product test');
  const before = db('catalog');
  const product = before.products[sku];
  await adminSignIn(page);

  await page.goto('/admin.php?p=products');
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.locator('#bulk-form button[formaction*="action=export"]').click(),
  ]);
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'bb-e2e-import-'));
  const exported = path.join(dir, 'export.csv');
  const edited = path.join(dir, 'products.csv');
  await download.saveAs(exported);
  const stock = Number(product.stock) + 5;
  expect(db('edit-csv', exported, edited, sku, 'stock', String(stock)).edited).toBe(1);

  await page.goto('/admin.php?p=import');
  const form = page.locator('#form-admin-import');
  await expect(form).toHaveAttribute('enctype', 'multipart/form-data');
  await form.locator('#field-csv_file').setInputFiles(edited);
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.flash-ok')).toHaveText(`Import úspešný: ${Object.keys(before.products).length} produktov.`);

  const after = db('catalog');
  expect(Object.keys(after.products).sort()).toEqual(Object.keys(before.products).sort());
  for (const [key, row] of Object.entries(before.products)) {
    expect(pick(after.products[key]), `product ${key}`).toEqual(pick(row));
    if (key !== sku) expect(String(after.products[key].stock), `stock of ${key}`).toBe(String(row.stock));
  }
  expect(Number(after.products[sku].stock)).toBe(stock);
  expect(db('product', sku).available).toBe(stock);
  expect(after.movements).toBe(before.movements + 1);
  fs.rmSync(dir, { recursive: true, force: true });
});

test('admin CMS page form saves through admin.php and reopens filled', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  const slug = `e2e-page-${Date.now()}`;
  await adminSignIn(page);
  await page.goto('/admin.php?p=page_edit');
  const form = page.locator('#form-admin-page');
  await form.locator('#field-slug').fill(slug);
  await form.locator('#field-title').fill('E2E stránka');
  await form.locator('#field-body').fill('<p>Obsah stránky</p>');
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.flash-ok')).toHaveText('Stránka vytvorená.');
  const created = db('page', slug).row;
  expect(created).toMatchObject({ title: 'E2E stránka', body: '<p>Obsah stránky</p>', status: 'active' });

  await page.goto(`/admin.php?p=page_edit&id=${created.id}`);
  await expect(form.locator('#field-slug')).toHaveValue(slug);
  await expect(form.locator('#field-title')).toHaveValue('E2E stránka');
  await expect(form.locator('#field-body')).toHaveValue('<p>Obsah stránky</p>');
  await form.locator('#field-title').fill('E2E stránka 2');
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.flash-ok')).toHaveText('Stránka aktualizovaná.');
  const updated = db('page', slug).row;
  expect(updated).toMatchObject({ id: created.id, title: 'E2E stránka 2', body: '<p>Obsah stránky</p>', layout: created.layout });
});

test('admin attribute form saves options and categories and reopens filled', async ({ page }) => {
  test.skip(!state.admin, 'Run tests/e2e/seed.php first');
  const slug = `e2e-attr-${Date.now()}`;
  await adminSignIn(page);
  await page.goto('/admin.php?p=attribute');
  const form = page.locator('#form-admin-attribute');
  await form.locator('#field-name').fill('E2E farba');
  await form.locator('#field-slug').fill(slug);
  await form.locator('#field-type').selectOption('enum');
  await form.locator('[data-action="add-option"]').click();
  await form.locator('input[name="options[]"]').last().fill('modrá');
  const category = form.locator('.cat-checkbox').first();
  await category.check();
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.flash-ok')).toHaveText('Atribút uložený.');
  const saved = db('attribute', slug);
  expect(saved.row).toMatchObject({ name: 'E2E farba', type: 'enum' });
  expect(saved.options.map(o => o.value)).toEqual(['modrá']);
  expect(saved.categories).toEqual([Number(await category.getAttribute('value'))]);

  await page.goto(`/admin.php?p=attribute&id=${saved.row.id}`);
  await expect(form.locator('#field-name')).toHaveValue('E2E farba');
  await expect(form.locator('#field-slug')).toHaveValue(slug);
  await expect(form.locator('#field-type')).toHaveValue('enum');
  await form.locator('button[type="submit"]').click();
  await expect(page.locator('.flash-ok')).toHaveText('Atribút uložený.');
  const again = db('attribute', slug);
  expect(again.options).toEqual(saved.options);
  expect(again.categories).toEqual(saved.categories);
});
