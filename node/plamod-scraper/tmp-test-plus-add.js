/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');
const envPath = path.resolve(__dirname, '..', '..', '.env');
if (fs.existsSync(envPath)) {
  for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) continue;
    const idx = trimmed.indexOf('=');
    process.env[trimmed.slice(0, idx).trim()] = trimmed.slice(idx + 1).trim().replace(/^['"]|['"]$/g, '');
  }
}
const { chromium } = require('playwright');
const { ensureLoggedInQuick } = require('./src/plamod');
const { selectInStockQty, snapshotRetailerCart } = require('./src/plamod-restock-cart');

async function cartQty(page, base, sku) {
  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  const cart = await snapshotRetailerCart(page, { refresh: true, expectedSkus: [sku], maxAttempts: 4 });
  return cart[sku] ?? 0;
}

async function main() {
  const sku = '5058815';
  const base = 'https://plamod.com';
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());

  const parentDump = await page.evaluate(() => {
    const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
    const block = [...document.querySelectorAll('div, section')].find((el) => {
      const t = norm(el.textContent || '');
      return /^IN[- ]?STOCK/i.test(t) && el.querySelector('button[role="combobox"]') && t.length <= 600;
    });
    if (!block) return null;
    let parent = block.parentElement;
    for (let up = 0; up < 4 && parent; up += 1) {
      parent = parent.parentElement;
    }
    return norm(parent?.innerHTML || '').slice(0, 4000);
  });

  await ensureLoggedInQuick(page, base, context);
  console.log('before', await cartQty(page, base, sku));

  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await selectInStockQty(page, 10);

  const block = page.locator('[data-ovs-instock-block="1"]');
  const btn6 = block.locator('button:not([disabled])').nth(0); // first enabled in block = plus left
  const btn9 = block.locator('button:not([disabled])').last(); // last = plus right

  console.log('click left plus');
  await btn6.click();
  await page.waitForTimeout(1000);
  console.log('after left plus', await cartQty(page, base, sku));

  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await selectInStockQty(page, 10);
  console.log('click right plus');
  await btn9.click();
  await page.waitForTimeout(1000);
  console.log('after right plus', await cartQty(page, base, sku));

  console.log('parent html snippet', parentDump?.slice(0, 1500));
  await context.close();
}

main();
