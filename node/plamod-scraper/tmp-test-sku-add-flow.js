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
const { markInStockBlock, getInStockBlock, selectInStockQty, clickAddInStockToCart, snapshotRetailerCart } = require('./src/plamod-restock-cart');

async function testSku(sku) {
  const base = 'https://plamod.com';
  const context = await chromium.launchPersistentContext(path.resolve(__dirname, '.pw-user-data'), {
    headless: true,
    viewport: { width: 1400, height: 900 },
  });
  const page = context.pages()[0] || (await context.newPage());
  await page.route('**/*', (route) => {
    const type = route.request().resourceType();
    if (['image', 'font', 'media'].includes(type)) route.abort();
    else route.continue();
  });
  const posts = [];
  page.on('request', (req) => {
    if (req.method() === 'POST') {
      posts.push({ url: req.url(), body: (req.postData() || '').slice(0, 800) });
    }
  });

  await ensureLoggedInQuick(page, base, context);
  await page.goto(`${base}/retailer/products/${sku}`, { waitUntil: 'domcontentloaded' });
  await markInStockBlock(page);
  const pick = await selectInStockQty(page, 1);
  const clicked = await clickAddInStockToCart(page);
  await page.waitForTimeout(1500);
  await page.goto(`${base}/retailer/cart`, { waitUntil: 'domcontentloaded' });
  const cart = await snapshotRetailerCart(page, { refresh: true, expectedSkus: [sku], maxAttempts: 4 });

  console.log(JSON.stringify({ sku, pick, clicked, cartQty: cart[sku] ?? 0, posts }, null, 2));
  await context.close();
}

testSku(process.argv[2] || '5068381').catch((e) => {
  console.error(e);
  process.exit(1);
});
