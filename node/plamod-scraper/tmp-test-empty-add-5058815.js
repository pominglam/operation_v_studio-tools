/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');

const envPath = path.resolve(__dirname, '..', '..', '.env');
if (fs.existsSync(envPath)) {
  for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) continue;
    const idx = trimmed.indexOf('=');
    const key = trimmed.slice(0, idx).trim();
    let value = trimmed.slice(idx + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (!process.env[key]) process.env[key] = value;
  }
}

const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');
const {
  scrapeRetailerCartQuantities,
  selectInStockQty,
  clickAddInStockToCart,
} = require('./src/plamod-restock-cart');

async function main() {
  const sku = '5058815';
  const base = 'https://plamod.com';
  const browser = await chromium.launch({ headless: true });
  const page = await (await browser.newContext()).newPage();
  await page.route('**/*', (route) => {
    const type = route.request().resourceType();
    if (type === 'image' || type === 'font' || type === 'media') route.abort();
    else route.continue();
  });
  await ensureLoggedInQuick(page, base, page.context());

  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  let cart = await scrapeRetailerCartQuantities(page);
  console.log('cartBeforeTest', cart[sku] ?? 0);

  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(500);

  const block = page.locator('div, section').filter({ hasText: /^IN-STOCK/i }).first();
  console.log(
    'blockButtons',
    await block.locator('button').evaluateAll((btns) =>
      btns.map((b, i) => ({ i, text: (b.textContent || '').trim(), disabled: b.disabled, role: b.getAttribute('role') })),
    ),
  );

  const pick = await selectInStockQty(page, 10);
  console.log('pick', pick);

  console.log(
    'blockButtonsAfterPick',
    await block.locator('button').evaluateAll((btns) =>
      btns.map((b, i) => ({ i, text: (b.textContent || '').trim(), disabled: b.disabled, role: b.getAttribute('role') })),
    ),
  );

  const clicked = await clickAddInStockToCart(page);
  console.log('clicked', clicked);

  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  cart = await scrapeRetailerCartQuantities(page);
  console.log('cartAfterTest', cart[sku] ?? 0);

  await browser.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
