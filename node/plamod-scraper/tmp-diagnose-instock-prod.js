/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');
const {
  selectInStockQty,
  clickAddInStockToCart,
} = require('./src/plamod-restock-cart');

async function installFastNavigation(page) {
  await page.route('**/*', (route) => {
    const type = route.request().resourceType();
    if (type === 'image' || type === 'font' || type === 'media') {
      route.abort();
      return;
    }
    route.continue();
  });
}

async function main() {
  const sku = process.argv[2] || '5058815';
  const qty = Number.parseInt(process.argv[3] || '10', 10);
  const baseUrl = 'https://plamod.com';
  const dir = path.resolve(__dirname, '..', '.pw-user-data');

  const context = await chromium.launchPersistentContext(dir, {
    headless: true,
    viewport: { width: 1400, height: 900 },
    locale: 'en-CA',
  });
  const page = context.pages()[0] || (await context.newPage());
  await installFastNavigation(page);
  await ensureLoggedInQuick(page, baseUrl, context);
  await page.goto(`${baseUrl}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(500);

  const block = page.locator('div, section').filter({ hasText: /^IN-STOCK/i }).first();
  const text = await block.evaluate((el) => (el.textContent || '').replace(/\s+/g, ' ').trim());
  console.log('block', text.slice(0, 250));
  console.log('buttons', await block.locator('button').evaluateAll((btns) => btns.map((b, i) => ({
    i,
    text: (b.textContent || '').trim(),
    disabled: b.disabled,
    role: b.getAttribute('role'),
  }))));

  const pick = await selectInStockQty(page, qty);
  console.log('pick', pick);
  const clicked = await clickAddInStockToCart(page);
  console.log('clicked', clicked);

  await context.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
