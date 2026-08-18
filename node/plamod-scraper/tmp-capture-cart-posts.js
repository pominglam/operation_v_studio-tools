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
const { markInStockBlock, getInStockBlock, selectInStockQty, snapshotRetailerCart } = require('./src/plamod-restock-cart');

async function runAction(page, base, sku, label, action) {
  const posts = [];
  const handler = (req) => {
    if (req.method() === 'POST' && req.url().includes('/retailer/cart')) {
      posts.push({ url: req.url(), body: req.postData() });
    }
  };
  page.on('request', handler);
  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await markInStockBlock(page);
  await action(page);
  await page.waitForTimeout(1200);
  page.off('request', handler);
  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  const cart = await snapshotRetailerCart(page, { refresh: true, expectedSkus: [sku], maxAttempts: 4 });
  console.log('\n===', label, '===');
  console.log('posts', JSON.stringify(posts, null, 2));
  console.log('cartQty', cart[sku] ?? 0);
}

async function main() {
  const sku = '5058815';
  const base = 'https://plamod.com';
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await ensureLoggedInQuick(page, base, context);

  await runAction(page, base, sku, 'combo only 10', async (p) => {
    await selectInStockQty(p, 10);
  });

  await runAction(page, base, sku, 'combo 10 + last plus', async (p) => {
    await selectInStockQty(p, 10);
    const block = getInStockBlock(p);
    await block.locator('button:not([disabled])').last().click();
  });

  await runAction(page, base, sku, 'combo 10 + row cart icon', async (p) => {
    await selectInStockQty(p, 10);
    const cartPath = 'M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12';
    const cartBtn = p.locator(`button:has(svg path[d="${cartPath}"])`).last();
    if ((await cartBtn.count()) > 0) {
      await cartBtn.click();
    } else {
      console.log('no cart icon button in row');
    }
  });

  await runAction(page, base, sku, 'click IN-STOCK label area sibling', async (p) => {
    await selectInStockQty(p, 10);
    await p.locator('text=IN-STOCK').first().click();
  });

  await context.close();
}

main();
